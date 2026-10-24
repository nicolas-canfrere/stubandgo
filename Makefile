COMPOSER_VERSION = 2.10.3

COMPOSER = docker run --rm \
	--user $$(id -u):$$(id -g) \
	--volume $$PWD:/app \
	--workdir /app

.DEFAULT_GOAL := help

.PHONY: help install composer-cli up down test mutation phpstan cs-check cs-fix lint

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

test: ## Run the PHPUnit test suite
	docker compose -f compose.test.yaml run --rm php bin/phpunit

mutation: ## Run mutation testing with Infection
	docker compose -f compose.test.yaml run --rm -e XDEBUG_MODE=coverage php vendor/bin/infection --threads=max

phpstan: ## Run PHPStan static analysis
	docker compose -f compose.test.yaml run --rm php bin/console cache:warmup
	docker compose -f compose.test.yaml run --rm php vendor/bin/phpstan analyse

cs-check: ## Check coding standards without modifying files
	docker compose -f compose.test.yaml run --rm php vendor/bin/php-cs-fixer check --diff

cs-fix: ## Fix coding standards
	docker compose -f compose.test.yaml run --rm php vendor/bin/php-cs-fixer fix

lint: ## Fix coding standards, then run PHPStan
	$(MAKE) cs-fix
	$(MAKE) phpstan
