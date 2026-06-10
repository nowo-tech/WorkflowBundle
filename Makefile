# Makefile for Workflow Bundle

COMPOSE_FILE := docker-compose.yml
COMPOSE     := docker-compose -f $(COMPOSE_FILE)
SERVICE_PHP := php

.PHONY: help up down build shell install assets test test-coverage cs-check cs-fix qa clean release-check release-check-demos composer-sync rector rector-dry phpstan update validate

help:
	@echo "Workflow Bundle - Development Commands"
	@echo ""
	@echo "Demos: make -C demo up-symfony8"

build:
	$(COMPOSE) build --no-cache

up:
	$(COMPOSE) build
	$(COMPOSE) up -d
	@echo "Installing dependencies..."
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction
	@echo "Container ready."

down:
	$(COMPOSE) down

shell:
	$(COMPOSE) exec $(SERVICE_PHP) sh

install: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer install

ensure-up:
	@if ! $(COMPOSE) exec -T $(SERVICE_PHP) true 2>/dev/null; then \
		echo "Starting container..."; \
		$(COMPOSE) up -d; \
		sleep 3; \
		$(COMPOSE) exec -T $(SERVICE_PHP) composer install --no-interaction; \
	fi

test: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) composer test

test-coverage: ensure-up
	$(COMPOSE) exec $(SERVICE_PHP) composer test-coverage | tee coverage-php.txt
	./.scripts/php-coverage-percent.sh coverage-php.txt

cs-check: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-check

cs-fix: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer cs-fix

rector: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector

rector-dry: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer rector-dry

phpstan: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer phpstan

qa: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer qa

update: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-interaction

validate: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict

release-check: ensure-up composer-sync cs-fix cs-check rector-dry phpstan test-coverage release-check-demos

release-check-demos:
	@$(MAKE) -C demo release-verify

composer-sync: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) composer validate --strict
	$(COMPOSE) exec -T $(SERVICE_PHP) composer update --no-install

clean: ensure-up
	$(COMPOSE) exec -T $(SERVICE_PHP) sh -c "rm -rf vendor .phpunit.cache coverage coverage.xml coverage-php.txt .php-cs-fixer.cache"

assets:
	@echo "No frontend assets in this bundle."
