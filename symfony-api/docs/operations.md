# Operations

## Environment inventory

Set in `.env` / `.env.local` / platform secrets (see `.env.example`).

- **Runtime**: `APP_ENV`, `APP_SECRET`, `APP_SHARE_DIR`, `TRUSTED_PROXIES`,
  `TRUSTED_HOSTS`, `DEFAULT_URI`, `FRONTEND_URL`.
- **Database**: `DATABASE_URL` (PostgreSQL; `_test` suffix auto-appended
  in `APP_ENV=test`).
- **Redis / sessions**: `REDIS_URL`, `SESSION_COOKIE_NAME`
  (`storefront_session`), `SESSION_COOKIE_SECURE` (bool), `LOCK_DSN`
  (`flock` in test, `redis://` elsewhere).
- **Cache**: `STOREFRONT_CACHE_ENABLED` (bool).
- **Messenger**: `MESSENGER_TRANSPORT_DSN` (`async` transport),
  `MESSENGER_FAILED_TRANSPORT_DSN` (`failed` transport).
- **Mail**: `MAILER_DSN` (test: `null://null`), `MAILER_FROM`.
- **GeoIP**: `GEOIP_URL` (ipwho.is-compatible `{ip}` JSON template; TTLs
  are code constants — 30 d hit, 600 s miss).
- **Commerce**: `COMMERCE_DEFAULT_COUNTRY`. Markets, EU VAT table,
  locales, delivery prices and country names are parameters in
  `config/packages/commerce.yaml`, not env.
- **Exchange rates**: `MYFIN_EUR_BYN_URL`, `WALUTOMAT_EUR_PLN_URL`,
  `WALUTOMAT_API_TOKEN` (cache TTL 300 s / 6 h quote freshness bound in
  `services.yaml`).
- **Encryption**: `PAYMENT_ENCRYPTION_KEY` (`base64:` 32 bytes, sodium
  XChaCha20), `LARAVEL_LEGACY_APP_KEY` (legacy AES-256-CBC reads; keep
  until all rows are `v1:`).
- **Shop Pay**: `SHOP_PAY_ENABLED`, `SHOP_PAY_SHOP_DOMAIN`,
  `SHOP_PAY_API_VERSION`, `SHOP_PAY_SHOP_ID`, `SHOP_PAY_CLIENT_ID`,
  `SHOP_PAY_STOREFRONT_PRIVATE_TOKEN`, `SHOP_PAY_ADMIN_ACCESS_TOKEN`,
  `SHOP_PAY_APP_CLIENT_SECRET`, `SHOP_PAY_WEBHOOK_URL`.

Public surface: only `shopId` + `clientId` reach the order JSON;
storefront/admin/app secrets stay server-side.

## Docker topology

- `php` — `php:8.4-fpm-alpine` + composer + intl/mbstring/opcache/
  pdo_pgsql + PECL `redis-6.3.0`, `USER www-data`, `WORKDIR /srv/app`;
  `APP_ENV`/`APP_SECRET`/DSN env injected via compose `environment:`
  (not build args); mounts `.:/srv/app` plus anonymous `/srv/app/vendor`
  and `/srv/app/var` volumes; **no healthcheck**; `depends_on` healthy
  `pgsql` + `redis`.
- `caddy` — `caddy:2.10-alpine`, publishes `${APP_PORT:-8080}:80`,
  `depends_on php`. `docker/caddy/Caddyfile` (read-only mount,
  `auto_https off`, `:80`): `php_fastcgi php:9000` for `/api/*` +
  `/healthz` under `root /srv/app/public` (`./public` mount); everything
  else from `./frontend/dist` mounted at `/srv/frontend/dist` with
  `try_files {path} /index.html`. `caddy-data` + `caddy-config` volumes.
- `pgsql` — `postgres:18-alpine`, `${FORWARD_DB_PORT:-5432}:5432`,
  `POSTGRES_DB/USER/PASSWORD` envs (default `app`), `pg_isready`
  healthcheck, `pgsql-data` volume (external name
  `${POSTGRES_VOLUME_NAME:-blog-api_sail-pgsql}`).
- `redis` — `redis:8-alpine`, `${FORWARD_REDIS_PORT:-6379}:6379`,
  `redis-cli ping` healthcheck, `redis-data` volume (external name
  `${REDIS_VOLUME_NAME:-blog-api_sail-redis}`).
- Network `symfony-api` (bridge). No service sets a restart policy in
  the dev compose file — set one in your prod overlay.
- The `blog-api_sail-*` volume-name defaults intentionally point at the
  legacy stack's volumes; override `POSTGRES_VOLUME_NAME`/
  `REDIS_VOLUME_NAME` before first `up` for a clean side-by-side stack
  (see README).

## Worker / scheduler processes

Scheduler `default` (`StoreSchedule`, stateful on `cache.app`,
`processOnlyLastMissedRun`) fires:

| Cron | Command |
|---|---|
| `0 * * * *` | `commerce:refresh-exchange-rates` |
| `*/5 * * * *` | `storefront:cache-warm` |
| `*/5 * * * *` | `shop-pay:reconcile` |

```bash
php bin/console messenger:consume scheduler_default -vv   # drives the schedule
php bin/console messenger:consume async                   # consumes the configured async
                                                          # transport — see note
php bin/console messenger:failed:show / :retry / :remove  # failure transport
```

> Transports: `symfony/redis-messenger` is installed, so workers can use
> the configured `redis://` `async`/`failed` DSNs (plus `sync://` /
> `in-memory://`). Messages are unrouted today (all sync), so the only
> strictly required consumer is `scheduler_default`.

## Console commands

```bash
storefront:cache-warm [--pages=N] [--locale=..]*   # per locale: cats + featured + pages
storefront:cache-clear                             # rotate tree/list/card generations
commerce:refresh-exchange-rates                    # fetch + store configured quotes
payments:reencrypt-legacy [--apply] [--batch-size] # AES→v1 sodium; dry-run default
shop-pay:reconcile                                 # reconcile all processing sessions
shop-pay:subscribe-webhooks                        # register orders/create webhook at
                                                   # SHOP_PAY_WEBHOOK_URL
debug:scheduler default                            # inspect recurring messages
```

## Health

- `GET /healthz` → `{data:{status:'ok',service:'symfony-api'}}`
  (PHP-FPM up + kernel booted; does **not** probe DB/Redis — don't use it
  as a dependency check).
- Compose healthchecks: `pg_isready` (pgsql), `redis-cli ping` (redis);
  php and caddy have none.

## Deploy / rollback checklist

```bash
# build
cd frontend && npm ci && npm run build        # dist/ mounted into caddy at /srv/frontend/dist
docker compose build php                      # bakes vendor + prod .env defaults
# ship
docker compose up -d pgsql redis
php bin/console doctrine:migrations:migrate --no-interaction   # on existing DB: mark baseline
                                                               # ...000000 first (see data-migration.md);
                                                               # this applies compat upgrade ...001000
php bin/console cache:clear --env=prod && php bin/console cache:warmup
docker compose up -d                          # php + caddy
# workers
<supervisor|compose> php bin/console messenger:consume scheduler_default --time-limit=3600
# rollback
docker compose up -d <previous image>         # code rollback is safe;
                                              # baseline down() is destructive (drops all 19 tables)
                                              # and the ...001000 compat upgrade is deliberately
                                              # irreversible — production rollback restores a backup
php bin/console storefront:cache-clear        # after any deploy touching catalog reads
```

Keep `LARAVEL_LEGACY_APP_KEY` deployed until `payments:reencrypt-legacy`
reports every row `v1:` (see `docs/data-migration.md`).

## Failure / outage behavior

- **Redis down**: storefront cache degrades to direct DB reads
  (one warning per process, `null` generation → resolver path); sessions
  fail → 401s across the board; `cache.rate` lookups fail → exchange-rate
  reads fall through to the DB store (last stored quote is used even when
  stale when the fetcher fails); when no stored quote exists, catalog and
  cart responses still 200 but products/cart carry `pricing_error`, and
  order placement answers 422 `errors.cart`; locks fail → SWR refreshes
  resolve directly instead of waiting.
- **PostgreSQL down**: everything DB-backed 500s via `ApiExceptionSubscriber`
  (problem JSON); `/healthz` still returns ok — front it with a DB probe
  if you need dependency-aware health.
- **GeoIP down**: `GeoIpResolver` catches its own fetch/cache errors and
  returns `null`; `MarketContextResolver` then falls back to
  `COMMERCE_DEFAULT_COUNTRY`; misses are cached briefly (600 s) to avoid
  hammering a flaky provider.
- **Shop Pay disabled/misconfigured**: `SHOP_PAY_ENABLED` false → every
  `/payments/shop-pay/*` route 503 `Shop Pay is not available.` and the
  order page renders the unavailable state; upstream Shopify errors
  surface as 422 `Shop Pay request failed.` with `detail`.
- **Messenger async unrouted**: dispatching an unrouted message just runs
  it in-process (sync) — no queue needed today.
- **Logs**: code logs through PSR-3 `LoggerInterface`; no Monolog bundle
  is installed, so framework's built-in stderr `Logger` handles it —
  pipe php-fpm output wherever your runtime collects logs.
