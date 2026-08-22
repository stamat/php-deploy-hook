# php-deploy-hook

A GitHub push webhook, in one PHP file. It updates a checkout and copies files
into place. That is the whole program.

## The problem this exists for

Deploying a small PHP site is `git pull` on the server, and the usual ways to
trigger it all want something you would rather not give:

| Option | What it wants |
| --- | --- |
| A deploy key in GitHub Actions | outbound SSH to your box, and a private key in someone else's CI |
| FTP upload from CI | your password in CI, and a partial upload if the connection drops mid-way |
| Cron polling `git pull` | a minute of latency, and a scheduler running every minute forever to catch a push a week |
| A PaaS agent | an agent on a host whose whole appeal was that it runs nothing |

A webhook is the missing option: GitHub already knows the moment you pushed, and
tells you. All that is needed on the other end is a file that checks it really was
GitHub, and then runs git.

The gist everybody copies for this does the job and leaks in three predictable
ways. This is that gist with the leaks closed.

| | The usual copy-paste | here |
| --- | --- | --- |
| Signature | `sha1`, which GitHub keeps only for legacy | `sha256`, compared with `hash_equals` |
| Runs git as | `shell_exec("cd $dir && git pull")` | an argument array through `proc_open` |
| Checkout | inside the web root, publishing `.git/config` to anyone who asks | outside it; only what you list is copied in |
| Replacing a file | written in place, so a request mid-deploy gets half of it | written beside it, then renamed — atomic |
| After a force-push | `pull --ff-only` fails; the site stays old behind a green webhook | `fetch` + `reset --hard` follows it |

## Install

```bash
# the checkout lives outside the web root, so nobody can fetch .git/config
git clone https://github.com/you/your-site.git /home/you/site

# the hook is the only thing the web sees
cp deploy.php /home/you/public_html/deploy.php
```

Then write the config next to it, rather than typing one — a hand-written config
with a missing semicolon in it fails as an empty `500`, which says nothing about
where to look:

```bash
cd /home/you/public_html
php deploy.php > deploy.config.php     # generates the secret, leaves the paths blank
php -l deploy.config.php               # it parses, and now you know
```

Then fill in the paths it left as placeholders:

```php
<?php
define('DEPLOY_SECRET', '…generated for you…');
define('DEPLOY_REPO',   '/home/you/site');          // the checkout
define('DEPLOY_BRANCH', 'main');
define('DEPLOY_PUBLISH', [                          // what to copy out of it, and where
    'index.php' => '/home/you/public_html/index.php',
]);
define('DEPLOY_LOG',    '/home/you/deploy.log');    // outside the web root; '' sends it to error_log
```

The paths are placeholders rather than guesses on purpose: a config that parses
and points at the wrong directory deploys nothing and says it worked.

Then GitHub → repository → **Settings → Webhooks → Add webhook**:

| Field | Value |
| --- | --- |
| Payload URL | `https://yoursite.com/deploy.php` |
| Content type | `application/json` — `form` works too, it is just noisier |
| Secret | the same string as `DEPLOY_SECRET` |
| SSL verification | enabled |
| Which events | just the push event |

GitHub's **Recent Deliveries** tab shows the reply to every call, which is where
`deployed a1b2c3d, 1 file(s) published` will be, or the reason it refused.

Settings live in their own file so that upgrading is copying one file over the
old one.

## Serving the checkout directly

If your site *is* the repository — a static site, or PHP that runs from the
checkout — leave `DEPLOY_PUBLISH` empty and point your web root at a directory
inside the checkout, never at its root. Serving the root publishes `.git`, and
`https://yoursite.com/.git/config` is the first thing an attacker asks for.

## What it answers

| Request | Answer |
| --- | --- |
| A push to `DEPLOY_BRANCH` | `200 deployed <sha>` |
| A wrong or missing signature | `401`, before the payload is parsed |
| Any method but POST | `405` |
| A payload over 1 MB | `413` |
| A body that is neither JSON nor `payload=` | `400` |
| A push to another branch | `202 ignored: refs/heads/…` |
| Any other event | `202` |
| GitHub's ping | `200 pong` |

Every one of those is written to `DEPLOY_LOG`, because the failure people
actually hit is a webhook that silently does nothing.

Both content types are accepted. GitHub's form defaults to
`application/x-www-form-urlencoded`, which wraps the JSON in a `payload` field —
the signature covers the raw body either way, so the only symptom of the
mismatch would have been a webhook that answers `pong` to the ping and `400` to
every push after it.

## What it will not do

**Run your commands.** No `composer install`, no build step, no post-deploy
hooks. A webhook that runs commands from its configuration is a remote shell with
a password on it, and the blast radius of a mistake stops being "the wrong
version is live". If your deploy needs to build something, build it in CI and
commit or release the result.

**Write anything outside `DEPLOY_PUBLISH`.** The destinations are constants in a
file only you can edit. Nothing in a request chooses a path.

**Deploy from anywhere but `DEPLOY_BRANCH`.** A push to any other branch is
answered and ignored.

## When it answers nothing

Every path in `deploy.php` prints a sentence, so **an empty body is never this
file talking**. An empty `500` with `Content-Type: text/html` means PHP died
before the script ran, with `display_errors` off — and the only thing that runs
before the first `header()` call is `deploy.config.php`.

```bash
php -l /path/to/deploy.config.php     # a parse error there is the usual answer
sudo tail -30 /var/log/apache2/error.log
```

A `404` means Apache is not serving that path, or a rewrite rule swallowed it. A
`200` showing you the source of the file means PHP is not handling `.php` in that
directory at all.

## Limits, stated plainly

- **`proc_open` must be available.** Plenty of shared hosting disables it. There
  is no fallback, and the hook says so rather than pretending: `500 proc_open is
  disabled on this host`.
- **The webhook secret is in a file on the server.** Anything that can read
  `deploy.config.php` can forge a deploy. Keep it out of the web root's reach and
  give it the tightest permissions your host allows.
- **A deploy is `reset --hard`.** Local changes in the checkout are destroyed,
  by design. The checkout is a copy of a branch, not somewhere to work.
- **One deploy at a time is not enforced.** Two pushes seconds apart run two
  deploys; the second `reset` lands on the same commit, so the outcome is right,
  but git may log a lock contention along the way.
- **Tested on PHP 8.1 and 8.4 in CI with git 2.x**, driving a real repository over
  HTTP. Not tested on Windows, and not tested against GitHub itself — the
  payloads in the suite are the shape GitHub sends, not recordings of it.

## Development

```
script/bootstrap   check PHP 8.1+ and git; installs nothing, because there is nothing
script/server      run it against a throwaway repository, and print a signed curl
script/lint        php -l over everything
script/test        the suite in test/run.php
```

The tests build a real upstream repository, clone it, and drive the hook over
HTTP: signatures, refusals, a deploy, a second deploy, a force-push, and a branch
name carrying a shell command.

## License

MIT.
