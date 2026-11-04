# Generator examples

PHP 8.5 playground for the generators article. One FrankenPHP container, nothing else.

## Articles

The articles are written in French. They use two examples to show when PHP
generators are worth it: streaming several LLM responses at once (SSE over
`curl_multi`), and a CSV ETL pipeline built on a small coroutine scheduler
(tasks, bounded channels, back-pressure, deadlock detection).

The main article comes in two versions, so both can be compared:

| Article | Content | Reading time |
|---------|---------|--------------|
| [ARTICLE.md](ARTICLE.md) | Short version of the main article. Generator basics and the scheduler are condensed and moved to the two companion articles below. | ~30 min |
| [ARTICLE_full.md](ARTICLE_full.md) | Full version of the main article, as it was before condensing: generator basics and the complete scheduler construction included. | ~45 min |

Companion articles, linked from the short version:

| Article | Content | Reading time |
|---------|---------|--------------|
| [ARTICLE_rappels.md](ARTICLE_rappels.md) | Generator basics: lazy evaluation, `send()` and `throw()`, `yield from`, cleanup with `finally`, and an array vs generator memory benchmark. | ~10 min |
| [ARTICLE_ordonnanceur.md](ARTICLE_ordonnanceur.md) | Building the coroutine scheduler step by step: channels, tasks, scheduler code, turn-by-turn traces, end of stream and deadlock detection. | ~20 min |

## Setup

```sh
cp .env.example .env   # then set your UID/GID and API keys
make build             # reads UID/GID from .env so files stay yours
make install           # composer install
make up                # start the container on http://localhost:8080
```

Other targets: `make down` stops the container, `make sh` opens a shell in it.

### Environment variables

Defined in `.env` (git-ignored), passed to the container by `compose.yaml`:

| Variable             | Used by                                          |
|----------------------|--------------------------------------------------|
| `UID`, `GID`         | image build, so bind-mounted files keep your ownership |
| `INTERNAL_BASE_URL`  | `DemoProvider`, base URL as seen from inside the container |
| `ANTHROPIC_API_KEY`  | Anthropic providers in `ProviderRegistry`        |
| `OPENROUTER_API_KEY` | OpenRouter provider in `ProviderRegistry`        |

The API keys are only needed for the streaming pages that call a real provider.

## Usage

- Web: put `example-<name>.php` in `public/`, open
  <http://localhost:8080/example-<name>.php>. <http://localhost:8080/> lists
  every `public/example*.php` file. Files are bind-mounted, so no rebuild on edit.
- Classes: `src/` is autoloaded as `App\` (PSR-4). A new namespace root means
  editing `composer.json`, then `docker compose run --rm php composer dump-autoload`.
- Composer: `docker compose run --rm php composer require <pkg>`

Errors are displayed and opcache is off — see `docker/php.ini`.

## CLI commands

`bin/console <name>` runs `cli/<name>.php`. It is a plain argv dispatcher, not a
command framework (no symfony/console): a thin `require` with a usage message.
It loads `vendor/autoload.php`, so `use App\...;` works inside any `cli/*.php`
file. Add a command by dropping a new `cli/<name>.php` file.

Run commands through `make console`. Everything in `script` is passed to
`bin/console`, so arguments go inside the quotes:

```sh
make console                                   # lists available commands
make console script=fibonacci
make console script="benchmark --repetitions=10 1000 10000"
```

### Available commands

| Command                              | What it does |
|--------------------------------------|--------------|
| `fibonacci [terms=100]`              | Prints Fibonacci terms from a generator. |
| `generate-orders [rows=1000]`        | Writes `rows` sample orders to `var/orders.csv`. |
| `etl [expectedRows=1000]`            | Scenarios for the ETL reader over `var/orders.csv`. |
| `channel`                            | Self-checks for `App\Pipeline\Channel`. |
| `scheduler`                          | Producer/consumer scenarios on the cooperative `Scheduler`. |
| `pipeline [keep]`                    | Full read → transform → batch → write pipeline over `var/orders.csv`, prints peak memory. With `keep`, batches are kept in memory instead of written. |
| `benchmark [options] [sizes...]`     | Array vs generator, time and memory. See below. |

`etl` and `pipeline` read `var/orders.csv`, so run `generate-orders` first.
`etl` expects the same row count that `generate-orders` wrote:

```sh
make console script="generate-orders 5000"
make console script="etl 5000"
make console script="pipeline keep"
```

### Benchmark: array vs generator

```sh
make console script=benchmark                                          # default sizes, no memory limit
make console script="benchmark --memory-limit=256M 500000 1000000"
make console script="benchmark --repetitions=10 1000 10000"
```

`--memory-limit` applies to each measurement process, so a run that exhausts
it is reported as `FAILED` in the table instead of stopping the whole benchmark.

`--repetitions` sets how many runs each median is computed over (default 5,
allowed 2 to 30).

## Code quality

PHPStan (level `max`, `phpstan.dist.neon`) and PHP CS Fixer (`@Symfony` +
`@Symfony:risky` rule sets, `.php-cs-fixer.dist.php`) run inside the container.
Caches go to `var/cache/` (git-ignored).

```sh
make phpstan     # static analysis
make cs-check    # show style violations as a diff, change nothing
make cs-fix      # apply the fixes
```
