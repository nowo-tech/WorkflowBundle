# Contributing Guide

Thank you for contributing to Workflow Bundle.


## Table of contents

- [Code of Conduct](#code-of-conduct)
- [Development setup](#development-setup)
- [Quality checks](#quality-checks)
- [Code standards](#code-standards)
- [Pull requests](#pull-requests)
- [Spec-driven development](#spec-driven-development)
- [Questions](#questions)
- [Cursor Agent](#cursor-agent)
- [Git hooks (REQ-GIT-001)](#git-hooks-req-git-001)

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](../CODE_OF_CONDUCT.md). By participating, you are expected to uphold it. Please report unacceptable behavior to **hectorfranco@nowo.tech**.

## Development setup

```bash
git clone https://github.com/nowo-tech/WorkflowBundle.git
cd WorkflowBundle
make up
make install
```

Demos: `make -C demo up-symfony8` (FrankenPHP + PostgreSQL).

Playwright (REQ-DEMO-013, official Playwright Docker image): `make -C demo/symfony8 reset-demo test-e2e` runs the
admin happy paths; `make -C demo/symfony8 demo-screenshots` refreshes `docs/images/demo/*.webp`.

Page contract (REQ-UI-003): `tests/Integration/PageContractTest.php` renders every admin page against UiKit's stub
host layout and theme; pages, data blocks and view models are listed in [TEMPLATES.md](TEMPLATES.md).

## Quality checks

```bash
make cs-check
make phpstan
make igor
make test
make test-coverage
make release-check
```

Or inside the container: `composer qa` runs cs-check + tests.

PHPStan includes **`nowo-tech/phpstan-frankenphp`** rulesets (classic + worker) as a **require-dev** maintainer tool only (REQ-CS-005) — it is not a runtime dependency of consumer apps.

## Code standards

- PHP 8.2+, `declare(strict_types=1);`
- PSR-12 via PHP-CS-Fixer
- PHPDoc on public classes and methods (English)
- New behavior requires PHPUnit coverage

## Pull requests

1. Update `docs/CHANGELOG.md` for user-visible changes
2. Run `make release-check` before opening the PR
3. Use the PR template in `.github/PULL_REQUEST_TEMPLATE.md`

## Spec-driven development

See [SPEC-DRIVEN-DEVELOPMENT.md](SPEC-DRIVEN-DEVELOPMENT.md) for product scope and `REQ-*` traceability.

## Questions

Open an [issue](https://github.com/nowo-tech/WorkflowBundle/issues) or contact hectorfranco@nowo.tech.

## Cursor Agent

Contributors using Cursor Agent on this repository should enable these user-level settings (REQ-IDE-006):

1. **Submit with Ctrl + Enter** (macOS: **⌘ + Enter**) — Cursor Settings → Agents / Chat → Submit with Ctrl + Enter. Enter inserts a newline; Ctrl/⌘+Enter submits the prompt.
2. **Queue follow-ups while the agent is running** — in your user `settings.json` (Linux `~/.config/Cursor/User/settings.json`, Windows `%APPDATA%\Cursor\User\settings.json`, macOS `~/Library/Application Support/Cursor/User/settings.json`):

```json
{
  "cursor.composer.queueMessageDefaultBehavior": "queue"
}
```

Do not use `"steer"` as the default: it interrupts the running agent turn as soon as the follow-up is sent.

## Git hooks (REQ-GIT-001)

Do **not** add `Co-authored-by: Cursor` or `cursoragent@cursor.com` trailers, nor `Co-Authored-By: Claude …` / `noreply@anthropic.com` ones, to commit messages.

```bash
make setup-hooks
make check-no-cursor-coauthor
```

`make setup-hooks` installs `.githooks/commit-msg` (or sets `core.hooksPath` to `.githooks`). Run it once per clone before your first commit.

If CI fails because trailers are already on the remote, see [GITHUB_CI.md](GITHUB_CI.md) (REQ-GIT-001) and run `make strip-cursor-coauthor-from-history` before `git push --force-with-lease`.
