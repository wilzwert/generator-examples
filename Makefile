.PHONY: build install up down sh

build:
	docker compose build

install:
	docker compose run --rm php composer install

up:
	docker compose up -d

down:
	docker compose down

sh:
	docker compose exec php sh

.PHONY: console

console:
	docker compose exec php bin/console $(script)

.PHONY: phpstan cs-check cs-fix

phpstan:
	docker compose run --rm php composer phpstan

cs-check:
	docker compose run --rm php composer cs-check

cs-fix:
	docker compose run --rm php composer cs-fix
