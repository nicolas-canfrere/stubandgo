COMPOSER_VERSION = 2.10.3
REDOCLY_VERSION = 2.62.1

COMPOSER = docker run --rm \
	--user $$(id -u):$$(id -g) \
	--volume $$PWD:/app \
	--workdir /app

.DEFAULT_GOAL := help

.PHONY: help install composer-cli up down console php-sh test crap mutation phpstan cs-check cs-fix lint test-db test-down deptrac lint-container openapi openapi-check openapi-lint keycloak-check keycloak-reset ci

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "}; {printf "  %-14s %s\n", $$1, $$2}'

install: ## Install PHP dependencies with Composer
	$(COMPOSER) composer:$(COMPOSER_VERSION) install --ignore-platform-reqs

composer-cli: ## Open a shell in the Composer container
	$(COMPOSER) --interactive --tty composer:$(COMPOSER_VERSION) sh

up: ## Start the dev stack (nginx + php)
	docker compose up -d

down: ## Stop and remove the dev stack
	docker compose down

console: ## Run a Symfony console command (make console CMD="debug:router")
	docker compose run --rm php bin/console $(CMD)

php-sh: ## Open a shell in the PHP container
	docker compose run --rm php sh

test-db: ## Create the test database and run migrations
	docker compose -f compose.test.yaml run --rm php bin/console doctrine:database:create --if-not-exists --env=test
	docker compose -f compose.test.yaml run --rm php bin/console doctrine:migrations:migrate -n --allow-no-migration --env=test

test-down: ## Stop and remove the test stack (php + database)
	docker compose -f compose.test.yaml down

test: test-db ## Run the PHPUnit test suite
	docker compose -f compose.test.yaml run --rm php bin/phpunit

crap: test-db ## Run PHPUnit with coverage and fail on any method with CRAP >= 9
	docker compose -f compose.test.yaml run --rm php bin/phpunit --coverage-crap4j var/coverage/crap4j.xml
	docker compose -f compose.test.yaml run --rm php bin/crap-check var/coverage/crap4j.xml 9

# Infection threads ("max" = CPU count - 1); each thread N tests against its own app_testN database.
MUTATION_THREADS ?= $(shell n=$$(nproc); [ "$$n" -gt 1 ] && echo $$((n - 1)) || echo 1)

mutation: test-db ## Run mutation testing with Infection, failing on a FATAL of the test database or any skipped mutant
	@sql=""; for i in $$(seq 1 $(MUTATION_THREADS)); do \
		sql="$$sql DROP DATABASE IF EXISTS app_test$$i WITH (FORCE); CREATE DATABASE app_test$$i TEMPLATE app_test;"; \
	done; \
	echo "$$sql" | docker compose -f compose.test.yaml exec -T database psql -q -v ON_ERROR_STOP=1 -U app -d postgres || exit 1; \
	echo "Cloned app_test into app_test1..app_test$(MUTATION_THREADS), one database per Infection thread."
	@since=$$(date -u +%Y-%m-%dT%H:%M:%SZ); rm -f var/infection/summary.json; \
	docker compose -f compose.test.yaml run --rm -e XDEBUG_MODE=coverage php vendor/bin/infection --threads=$(MUTATION_THREADS); status=$$?; \
	fatals=$$(docker compose -f compose.test.yaml logs --no-log-prefix --since "$$since" database | grep FATAL); \
	if [ -n "$$fatals" ]; then echo "The test database logged FATAL errors during mutation testing:"; echo "$$fatals"; exit 1; fi; \
	echo "No FATAL logged by the test database since $$since."; \
	skipped=$$(grep -o '"skippedCount":[0-9]*' var/infection/summary.json | cut -d: -f2); \
	if [ "$$skipped" != "0" ]; then echo "Infection skipped $${skipped:-an unknown number of} mutants (\"required more time than configured\"): the MSI is hollow."; exit 1; fi; \
	echo "No mutant skipped by Infection."; \
	exit $$status

phpstan: ## Run PHPStan static analysis
	docker compose -f compose.test.yaml run --rm php bin/console cache:warmup
	docker compose -f compose.test.yaml run --rm php vendor/bin/phpstan analyse

deptrac: ## Check architecture dependencies with Deptrac
	docker compose -f compose.test.yaml run --rm php vendor/bin/deptrac analyse --fail-on-uncovered --report-uncovered

lint-container: ## Lint the production service container (aliases the test environment redefines)
	docker compose run --rm --no-deps -e APP_CACHE_DIR=/tmp/lint-container-cache -e APP_LOG_DIR=/tmp/lint-container-log php bin/console lint:container --env=prod --no-debug

cs-check: ## Check coding standards without modifying files
	docker compose -f compose.test.yaml run --rm php vendor/bin/php-cs-fixer check --diff

cs-fix: ## Fix coding standards
	docker compose -f compose.test.yaml run --rm php vendor/bin/php-cs-fixer fix

lint: ## Fix coding standards, then run PHPStan
	$(MAKE) cs-fix
	$(MAKE) phpstan

openapi: ## Regenerate openapi.yaml from the NelmioApiDocBundle documentation
	@tmp=$$(mktemp); err=$$(mktemp); trap 'rm -f "$$tmp" "$$err"' EXIT; \
	docker compose run --rm -T --no-deps php bin/console nelmio:apidoc:dump --format=yaml > "$$tmp" 2> "$$err" || { echo "The OpenAPI dump failed:"; cat "$$err"; exit 1; }; \
	cat "$$tmp" > openapi.yaml

openapi-check: ## Fail if openapi.yaml is not up to date with the code
	@tmp=$$(mktemp); err=$$(mktemp); trap 'rm -f "$$tmp" "$$err"' EXIT; \
	docker compose run --rm -T --no-deps php bin/console nelmio:apidoc:dump --format=yaml > "$$tmp" 2> "$$err" || { echo "The OpenAPI dump failed:"; cat "$$err"; exit 1; }; \
	if ! diff -u openapi.yaml "$$tmp"; then echo "openapi.yaml is out of date: run 'make openapi' and commit the result."; exit 1; fi; \
	echo "openapi.yaml is up to date."

openapi-lint: ## Lint openapi.yaml with Redocly CLI
	docker run --rm --user $$(id -u):$$(id -g) --volume $$PWD:/spec --workdir /spec redocly/cli:$(REDOCLY_VERSION) lint openapi.yaml

keycloak-check: ## Fail unless Keycloak announces the public issuer of both realms (stack must be up)
	docker compose run --rm -T --no-deps php php docker/keycloak-check.php

keycloak-reset: ## Drop Keycloak and its data volume: the next "make up" re-imports the realm exports
	docker compose rm -sf keycloak
	@project=$$(docker compose config | sed -n 's/^name: //p'); \
	test -n "$$project" || { echo "keycloak-reset: cannot resolve the compose project name" >&2; exit 1; }; \
	volume=$$(docker volume ls -q --filter "label=com.docker.compose.project=$$project" --filter "label=com.docker.compose.volume=keycloak_data"); \
	test -n "$$volume" || { echo "keycloak-reset: no keycloak_data volume found for project $$project" >&2; exit 1; }; \
	docker volume rm $$volume

ci: cs-check phpstan deptrac lint-container openapi-check openapi-lint test crap mutation ## Run every quality gate
