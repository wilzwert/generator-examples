# Generator examples

PHP 8.5 playground for the generators article. One FrankenPHP container, nothing else.

## Setup

```sh
docker compose build          # reads UID/GID from .env so files stay yours
docker compose run --rm php composer install
docker compose up -d
```

## Usage

- Web: put `exampleX.php` in `public/`, open <http://localhost/exampleX.php>.
  <http://localhost/> lists them. Files are bind-mounted, so no rebuild on edit.
- Classes: `src/` is autoloaded as `App\` (PSR-4). New namespace root means
  editing `composer.json` then `docker compose run --rm php composer dump-autoload`.
- CLI: `docker compose exec php php script.php`
  (or `docker compose exec php php -r 'require "vendor/autoload.php"; ...'`)
- Composer: `docker compose run --rm php composer require <pkg>`

Errors are displayed and opcache is off — see `docker/php.ini`.

## CLI commands

`bin/console <name>` runs `cli/<name>.php`. It's a plain argv dispatcher, not a
command framework (no symfony/console) — just a thin `require` with a usage
message. It still loads `vendor/autoload.php`, so `use App\...;` works inside
any `cli/*.php` file like it does on the web side.

```sh
docker compose exec php php bin/console example1
docker compose exec php php bin/console          # lists available commands
```

Add a new command by dropping a `cli/<name>.php` file; `use App\...;` works
inside it like any other PSR-4-autoloaded class.

### Benchmark: array vs generator

```sh
docker compose exec php bin/console benchmark                              # default sizes, no memory limit
docker compose exec php bin/console benchmark --memory-limit=256M 500000 1000000
```

`--memory-limit` applies to each measurement process, so a run that exhausts
it is reported as `FAILED` in the table instead of stopping the whole benchmark.

`--repetitions` sets how many runs each median is computed over (default 5,
allowed 2 to 30).

```sh
docker compose exec php bin/console benchmark --repetitions=10 1000 10000
```

## Code quality

PHPStan (level `max`, `phpstan.dist.neon`) and PHP CS Fixer (`@Symfony` +
`@Symfony:risky` rule sets, `.php-cs-fixer.dist.php`) run inside the container.
Caches go to `var/cache/` (git-ignored).

```sh
make phpstan     # static analysis
make cs-check    # show style violations as a diff, change nothing
make cs-fix      # apply the fixes
```
