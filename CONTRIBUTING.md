# Contributing to php-deploy-hook

Issues and pull requests are welcome. Taking part means keeping to the
[Code of Conduct](CODE_OF_CONDUCT.md).

This hook receives a push from GitHub, updates a checkout, and copies files out
of it. It is finished when it does that well. What it refuses:

- **No commands from configuration.** Not `composer install`, not a build step,
  not a post-deploy hook. A webhook that runs configured commands is a remote
  shell with a password in front of it, and one leaked secret stops being "the
  wrong version is live". Build in CI; deploy the result.
- **No dependencies.** Not Composer, not a framework. A file you can read end to
  end before trusting it with your server is the feature.
- **One file, plus an optional config file.** A second required file means
  upgrading stops being "copy the new one over the old one".
- **No provider zoo.** GitHub's push webhook, and its signature scheme. GitLab,
  Gitea and Bitbucket sign differently and each would double this file for a user
  who is not here. Fork it — that is what a hundred lines is for.
- **Nothing from a request reaches a shell or chooses a path.** Every path is a
  constant in the config, and git runs as an argument array. A change that breaks
  either of those is not a change to this project.
- **No queue, no state, no daemon.** It runs while the request is open and then
  it is gone.

## Getting set up

```bash
git clone https://github.com/stamat/php-deploy-hook.git
cd php-deploy-hook
script/bootstrap
```

```bash
script/server    # a throwaway repository, and a signed curl to fire at it
script/test      # a real repository, driven over HTTP
script/lint      # php -l over everything
```

`deploy.php` is the whole program. `test/run.php` is the spec — it opens with
what it covers and what it deliberately does not.

## Reporting a bug

[Open an issue](../../issues/new/choose) — the form asks for what you ran, what
you expected, the version and the environment.

Include your PHP version, whether `proc_open` is enabled, and what your host is.
Shared hosting varies more than any other part of this.

**Found something that lets a request run a command, write outside
`DEPLOY_PUBLISH`, or deploy without a valid signature?** That is not an issue —
report it privately through [@stamat](https://github.com/stamat) instead.

## Pull requests

- **Add a test.** A bug fix gets a test that fails without the fix. Test names
  are sentences describing the guarantee, not the function.
- **Anything from the request is hostile.** The signature, the event, the ref,
  the body. A change that touches one needs the test that proves what it refuses,
  named so the test documents the attack.
- **Match the surrounding style.** `script/lint` is the authority, and CI runs it.
- **Add a changelog entry** under `## [Unreleased]` in [CHANGELOG.md](CHANGELOG.md).
- **Keep the diff about one thing.**
- **Agent-written code is welcome — you still own it.** Tests, lint, CI green,
  and you understand every line well enough to answer review questions. Point
  your agent at [AGENTS.md](AGENTS.md) before it starts.

Commit messages are freeform, write something that says what changed.

## How a release works

`script/publish [version]` takes the current version from the last `v*` tag,
writes the new one into `deploy.php` with `script/version`, cuts the changelog,
commits, tags, pushes, and offers to open the GitHub release with that entry as
its body. There is no registry: the artifact is `deploy.php` in the tagged tree.
