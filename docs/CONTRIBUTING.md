# Contributing Guide

Thank you for contributing to Workflow Bundle.

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

- PHP 8.1+, `declare(strict_types=1);`
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
