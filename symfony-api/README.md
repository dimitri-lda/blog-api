# symfony-api

Symfony 8.1 port of the daoSport storefront backend. It exposes a JSON API
(`POST`/`GET`/`PATCH`/`PUT`/`DELETE` under `/api/v1`) consumed by the React SPA
in `frontend/`, served behind Caddy, backed by PostgreSQL 18 and Redis.

## Status

This application is **complete enough to run the full storefront flow**
(catalog, cart, checkout, Shop Pay, account, admin orders, password reset)
but the Laravel application in the repository root **has not been removed or
cut over**. Both applications can run side by side; nothing routes production
traffic to this app yet. Treat all URLs here as internal until the cut-over
runbook in `docs/data-migration.md` is executed.

## Stack

- PHP `>=8.4` (Docker image `php:8.4-fpm-alpine`), Symfony FrameworkBundle
  `8.1.*`, SecurityBundle `8.1.*`, Messenger + Scheduler `8.1.*`, Mailer `8.1.*`.
- Doctrine DBAL 4 for all persistence (migrations via
  doctrine-migrations-bundle; no ORM entities, `auto_mapping` disabled).
- PostgreSQL 18 (`postgres:18-alpine`), Redis 8 (`redis:8-alpine`,
  `ext-redis` 6.3.0) for cache pools, session storage, locks and transports.
- Caddy 2.10: static SPA from `frontend/dist` with `/index.html` fallback,
  FastCGI proxy for `/api/*` and `/healthz` to `php:9000`.
- React 18.3 + Vite 8 SPA (`frontend/`), TanStack Query, React Router 7,
  Tailwind 3. No Next.js, no Inertia, no API Platform, no TypeScript.
- PHPUnit 12 functional/integration suite, DB-gated by `RUN_DB_TESTS=1`.

## Prerequisites (local, without Docker)

- PHP >=8.4 with `ext-pdo_pgsql`, `ext-intl`, `ext-mbstring`, `ext-redis`,
  `ext-sodium`, `ext-openssl`.
- Composer 2.
- Node 20+ and npm (for `frontend/`).
- PostgreSQL 18 and Redis 8 reachable via `DATABASE_URL`/`REDIS_URL`.

Or use the provided `compose.yaml` (`php` + `caddy` + `pgsql` + `redis`),
which supplies all extensions inside the `php` image.

## Install

```bash
cd symfony-api
cp .env.example .env           # ignored by git; fill secrets before Composer scripts
composer install
cd frontend && npm ci          # exact pins in package-lock.json
```

## Run (Compose)

> **Volume warning:** `compose.yaml` deliberately defaults
> `POSTGRES_VOLUME_NAME`/`REDIS_VOLUME_NAME` to `blog-api_sail-pgsql` /
> `blog-api_sail-redis` — the *legacy* Laravel stack's named volumes. For a
> clean side-by-side dev stack, set fresh volume names (and non-conflicting
> forwarded ports if the old stack is up) **before** the first
> `docker compose up`; do not attach or migrate the legacy volumes during
> initial setup:

```bash
export POSTGRES_VOLUME_NAME=sf-pgsql REDIS_VOLUME_NAME=sf-redis
export APP_PORT=8080 FORWARD_DB_PORT=55432 FORWARD_REDIS_PORT=56379
docker compose up -d           # builds php image, starts caddy/pgsql/redis
docker compose exec php php bin/console doctrine:migrations:migrate --no-interaction
cd frontend && npm run build   # produce frontend/dist served by Caddy
```

The API answers on `http://localhost:8080` (`/healthz`, `/api/v1/*`) and the
SPA is served from `frontend/dist` via the `symfony-api` network. For dev,
`npm run dev` serves the SPA on port 5173 with `/api` + `/healthz` proxied
to `VITE_API_ORIGIN` (default `http://localhost:8080`).

## Test

```bash
php bin/phpunit                                       # unit suite (no DB)
RUN_DB_TESTS=1 DATABASE_URL=... php bin/phpunit       # full suite incl. DB tests
php bin/console lint:container
php bin/console lint:yaml config openapi
```

In `APP_ENV=test`, doctrine appends `_test` to the database name
(`dbname_suffix`), cache pools become array adapters, `framework.lock` uses
`flock`, and sessions use the mock-file handler — no Redis is needed to run
the suite on a host without Redis/ext-redis.

## Directory map

```text
symfony-api/
  bin/console, bin/phpunit       entry points
  config/                        packages, services, routes, bundles
  docker/caddy/Caddyfile         SPA static + /api FastCGI rules
  docs/                          architecture, api, caching, migration, ops
  frontend/                      React/Vite SPA (own package.json)
  migrations/                    ...000000 fresh-schema baseline +
                                 ...001000 idempotent legacy compatibility upgrade
  openapi/openapi.yaml           OpenAPI 3.1 contract for /api/v1
  public/index.php               HTTP front controller
  src/Domain/                    order/payment/user value objects, no IO
  src/Application/               use cases (catalog, cart, orders, payments,
                                 security, commerce pricing/exchange rates)
  src/Infrastructure/            DBAL persistence, cache, crypto, HTTP,
                                 localization, payments, scheduling, security
  src/Presentation/Http/         controllers, ApiCsrfSubscriber,
                                 ApiExceptionSubscriber, health
  src/Presentation/Console/      storefront/payments/commerce commands
  tests/                         Unit/, Integration/, Functional/
  translations/store.yaml        storefront translation catalog (en/ru/pl)
```

## Further reading

- `docs/architecture.md` — layering, security model, data flow.
- `docs/api.md` — every route, envelopes, CSRF lifecycle, authz rules.
- `docs/caching.md` — generation cache, SWR, pools, invalidation, commands.
- `docs/data-migration.md` — cut-over from Laravel, re-encryption, rollback.
- `docs/operations.md` — env inventory, workers, scheduler, deploy checklist.
- `openapi/openapi.yaml` — machine-readable API contract.
