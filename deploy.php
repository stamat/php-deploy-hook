<?php
/**
 * deploy.php — a GitHub push webhook that updates a checkout and publishes files
 * from it. One file, no dependencies, and nothing from the request reaches a shell.
 *
 * Settings live in deploy.config.php beside this file, so a new deploy.php can be
 * copied over the old one without losing them.
 *
 * https://github.com/stamat/php-deploy-hook — MIT
 */

const DEPLOY_VERSION = '0.1.0';

if (is_file(__DIR__ . '/deploy.config.php')) require __DIR__ . '/deploy.config.php';

defined('DEPLOY_SECRET')  || define('DEPLOY_SECRET', '');            // the webhook secret
defined('DEPLOY_REPO')    || define('DEPLOY_REPO', __DIR__ . '/..'); // the checkout, outside the web root
defined('DEPLOY_BRANCH')  || define('DEPLOY_BRANCH', 'main');
defined('DEPLOY_PUBLISH') || define('DEPLOY_PUBLISH', []);           // ['file/in/repo' => '/absolute/destination']
defined('DEPLOY_LOG')     || define('DEPLOY_LOG', '');               // a path, or '' for error_log

// ---- the command line -------------------------------------------------------

if (PHP_SAPI === 'cli') {
    // Printed rather than written, so it cannot overwrite a config that is already
    // there — and a config that came out of here parses, which is more than can be
    // said for one typed at midnight.
    // The secret is generated because a machine picks a better one. Every path is
    // left as a placeholder on purpose: guessing them produces a config that
    // parses and deploys the wrong thing, which is worse than an obvious blank.
    echo "<?php\n"
       . "define('DEPLOY_SECRET', '" . bin2hex(random_bytes(32)) . "');\n"
       . "define('DEPLOY_REPO', '/absolute/path/to/the/checkout');       // outside the web root\n"
       . "define('DEPLOY_BRANCH', 'main');\n"
       . "define('DEPLOY_PUBLISH', [\n"
       . "    // 'file/in/the/repo.php' => '/absolute/path/in/the/web/root.php',\n"
       . "]);\n"
       . "define('DEPLOY_LOG', '/absolute/path/to/deploy.log');          // outside the web root too\n";

    fwrite(STDERR, "\nThat is a config. Redirect it into deploy.config.php beside deploy.php,\n"
        . "fill in DEPLOY_PUBLISH, and give GitHub the same secret:\n\n"
        . "  php deploy.php > deploy.config.php\n"
        . "  php -l deploy.config.php\n\n");
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

function say(int $status, string $message): never {
    http_response_code($status);

    $line = gmdate('c') . " $status $message";
    DEPLOY_LOG === '' ? error_log("deploy: $line") : @file_put_contents(DEPLOY_LOG, "$line\n", FILE_APPEND | LOCK_EX);

    exit($message . "\n");
}

/**
 * Who this process is, for a message about permissions. The whole class of problem
 * is that the web server is not the person who ran the clone.
 */
function whoami(): string {
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        return (posix_getpwuid(posix_geteuid())['name'] ?? '') ?: 'this user';
    }
    return get_current_user() ?: 'this user';
}

/**
 * Runs git as an argument array, never a command string. Nothing from the request
 * reaches this, and nothing here can be turned into a second command by a quote.
 */
function git(array $args): array {
    if (!function_exists('proc_open')) return [1, 'proc_open is disabled on this host'];

    $proc = @proc_open(
        array_merge(['git', '-C', DEPLOY_REPO], $args),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) return [1, 'git could not be started'];

    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($proc), trim($out)];
}

/**
 * Copies one file into place through a temporary name. Rename is atomic, so a
 * request arriving mid-deploy gets the whole old file or the whole new one, and
 * never the half-written thing in between.
 */
function publish(string $from, string $to): bool {
    if (!is_file($from)) return false;

    $temp = $to . '.deploy-new';
    if (!@copy($from, $temp)) return false;
    if (!@rename($temp, $to)) {
        @unlink($temp);
        return false;
    }
    return true;
}


// ---- the request ------------------------------------------------------------

if (DEPLOY_SECRET === '') say(500, 'deploy.php has no secret configured');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') say(405, 'POST only');

// Read the body before anything else: the signature covers these exact bytes, and
// re-encoding them anywhere in between is how a signature check quietly stops
// matching for reasons nobody can see.
$body = file_get_contents('php://input') ?: '';
if (strlen($body) > 1024 * 1024) say(413, 'payload too large');

$mine = 'sha256=' . hash_hmac('sha256', $body, DEPLOY_SECRET);
if (!hash_equals($mine, $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '')) say(401, 'signature does not match');

// Only now is the payload worth parsing.
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event === 'ping') say(200, 'pong');
if ($event !== 'push') say(202, "ignored: $event is not a push");

$payload = json_decode($body, true);

// GitHub's webhook form defaults to application/x-www-form-urlencoded, which
// wraps the JSON in a payload= field. The signature covers the raw body either
// way, so this only decides how the ref is read — but getting it wrong looks like
// a deploy that answers 400 to every push while the ping says pong.
if (!is_array($payload) && str_starts_with($body, 'payload=')) {
    parse_str($body, $form);
    $payload = json_decode((string) ($form['payload'] ?? ''), true);
}

if (!is_array($payload)) say(400, 'payload is neither JSON nor a payload= form field');

$ref = (string) ($payload['ref'] ?? '');
if ($ref !== 'refs/heads/' . DEPLOY_BRANCH) say(202, 'ignored: ' . substr($ref, 0, 100));


// ---- the deploy -------------------------------------------------------------

// A checkout under /root or a home directory is the usual reason this fails: the
// directory is 0700, the web server is not that user, and git's own answer is
// "cannot change to …: Permission denied" with no hint about which user it means.
if (!is_dir(DEPLOY_REPO)) {
    say(500, DEPLOY_REPO . ' is not a directory. DEPLOY_REPO must be a git checkout the web server can read.');
}
if (!is_readable(DEPLOY_REPO) || !is_dir(DEPLOY_REPO . '/.git')) {
    say(500, DEPLOY_REPO . ' is not a readable git checkout for ' . whoami()
        . '. A checkout under /root or a home directory is unreachable however open its own permissions are,'
        . ' because every directory above it must be traversable too — put it somewhere like /srv or /var/www.');
}
if (!is_writable(DEPLOY_REPO . '/.git')) {
    say(500, DEPLOY_REPO . '/.git is not writable by ' . whoami()
        . '. git writes there on every fetch: chown -R ' . whoami() . ' ' . DEPLOY_REPO);
}

// fetch and reset, not pull: a deploy target has no local work worth merging, and
// `pull --ff-only` fails after a force-push upstream — which leaves the site on an
// old version behind a webhook still reporting success.
[$code, $out] = git(['fetch', '--quiet', 'origin', DEPLOY_BRANCH]);
if ($code !== 0) say(500, "fetch failed: $out");

[$code, $out] = git(['reset', '--hard', '--quiet', 'origin/' . DEPLOY_BRANCH]);
if ($code !== 0) say(500, "reset failed: $out");

[, $head] = git(['rev-parse', '--short', 'HEAD']);

$published = 0;
foreach (DEPLOY_PUBLISH as $from => $to) {
    if (!publish(rtrim(DEPLOY_REPO, '/') . '/' . ltrim($from, '/'), $to)) {
        say(500, "could not publish $from");
    }
    $published++;
}

say(200, "deployed $head" . ($published ? ", $published file(s) published" : ''));
