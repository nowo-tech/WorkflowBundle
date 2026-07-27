DEMOS := symfony8

.PHONY: help up down update-bundle test test-coverage release-check release-verify \
	up-symfony8 down-symfony8 install-symfony8 shell-symfony8 \
	test-symfony8 test-coverage-symfony8 update-bundle-symfony8

help:
	@echo "Workflow Bundle — Demos"
	@echo ""
	@echo "Symfony 8 (FrankenPHP):"
	@echo "  up-symfony8 down-symfony8 install-symfony8 shell-symfony8"
	@echo "  test-symfony8 test-coverage-symfony8 update-bundle-symfony8"
	@echo ""
	@echo "Aliases: up down update-bundle test test-coverage (REQ-MAKE-003)"
	@echo "  release-verify       up → HTTP smoke → down (REQ-TEST-011)"
	@echo "  release-check        Same as release-verify"
	@echo "  update-deps-all      composer update in all demos (REQ-MAKE-008)"

up-symfony8:
	@$(MAKE) -C symfony8 up

down-symfony8:
	@$(MAKE) -C symfony8 down

install-symfony8:
	@$(MAKE) -C symfony8 install

shell-symfony8:
	@$(MAKE) -C symfony8 shell

test-symfony8:
	@$(MAKE) -C symfony8 test

test-coverage-symfony8:
	@$(MAKE) -C symfony8 test-coverage

update-bundle-symfony8:
	@$(MAKE) -C symfony8 update-bundle

up:
	@$(MAKE) -C symfony8 up

down:
	@$(MAKE) -C symfony8 down

update-bundle:
	@$(MAKE) -C symfony8 update-bundle

test:
	@$(MAKE) -C symfony8 test

test-coverage:
	@$(MAKE) -C symfony8 test-coverage

# REQ-TEST-011: boot demo + one successful HTTP response
release-verify:
	@for demo in $(DEMOS); do \
		port=$$(grep '^PORT=' $$demo/.env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
		[ -z "$$port" ] && port=$$(grep '^PORT=' $$demo/.env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
		[ -z "$$port" ] && port=8022; \
		echo "Release verify: $$demo (port $$port)..."; \
		$(MAKE) up-$$demo; \
		code=000; \
		for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do \
			code=$$(curl -s -o /dev/null -w "%{http_code}" "http://127.0.0.1:$$port/" 2>/dev/null || echo "000"); \
			echo "$$code" | grep -qE '^[23]' && break; \
			echo "  release-verify: waiting for $$demo... $$i/15 (HTTP $$code)"; \
			sleep 2; \
		done; \
		$(MAKE) down-$$demo 2>/dev/null || true; \
		if ! echo "$$code" | grep -qE '^[23]'; then echo "$$demo healthcheck failed (HTTP $$code)"; exit 1; fi; \
		echo "OK $$demo (HTTP $$code)"; \
	done
	@echo "All demos passed release-verify."

release-check: release-verify
	@echo "All demos passed release-check."

# REQ-MAKE-008: update-deps-all (REQ-MAKE-008)
DEMO_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST))))
BUNDLE_ROOT := $(abspath $(DEMO_ROOT)/..)
include $(abspath $(BUNDLE_ROOT)/../.scripts/Makefile.demo-aggregate-update-deps.mk)
