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
