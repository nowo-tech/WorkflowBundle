.PHONY: help up down build install shell logs test test-coverage cache-clear reset-demo link-bundle update-bundle ensure-up restart release-check

help:
	@echo "Symfony 8 Demo - Workflow Bundle"

up:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	docker-compose build
	docker-compose up -d
	@echo "Waiting for database..."
	@sleep 8
	@echo "Installing dependencies..."
	@docker-compose exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer update --no-interaction
	@$(MAKE) link-bundle
	@docker-compose exec -T php php bin/console nowo:workflow:sync-schema
	@docker-compose exec -T php php bin/console doctrine:schema:update --force --no-interaction
	@$(MAKE) reset-demo
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	echo "Demo started at: http://localhost:$$PORT"

down:
	docker-compose down

restart: ensure-up
	docker-compose restart

build:
	docker-compose build --no-cache

ensure-up:
	@if ! docker-compose exec -T php true 2>/dev/null; then docker-compose up -d; sleep 3; fi

install: ensure-up
	docker-compose exec -T -e COMPOSER_MEMORY_LIMIT=-1 php composer install --no-interaction

shell: ensure-up
	docker-compose exec php sh

logs:
	docker-compose logs -f

test: ensure-up
	docker-compose exec -e APP_ENV=test php composer test

test-coverage: ensure-up
	docker-compose exec php composer test-coverage

cache-clear: ensure-up
	docker-compose exec php php bin/console cache:clear

reset-demo: ensure-up
	docker-compose exec -T php php bin/console nowo:workflow:seed-demo --fresh --no-interaction

link-bundle: ensure-up
	docker-compose exec php sh -c "rm -rf vendor/nowo-tech/workflow-bundle && ln -s /var/workflow-bundle vendor/nowo-tech/workflow-bundle && php bin/console cache:clear"

update-bundle: ensure-up
	docker-compose exec php composer update nowo-tech/workflow-bundle -W --no-interaction
	docker-compose exec php sh -c "php bin/console cache:clear --env=dev"

release-check: ensure-up link-bundle test test-coverage

# REQ-MAKE-008: update-deps (REQ-MAKE-008)
COMPOSE := docker-compose
SERVICE_PHP := php
include $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../../../.scripts/Makefile.demo-update-deps.mk)
