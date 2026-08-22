<?php
/**
 * What this covers: everything the endpoint decides before it touches a repository
 * — signature, method, event, branch, payload size — and then a real deploy against
 * a real git checkout, including the file swap and a force-push upstream.
 *
 * What it deliberately does not cover: GitHub itself. The payloads here are the
 * shape GitHub sends, not recordings of it, so a change at their end shows up as a
 * webhook that answers 202 rather than as a red test.
 *
 * No framework on purpose. This is a leaf; it installs nothing to test itself.
 */

$port   = 8471;
$root   = sys_get_temp_dir() . '/deploy-test-' . getmypid();
$origin = "$root/origin";
$repo   = "$root/checkout";
$web    = "$root/web";
$live   = "$root/live";
$secret = 'a shared secret';
$base   = "http://127.0.0.1:$port/deploy.php";

foreach ([$root, $web, $live] as $dir) mkdir($dir, 0777, true);

function run(string ...$args): array {
    $proc = proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out  = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), trim($out)];
}

// An upstream repository, and a checkout of it, exactly as a server would have.
run('git', 'init', '--quiet', '-b', 'main', $origin);
run('git', '-C', $origin, 'config', 'user.email', 'test@example.com');
run('git', '-C', $origin, 'config', 'user.name', 'test');
file_put_contents("$origin/index.php", "<?php echo 'one';\n");
run('git', '-C', $origin, 'add', '-A');
run('git', '-C', $origin, 'commit', '--quiet', '-m', 'one');
run('git', 'clone', '--quiet', $origin, $repo);

copy(__DIR__ . '/../deploy.php', "$web/deploy.php");
file_put_contents("$web/deploy.config.php", "<?php\n"
    . "define('DEPLOY_SECRET', " . var_export($secret, true) . ");\n"
    . "define('DEPLOY_REPO', " . var_export($repo, true) . ");\n"
    . "define('DEPLOY_BRANCH', 'main');\n"
    . "define('DEPLOY_PUBLISH', ['index.php' => " . var_export("$live/index.php", true) . "]);\n"
    . "define('DEPLOY_LOG', " . var_export("$root/deploy.log", true) . ");\n");

$server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $web],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', "$root/server.log", 'w']], $pipes);

register_shutdown_function(function () use ($server, $root) {
    proc_terminate($server);
    run('rm', '-rf', $root);
});

for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port, $e, $s, 0.1); $i++) usleep(100_000);

// --- harness ----------------------------------------------------------------

$failures = 0;

function it(string $guarantee, callable $body): void {
    global $failures;
    try {
        $body();
        echo "  ok   $guarantee\n";
    } catch (Throwable $e) {
        $failures++;
        echo "  FAIL $guarantee\n       " . $e->getMessage() . "\n";
    }
}

function assert_that(bool $ok, string $whatBroke): void {
    if (!$ok) throw new RuntimeException($whatBroke);
}

/** Posts a payload signed the way GitHub signs it, unless $signWith says otherwise. */
function post(string $event, string $body, ?string $signWith = null): array {
    $signature = 'sha256=' . hash_hmac('sha256', $body, $signWith ?? $GLOBALS['secret']);
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "X-GitHub-Event: $event\r\nX-Hub-Signature-256: $signature\r\nContent-Type: application/json",
        'content'       => $body,
        'ignore_errors' => true,
        'timeout'       => 10,
    ]]);
    $out    = (string) file_get_contents($GLOBALS['base'], false, $ctx);
    $status = (int) (explode(' ', $http_response_header[0] ?? 'HTTP/1.1 0')[1] ?? 0);
    return [$status, trim($out)];
}

function push_payload(string $branch = 'main'): string {
    return json_encode(['ref' => "refs/heads/$branch", 'after' => str_repeat('a', 40)]);
}

function commit(string $text): void {
    file_put_contents($GLOBALS['origin'] . '/index.php', "<?php echo '$text';\n");
    run('git', '-C', $GLOBALS['origin'], 'commit', '--quiet', '-am', $text);
}

echo "php-deploy-hook\n";

// --- the config it writes ----------------------------------------------------

it('the config it prints is valid PHP, with a secret nobody can guess', function () {
    $proc = proc_open([PHP_BINARY, __DIR__ . '/../deploy.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    array_map('fclose', [$pipes[1], $pipes[2]]);
    proc_close($proc);

    $file = $GLOBALS['root'] . '/printed.config.php';
    file_put_contents($file, $out);

    // The whole point: what comes out of here parses. A blank 500 from a hand-typed
    // config is what this replaces.
    [$code, $lint] = run(PHP_BINARY, '-l', $file);
    assert_that($code === 0, "the printed config does not parse: $lint");

    assert_that(preg_match("~define\\('DEPLOY_SECRET', '([0-9a-f]{64})'\\);~", $out) === 1,
        "no usable secret in the printed config: $out");
    assert_that(str_contains($out, "define('DEPLOY_PUBLISH'"), 'the printed config does not mention what to publish');
    assert_that(str_contains($out, '/absolute/path/to/the/checkout'),
        'a path was guessed rather than left as a placeholder, which deploys the wrong thing quietly');

    $again = proc_open([PHP_BINARY, __DIR__ . '/../deploy.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes2);
    $second = stream_get_contents($pipes2[1]);
    array_map('fclose', [$pipes2[1], $pipes2[2]]);
    proc_close($again);
    assert_that($out !== $second, 'two runs printed the same secret');
});

// --- what it refuses before it touches anything ------------------------------

it('a payload signed with the wrong secret is refused, and nothing is deployed', function () {
    [$status, $body] = post('push', push_payload(), 'not the secret');
    assert_that($status === 401, "a forged signature was answered $status: $body");
    assert_that(!is_file($GLOBALS['live'] . '/index.php'), 'a forged request published a file');
});

it('a payload with no signature at all is refused', function () {
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'content' => push_payload(),
        'header' => "X-GitHub-Event: push\r\nContent-Type: application/json",
        'ignore_errors' => true, 'timeout' => 10,
    ]]);
    file_get_contents($GLOBALS['base'], false, $ctx);
    assert_that(str_contains($http_response_header[0] ?? '', '401'), 'an unsigned request was not refused');
});

it('anything but POST is refused, so a crawler cannot deploy by visiting the URL', function () {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
    file_get_contents($GLOBALS['base'], false, $ctx);
    assert_that(str_contains($http_response_header[0] ?? '', '405'), 'a GET was not refused');
});

it("GitHub's ping is answered, so the webhook can be tested from their side", function () {
    [$status, $body] = post('ping', '{"zen":"Non-blocking is better than blocking."}');
    assert_that($status === 200 && $body === 'pong', "ping was answered $status: $body");
});

it('a push to another branch is ignored rather than deployed', function () {
    [$status, $body] = post('push', push_payload('dev'));
    assert_that($status === 202, "a push to dev was answered $status: $body");
    assert_that(str_contains($body, 'refs/heads/dev'), "the answer does not say what was ignored: $body");
});

it('an event that is not a push is ignored', function () {
    [$status, $body] = post('issues', '{"action":"opened"}');
    assert_that($status === 202, "an issues event was answered $status: $body");
});

it('a payload larger than a megabyte is refused rather than parsed', function () {
    [$status] = post('push', json_encode(['ref' => 'refs/heads/main', 'padding' => str_repeat('x', 1024 * 1024 + 10)]));
    assert_that($status === 413, "an oversized payload was answered $status");
});

it('a read-only web root still gets the file, since the file itself is writable', function () {
    // What a hardened host looks like: the directory belongs to root, the one file
    // the deploy owns belongs to the web server.
    $locked = $GLOBALS['root'] . '/locked-root';
    mkdir($locked);
    $target = $locked . '/index.php';
    file_put_contents($target, "<?php echo 'old';\n");
    chmod($locked, 0555);

    $web = $GLOBALS['root'] . '/web3';
    mkdir($web);
    copy(__DIR__ . '/../deploy.php', $web . '/deploy.php');
    file_put_contents($web . '/deploy.config.php', "<?php\n"
        . "define('DEPLOY_SECRET', " . var_export($GLOBALS['secret'], true) . ");\n"
        . "define('DEPLOY_REPO', " . var_export($GLOBALS['repo'], true) . ");\n"
        . "define('DEPLOY_BRANCH', 'main');\n"
        . "define('DEPLOY_PUBLISH', ['index.php' => " . var_export($target, true) . "]);\n");

    commit('into a read-only directory');

    $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:8473', '-t', $web],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', $web . '/log', 'w']], $pipes);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', 8473, $e, $s, 0.1); $i++) usleep(100_000);

    $body = push_payload();
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "X-GitHub-Event: push\r\nX-Hub-Signature-256: sha256=" . hash_hmac('sha256', $body, $GLOBALS['secret']) . "\r\nContent-Type: application/json",
        'content' => $body, 'ignore_errors' => true, 'timeout' => 10,
    ]]);
    $out = (string) file_get_contents('http://127.0.0.1:8473/deploy.php', false, $ctx);
    proc_terminate($proc);
    chmod($locked, 0755);

    if (is_writable($locked)) return;   // running as root: nothing to fall back from

    assert_that(str_contains($out, 'deployed'), "a writable file in a read-only directory was refused: $out");
    assert_that(str_contains((string) file_get_contents($target), 'into a read-only directory'),
        'the fallback did not actually replace the file');
});

it('a checkout it cannot reach is explained in terms of the user, not of git', function () {
    // Point an install at a directory that is not a checkout at all.
    $elsewhere = $GLOBALS['root'] . '/not-a-checkout';
    mkdir($elsewhere);

    $web = $GLOBALS['root'] . '/web2';
    mkdir($web);
    copy(__DIR__ . '/../deploy.php', $web . '/deploy.php');
    file_put_contents($web . '/deploy.config.php', "<?php\n"
        . "define('DEPLOY_SECRET', " . var_export($GLOBALS['secret'], true) . ");\n"
        . "define('DEPLOY_REPO', " . var_export($elsewhere, true) . ");\n"
        . "define('DEPLOY_BRANCH', 'main');\n");

    $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:8472', '-t', $web],
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', $web . '/log', 'w']], $pipes);
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', 8472, $e, $s, 0.1); $i++) usleep(100_000);

    $body = push_payload();
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "X-GitHub-Event: push\r\nX-Hub-Signature-256: sha256=" . hash_hmac('sha256', $body, $GLOBALS['secret']) . "\r\nContent-Type: application/json",
        'content' => $body, 'ignore_errors' => true, 'timeout' => 10,
    ]]);
    $out = (string) file_get_contents('http://127.0.0.1:8472/deploy.php', false, $ctx);
    proc_terminate($proc);

    assert_that(str_contains($out, 'not a readable git checkout'), "the answer does not name the problem: $out");
    assert_that(str_contains($out, '/srv'), "the answer does not say where to put it instead: $out");
});

// --- what it does when the signature is good ---------------------------------

it('a form-encoded push is understood too, since that is what the webhook form defaults to', function () {
    commit('form-encoded');

    // What GitHub sends when Content type is left at application/x-www-form-urlencoded.
    [$status, $body] = post('push', 'payload=' . urlencode(push_payload()));
    assert_that($status === 200, "a form-encoded push was answered $status: $body");
    assert_that(str_contains((string) file_get_contents($GLOBALS['live'] . '/index.php'), 'form-encoded'),
        'a form-encoded push did not deploy');
});

it('a body that is neither JSON nor a payload field is refused with a reason', function () {
    [$status, $body] = post('push', 'this is not a payload at all');
    assert_that($status === 400, "junk was answered $status");
    assert_that(str_contains($body, 'payload='), "the reason does not mention the form field: $body");
});

it('a push to the deploy branch updates the checkout and publishes the file', function () {
    commit('two');

    [$status, $body] = post('push', push_payload());
    assert_that($status === 200, "a real push was answered $status: $body");
    assert_that(str_contains($body, 'deployed'), "the answer does not say what happened: $body");
    assert_that(str_contains((string) file_get_contents($GLOBALS['live'] . '/index.php'), 'two'),
        'the published file is not the one that was just pushed');
});

it('a second push publishes the newer file over the older one', function () {
    commit('three');
    post('push', push_payload());
    assert_that(str_contains((string) file_get_contents($GLOBALS['live'] . '/index.php'), 'three'),
        'the second deploy did not replace the first');
});

it('a force-push upstream still deploys, where pull --ff-only would have stuck', function () {
    // Rewrite history the way an amended commit or a rebase does.
    run('git', '-C', $GLOBALS['origin'], 'reset', '--quiet', '--hard', 'HEAD~1');
    commit('rewritten');

    [$status, $body] = post('push', push_payload());
    assert_that($status === 200, "a force-pushed branch was answered $status: $body");
    assert_that(str_contains((string) file_get_contents($GLOBALS['live'] . '/index.php'), 'rewritten'),
        'the deploy did not follow a rewritten history');
});

it('nothing from the request reaches the shell, however the branch is spelled', function () {
    $before = file_get_contents($GLOBALS['live'] . '/index.php');
    [$status] = post('push', json_encode(['ref' => 'refs/heads/main; touch ' . $GLOBALS['root'] . '/pwned']));

    assert_that($status === 202, "an injected ref was answered $status");
    assert_that(!file_exists($GLOBALS['root'] . '/pwned'), 'a ref carrying a shell command ran it');
    assert_that(file_get_contents($GLOBALS['live'] . '/index.php') === $before, 'an injected ref changed the published file');
});

it('every refusal is written to the log, so a silent webhook can be explained', function () {
    $log = (string) file_get_contents($GLOBALS['root'] . '/deploy.log');
    assert_that(str_contains($log, '401 signature does not match'), 'a forged signature left no trace');
    assert_that(str_contains($log, '200 deployed'), 'a successful deploy left no trace');
});

echo $failures ? "\n$failures failed\n" : "\nall good\n";
exit($failures ? 1 : 0);
