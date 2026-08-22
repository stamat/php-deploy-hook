# Changelog

All notable changes to php-deploy-hook are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and
the project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Contributing an entry

Write your change under `## [Unreleased]`, grouped under `### Added`,
`### Changed`, `### Fixed`, `### Deprecated`, `### Removed` or `### Security`.
Give the heading a short title after an em dash and open with one paragraph
saying what was wrong before:

```markdown
## [Unreleased] — timeouts are configurable

Every request used the same hardcoded thirty seconds, which is too long for a
health check and too short for an upload.

### Added

- ...
```

Write it for the person upgrading, not for the person who wrote the code. What
they need is what changed for them: a renamed option, a different default, an
error that is now thrown, output that moved.

On `script/publish`, `script/changelog` cuts this section into a released entry
in the same commit as the version bump, and the entry becomes the body of the
GitHub release verbatim.

## [Unreleased] — first working version

Triggering a deploy on push meant handing a private key to someone else's CI, an
FTP password to the same, or running a cron poll every minute forever to catch a
push a week. GitHub already knows the moment you pushed.

### Added

- `deploy.php`: a GitHub push webhook that verifies `X-Hub-Signature-256` with
  `hash_equals`, updates a checkout with `fetch` and `reset --hard`, and copies
  the files named in `DEPLOY_PUBLISH` into place through an atomic rename.
- Settings in an optional `deploy.config.php`, so upgrading is copying one file
  over the old one.
- Every answer — deploys, refusals and ignored events — written to `DEPLOY_LOG`,
  because a webhook that silently does nothing is the failure people actually hit.
- `test/run.php`: twelve guarantees driven over HTTP against a real git
  repository, including a force-push upstream and a branch name carrying a shell
  command, with no test framework to install.
