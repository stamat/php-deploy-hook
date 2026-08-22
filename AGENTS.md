# php-deploy-hook — agent notes

A GitHub push webhook in one PHP file: update a checkout, copy files out of it.

Read [CONTRIBUTING.md](CONTRIBUTING.md) first — it defines what belongs in this
project and what a pull request needs.

## Commands

```bash
script/bootstrap # install what the project needs, from a fresh clone
script/server    # run it locally
script/build     # produce the artifacts
script/test      # run the tests
script/lint      # run the linters (the authority; CI runs it)
```

## Layout

`deploy.php` is the entire program, in the order it runs: config, helpers,
request checks, then the deploy. Nothing is generated, and the file people
download is the file in the repository.

`test/run.php` builds a real upstream repository with `git init`, clones it, and
drives `deploy.php` over PHP's built-in server. It needs git on PATH; there is no
test framework and no `vendor/`.

## Documentation

There is no docs site. README.md is the documentation and CONTRIBUTING.md holds
the refusals. The README's "What it answers" table is the contract — a change to
any status code or message belongs in it in the same commit.

- **Document in the same change as the code.** A behavior change that ships
  undocumented is unfinished — the doc page, the README section, the comment
  format above, whichever of them covers it.
- **Edit the page that already covers it.** Do not add new pages, new README
  sections, or summary and migration files nobody asked for. A doc nobody
  asked for is a doc nobody maintains.
- **Write for the person using it**, not the person who wrote it: what it
  does, one example that runs, and the part that would otherwise surprise
  them.

## Principles

- **Test-driven.** The test is the spec; write it first. A failing test means
  the code is wrong — never weaken, skip, or delete a test to make it pass. If
  the test itself is wrong, say so and let review decide.
- **YAGNI.** Build only what the task needs — no speculative options,
  abstractions, or "for later" scaffolding.
- **Native / stdlib first.** In order: what's already in this repo → the
  platform → the standard library → new code. A new dependency is a last
  resort and needs a reason.
- **Root cause over symptom.** Fix where all callers route through, not the
  one path the bug report names.
- **Delete dead code.** No commented-out blocks, no "for later" exports — git
  remembers.

## Boundaries

- **Always:** run `script/lint` and `script/test` before calling work done;
  pair every fix or feature with a test; document anything user-visible where
  it is already documented; add a changelog entry under `## [Unreleased]`.
- **Ask first:** renaming a `DEPLOY_*` constant, which breaks every existing
  `deploy.config.php`; changing a status code the README documents.
- **Never:** let anything from a request reach a shell or choose a path; run a
  command that came from configuration; add a dependency; weaken, skip, or
  delete a test to make it pass; bump the version or publish — a tag does that.

## Before adding a feature

Run this checklist before writing any code; stop at the first "no".

1. **Does the platform or standard library already do it?** If so, there is
   no feature.
2. **Search for prior art.** How do similar projects do it? What interface do
   they expose? Cite what you found — a URL per fact, no guesses. How can we
   improve on it? If the answer is "we can't", would we benefit from having it
   here at all?
3. **Does it fit the project?** CONTRIBUTING.md says what this project is for
   and what it refuses to become — check against that paragraph, before
   building, not after.
4. **Still yes?** Build the smallest version that works.

## Non-obvious rules

- **The signature covers the raw body bytes.** Read `php://input` once and hash
  that. Decoding and re-encoding the payload anywhere before the check makes the
  signature stop matching for reasons that are invisible in a diff.
- **Check the signature before parsing anything.** `json_decode` on an unverified
  megabyte is work an unauthenticated stranger asked you to do.
- **`script/version` rewrites `const DEPLOY_VERSION` by regex.** Keep that line
  on one line, single-quoted, or a release ships the old number.
- **`fetch` + `reset --hard`, never `pull`.** A rebase or amended commit upstream
  makes `pull --ff-only` fail, and the site sits on an old version behind a
  webhook that looks fine.
