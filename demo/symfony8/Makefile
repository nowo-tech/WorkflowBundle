.PHONY: help up down build install shell logs test test-coverage cache-clear reset-demo link-bundle update-bundle ensure-up restart release-check setup verify test-e2e demo-screenshots

help:
	@echo "Symfony 8 Demo - Workflow Bundle"
	@echo "  up down restart build ensure-up install shell"
	@echo "  setup reset-demo link-bundle update-bundle"
	@echo "  test test-coverage verify release-check"
	@echo "  test-e2e demo-screenshots (Playwright, REQ-DEMO-013)"

up:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	$(COMPOSE) build
	$(COMPOSE) up -d
	@echo "Waiting for database..."
	@sleep 8
	@echo "Installing dependencies..."
	@$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer update --no-interaction
	@$(MAKE) link-bundle
	@$(COMPOSE) exec -T php php bin/console nowo:workflow:sync-schema
	@$(COMPOSE) exec -T php php bin/console doctrine:schema:update --force --no-interaction
	@$(MAKE) reset-demo
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	echo "Demo started at: http://localhost:$$PORT"

down:
	$(COMPOSE) down

restart: ensure-up
	$(COMPOSE) restart

build:
	$(COMPOSE) build --no-cache

ensure-up:
	@if ! $(COMPOSE) exec -T php true 2>/dev/null; then $(COMPOSE) up -d; sleep 3; fi

install: ensure-up
	$(COMPOSE) exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction

shell: ensure-up
	$(COMPOSE) exec php sh

logs:
	$(COMPOSE) logs -f

test: ensure-up
	$(COMPOSE) exec -e APP_ENV=test php composer test

test-coverage: ensure-up
	$(COMPOSE) exec php composer test-coverage

cache-clear: ensure-up
	$(COMPOSE) exec php php bin/console cache:clear

setup: ensure-up
	@$(COMPOSE) exec -T php php bin/console nowo:workflow:sync-schema
	@$(COMPOSE) exec -T php php bin/console doctrine:schema:update --force --no-interaction
	@$(MAKE) reset-demo

reset-demo: ensure-up
	$(COMPOSE) exec -T php php bin/console nowo:workflow:seed-demo --fresh --no-interaction

link-bundle: ensure-up
	$(COMPOSE) exec php sh -c "rm -rf vendor/nowo-tech/workflow-bundle && ln -s /var/workflow-bundle vendor/nowo-tech/workflow-bundle && php bin/console cache:clear"

update-bundle: ensure-up
	$(COMPOSE) exec -T php composer update nowo-tech/workflow-bundle -W --no-interaction
	$(COMPOSE) exec -T php sh -c "php bin/console cache:clear --env=dev"

# REQ-DEMO-007: sync the bundle code (composer path repo + cache clear) before the tests.
release-check: ensure-up update-bundle test test-coverage

verify: ensure-up
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=8022; \
	code=$$(curl -fsS -o /dev/null -w "%{http_code}" "http://localhost:$$PORT/" || true); \
	if [ "$$code" != "200" ]; then echo "verify failed: HTTP $$code"; exit 1; fi; \
	echo "verify OK (HTTP 200)"

# REQ-MAKE-008: update-deps (REQ-MAKE-008)
# Prefer Compose V2 plugin (GitHub Actions / modern Docker Desktop); fall back to docker-compose V1 (REQ-MAKE-010).
COMPOSE := $(shell docker compose version >/dev/null 2>&1 && echo "docker compose" || echo "docker-compose")
SERVICE_PHP := php
# Optional: monorepo helper absent on standalone GitHub Actions checkout (REQ-MAKE-009).
-include $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../../../.scripts/Makefile.demo-update-deps.mk)

# REQ-DEMO-013: Playwright e2e + WebP screenshots (official Playwright image; run reset-demo first).
PLAYWRIGHT_IMAGE ?= mcr.microsoft.com/playwright:v1.64.0-jammy

test-e2e: ensure-up
	@mkdir -p e2e/node_modules
	@PORT=$$(grep -E '^PORT=' .env 2>/dev/null | cut -d= -f2); PORT=$${PORT:-8022}; \
	docker run --rm --network host \
		-v "$(CURDIR)/e2e:/e2e" -w /e2e \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm install --no-fund --no-audit && npx playwright test --project=chromium'

demo-screenshots: ensure-up
	@mkdir -p ../../docs/images/demo e2e/node_modules
	@PORT=$$(grep -E '^PORT=' .env 2>/dev/null | cut -d= -f2); PORT=$${PORT:-8022}; \
	docker run --rm --network host \
		-v "$(CURDIR)/e2e:/e2e" \
		-v "$(CURDIR)/../../docs/images/demo:/screenshots" \
		-w /e2e \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		-e SCREENSHOT_DIR=/screenshots \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm install --no-fund --no-audit && npx playwright test --project=screenshots && node scripts/to-webp.mjs /screenshots'
	@echo "Screenshots written under docs/images/demo/ (WebP)"
