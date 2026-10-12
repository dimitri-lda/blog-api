# Architecture

Symfony 8.1 JSON API for the daoSport storefront, consumed by a React/Vite
single-page application. The design intentionally avoids Next.js, Inertia,
Ziggy, API Platform and Doctrine ORM entities: persistence is hand-written
DBAL, transport is one JSON contract.

## Layers

```text
src/
  Domain/          pure value objects: OrderStatus, PaymentSessionStatus,
                   UserIdentity contracts. No IO, no framework types.
  Application/     use cases and services:
                     Cart/        cart lifecycle
                     Catalog/     StorefrontCatalog interface (read model)
                     Commerce/    MarketContextFactory, PricingService,
                                  ExchangeRateProvider/Store/Fetcher ports,
                                  CountryCatalog
                     Orders/      PlaceOrderService
                     Payments/    ShopPayService, FieldCipher port
                     Security/    exceptions + ports
  Infrastructure/  adapters that touch the outside world:
                     Cache/       StorefrontCache, CachedStorefrontCatalog,
                                  DeferredTaskRegistry, terminate subscriber
                     Commerce/    ExchangeRateService, HttpExchangeRateFetcher
                     Crypto/      SodiumFieldCipher (v1:), PaymentPayloadCodec,
                                  LegacyLaravelDecryptor, re-encryption service
                     Http/        MarketContextResolver, GeoIpResolver
                     Localization/TranslationCatalog (translations/store.yaml)
                     Payments/    ShopPayConfiguration, ShopPayStorefrontClient,
                                  ShopifyAdminClient, ShopPayPaymentRequestFactory
                     Persistence/ Dbal* repositories for catalog, cart, orders,
                                  users/profiles
                     Scheduling/  StoreSchedule (Symfony Scheduler)
                     Security/    DbalUserProvider, DbalUserAccounts,
                                  Login*Handler, ApiCsrfSubscriber,
                                  PasswordResetService
  Presentation/
    Http/Api/V1/   thin controllers: MapRequestPayload DTOs in, JsonResponse
                   `{data: ...}` out; all invariants live in Application.
    Http/          HealthController (/healthz), ApiExceptionSubscriber.
    Console/       storefront/payments/commerce CLI commands.
```

Dependencies point inward only: Presentation and Infrastructure depend on
Application/Domain interfaces; `StorefrontCatalog` is injected as
`CachedStorefrontCatalog` (decorator over `DbalStorefrontCatalog`) via
`config/services.yaml` alias — no annotations in use-case code.

## Persistence

PostgreSQL 18 through Doctrine DBAL `Connection` only (`auto_mapping: false`;
ORM is installed for migrations tooling but never queried). Read/write
repositories are `Dbal*` classes (e.g. `DbalStorefrontCatalog`,
`DbalCartService`, `DbalOrderReader`, `DbalAdminOrderService`,
`DbalProfileService`, `DbalUserAccounts`, `DbalUserProvider`). All multi-write
flows (order placement + stock reservation + cart clearing, admin status
change + inventory release, password reset + remember-token rotation,
webhook delivery dedup) run inside `transactional()`. Schema lives in
`migrations/` — `Version20261012000000` is the fresh-schema baseline (19
tables) and `Version20261012001000` is an idempotent compatibility
upgrade for legacy Laravel schemas that predate Shop Pay / inventory
hardening (deliberately irreversible).

## Security model

- Same-origin session auth: `json_login` at `POST /api/v1/auth/login`
  (`email`/`password` body), `storefront_session` cookie backed by Redis
  (`RedisSessionHandler`, `sess_` prefix, 7200 s), `logout` at
  `POST /api/v1/auth/logout`, `remember_me` cookie `storefront_remember`
  (30 days, httponly, samesite=lax, `secure` from `SESSION_COOKIE_SECURE`).
- Login throttling: Symfony `login_throttling`, 5 attempts/minute.
  `framework.lock` (`LOCK_DSN`, `flock` in test) backs the storefront
  cache's stampede locks.
- Role hierarchy `ROLE_SUPER_ADMIN > ROLE_ADMIN > ROLE_USER`; `#[IsGranted]`
  guards `/api/v1/profile*` + `/api/v1/me/orders` (USER) and
  `/api/v1/admin/orders*` (ADMIN). Object-level ownership is enforced in
  controllers (`OrderController::canRead`, `ShopPayController::canRead`):
  the owning user or the session that placed the order (`last_order`).
- CSRF: `ApiCsrfSubscriber` requires a valid `X-CSRF-Token` header (token id
  `api`, minted by `GET /api/v1/context`) on every non-GET `/api/v1/*` call
  except `/api/v1/webhooks/*`, which uses HMAC verification instead.
- Error surface: `ApiExceptionSubscriber` converts validation failures to
  422 problem JSON with `errors`, `AccessDeniedException` to 401 (anonymous)
  or 403 (authenticated), HTTP exceptions to their status, anything else to
  500 — always `application/problem+json` under `/api/`.
- Login migrates the session id and clears CSRF storage (fixation
  hardening): clients must refetch `/api/v1/context` after login/register.

## Frontend

React 18 SPA in `frontend/` (Vite 8, React Router 7, TanStack Query,
Tailwind 3, HeadlessUI). Same-origin `fetch` wrapper (`src/api/client.js`)
sends `credentials: same-origin` and the CSRF token; `AppContext` holds
`/context` data and gates rendering until the token is installed. Caddy
serves `frontend/dist` statically with SPA fallback and proxies `/api/*`
and `/healthz` to PHP-FPM, so there is no CORS surface in production.

## Data / integration points

- Market resolution per request: session `market` attribute if present,
  else GeoIP lookup (`GEOIP_URL` template, ipwho.is schema, cached in the
  storefront pool) else `COMMERCE_DEFAULT_COUNTRY`; `PUT /api/v1/preferences`
  writes it back. BY/PL have dedicated market rows (BYN/PLN, ru/pl, VAT);
  other supported countries use EUR + the EU VAT table + chosen locale.
- Pricing: `PricingService` converts net EUR cents through
  `ExchangeRateService` (DB store + `cache.rate` + HTTP fetchers with
  fallbacks: MyFin → NBRB for BYN, Walutomat → NBP for PLN) and applies
  market VAT and delivery rules.
- Shop Pay: `ShopPayService` talks to the Shopify Storefront + Admin APIs;
  only `shopId`/`clientId` are exposed publicly via
  `order.shop_pay.config`; all tokens/secrets stay server-side.
  `ShopifyWebhookController` verifies `X-Shopify-Hmac-Sha256` and dedupes
  `orders/create` deliveries.
- Sessions, cache, locks and (optionally) Messenger transports live in
  Redis; PostgreSQL holds all business state.

## Background work

Symfony Scheduler `default` schedule (`StoreSchedule`, stateful on
`cache.app`, `processOnlyLastMissedRun`):

| Cron          | Command                          |
|---------------|----------------------------------|
| `0 * * * *`   | `commerce:refresh-exchange-rates`|
| `*/5 * * * *` | `storefront:cache-warm`          |
| `*/5 * * * *` | `shop-pay:reconcile`             |

It is driven by `messenger:consume scheduler_default`. Messenger also
declares `async`/`failed` transports (env-configured DSNs) but no routing
rules yet — every dispatch currently runs synchronously.
