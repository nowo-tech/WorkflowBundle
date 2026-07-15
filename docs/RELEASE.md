# Release

## Pre-release

```bash
make release-check
```

Pipeline: Composer validate/sync → cs-fix → cs-check → rector-dry → phpstan → test-coverage → validate-translations → demo verification.

## Tag and publish

1. Update `docs/CHANGELOG.md`
2. Create annotated tag `vX.Y.Z`
3. Push the tag
4. Confirm `.github/workflows/release.yml` and `sync-releases.yml` succeed

## Post-release

- Verify Packagist metadata
- Smoke-test in a clean Symfony app: `composer require nowo-tech/workflow-bundle`

After creating the release commit and tag, run `make check-no-cursor-coauthor` again **before** `git push` (REQ-GIT-001). The release commit itself is not covered by an earlier `release-check` run.
