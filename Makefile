COMPOSER_VERSION = 2.10.3

COMPOSER = docker run --rm \
	--user $$(id -u):$$(id -g) \
	--volume $$PWD:/app \
	--workdir /app

.PHONY: install composer-cli up down

install:
	$(COMPOSER) composer:$(COMPOSER_VERSION) install

composer-cli:
	$(COMPOSER) --interactive --tty composer:$(COMPOSER_VERSION) sh

up:
	docker compose up -d

down:
	docker compose down
