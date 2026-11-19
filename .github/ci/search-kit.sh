#!/usr/bin/env bash
# CI helper (since 1.21.0): SearchKit (nowo-tech/search-kit-bundle >= 1.9, PHP >= 8.3) is an optional dependency of the
# SearchKit group "workflow_requests" (src/Bridge/SearchKit). It is not in composer.json or composer.lock: the bundle
# supports PHP 8.2 and the PHP 8.2 jobs (and composer.json's platform 8.2.0) could not resolve it.
#
# On PHP >= 8.3 this adds it to require-dev (without updating), sets the platform to the running PHP and exports
# SEARCH_KIT_PACKAGES for the job's `composer update` (with --install it runs that update itself). On PHP 8.2 it does
# nothing: the group is not registered, its tests are skipped and PHPStan leaves it out (phpstan-search-kit.php).
set -euo pipefail

CONSTRAINT="${SEARCH_KIT_CONSTRAINT:-^1.9}"
PACKAGE="nowo-tech/search-kit-bundle"

if ! php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);'; then
  echo "PHP $(php -r 'echo PHP_VERSION;') < 8.3: SearchKit is not installed; the SearchKit group is skipped"
  exit 0
fi

echo "Adding ${PACKAGE} ${CONSTRAINT} to require-dev (PHP $(php -r 'echo PHP_VERSION;'))"
composer config platform.php "$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION . ".99";')"
composer require --dev --no-update --no-interaction "${PACKAGE}:${CONSTRAINT}"

if [ -n "${GITHUB_ENV:-}" ]; then
  echo "SEARCH_KIT_PACKAGES=${PACKAGE}" >> "${GITHUB_ENV}"
fi
if [ "${1:-}" = "--install" ]; then
  composer update "${PACKAGE}" --with-all-dependencies --no-interaction --prefer-dist --no-progress
fi
