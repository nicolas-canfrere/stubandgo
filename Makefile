COMPOSER_VERSION = 2.10.3

COMPOSER = docker run --rm \
	--user $$(id -u):$$(id -g) \
	--volume $$PWD:/app \
	--workdir /app

.DEFAULT_GOAL := help

.PHONY: help install composer-cli up down console php-sh test crap mutation phpstan cs-check cs-fix lint test-db test-down deptrac ci

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*## "}; {printf "  %-14s %s\n", $$1, $$2}'

install: ## Install PHP dependencies with Composer
	$(COMPOSER) composer:$(COMPOSER_VERSION) install

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

cs-check: ## Check coding standards without modifying files
	docker compose -f compose.test.yaml run --rm php vendor/bin/php-cs-fixer check --diff

cs-fix: ## Fix coding standards
	docker compose -f compose.test.yaml run --rm php vendor/bin/php-cs-fixer fix

lint: ## Fix coding standards, then run PHPStan
	$(MAKE) cs-fix
	$(MAKE) phpstan

ci: cs-check phpstan deptrac test crap mutation ## Run every quality gate
