# Contributing Guide

Thank you for contributing to Workflow Bundle.


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

## Quality checks

```bash
make cs-check
make phpstan
make test
make test-coverage
make release-check
```

Or inside the container: `composer qa` runs cs-check + tests.

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

## Git hooks (REQ-GIT-001)

Do **not** add `Co-authored-by: Cursor` or `cursoragent@cursor.com` trailers to commit messages.

```bash
make setup-hooks
make check-no-cursor-coauthor
```

`make setup-hooks` installs `.githooks/commit-msg` (or sets `core.hooksPath` to `.githooks`). Run it once per clone before your first commit.

If CI fails because trailers are already on the remote, see [GITLAB_CI.md](GITLAB_CI.md) (REQ-GIT-001) and run `make strip-cursor-coauthor-from-history` before `git push --force-with-lease`.
