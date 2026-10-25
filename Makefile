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

mutation: test-db ## Run mutation testing with Infection, failing if the test database logged a FATAL
	@since=$$(date -u +%Y-%m-%dT%H:%M:%SZ); \
	docker compose -f compose.test.yaml run --rm -e XDEBUG_MODE=coverage php vendor/bin/infection --threads=max; status=$$?; \
	fatals=$$(docker compose -f compose.test.yaml logs --no-log-prefix --since "$$since" database | grep FATAL); \
	if [ -n "$$fatals" ]; then echo "The test database logged FATAL errors during mutation testing:"; echo "$$fatals"; exit 1; fi; \
	echo "No FATAL logged by the test database since $$since."; \
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
	docker volume rm -f $$(docker compose config --format json | jq -r '.name')_keycloak_data

ci: cs-check phpstan deptrac lint-container openapi-check openapi-lint test crap mutation ## Run every quality gate
