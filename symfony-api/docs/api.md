# API reference

Base: `/api/v1`, same origin, `Accept: application/json`. All responses are
JSON. The full route table is generated with `php bin/console debug:router`
— the list below is current as of this writing.

## Envelope and errors

- Success payloads always use a top-level `data` object/array. List
  endpoints may add sibling `meta`, `filters`, `statuses` keys.
- Errors use `application/problem+json`:

```json
{
    "type": "https://httpstatuses.com/422",
    "title": "Validation failed.",
    "status": 422,
    "errors": {"email": ["The email has already been taken."]},
    "detail": "optional free-form detail"
}
```

`errors` (when present) maps field name → array of messages; `detail`
appears on business failures (cart conflicts, Shop Pay errors).

## Authentication model

- Session cookie `storefront_session` (Redis-backed), sent
  `credentials: same-origin`; `storefront_remember` remember-me cookie
  when `remember_me: true` is posted to login.
- CSRF: `GET /api/v1/context` returns `data.csrf_token`. Every
  non-GET `/api/v1/*` call must repeat it in `X-CSRF-Token`, otherwise
  `ApiCsrfSubscriber` answers 403 `Invalid CSRF token.` before the
  controller runs. `/api/v1/webhooks/*` is exempt (HMAC instead).
- **After login or register you must refetch `/api/v1/context`**: Symfony
  migrates the session id and clears CSRF storage on authentication, so
  the token minted before login is invalid. Login responses intentionally
  contain no token.
- Anonymous calls to role-guarded routes return 401
  `Authentication required.`; authenticated non-privileged calls return
  403 `Forbidden.`
- Ownership rules: `GET /api/v1/orders/{number}` and all
  `/api/v1/orders/{number}/payments/shop-pay/*` calls are allowed for the
  owning user (`order.user_id === auth.id`) or any session whose
  `last_order` matches — i.e. the guest session that just placed it.
  Everyone else gets 404 (not 403).

## Market context

`GET /api/v1/context` resolves a market per session: the stored
`market` attribute wins, else a GeoIP lookup (`GEOIP_URL`,
`ipwho.is`-compatible) decides the country, else
`COMMERCE_DEFAULT_COUNTRY` (default `DE`). Result is persisted in the
session. `BY` and `PL` are dedicated markets (BYN/PLN currency, ru/pl
locale, fixed VAT); other supported countries price in EUR with the
per-country EU VAT rate and the chosen locale (`en`/`ru`/`pl`).
`PUT /api/v1/preferences` `{country, locale}` rewrites the session market;
clients should refetch context and invalidate priced queries afterwards.

## Routes

All routes below are relative to `/api/v1`. `[CSRF]` = `X-CSRF-Token`
required. `[USER]`/`[ADMIN]` = role required. `[OWN]` = ownership rule above.

### Context / meta

| Method + path | Purpose |
|---|---|
| GET `/context` | `{data:{auth:{user\|null},market,countries,translations,csrf_token}}` |
| PUT `/preferences` [CSRF] | `{country, locale}` → `{data:{market,message}}`; 422 on unsupported country |
| GET `/healthz` (root path, not `/api/v1`) | `{data:{status:'ok',service:'symfony-api'}}` |

`user` shape: `{id,name,email,email_verified_at,role,roles}`.

### Storefront

| Method + path | Purpose |
|---|---|
| GET `/store/home` | `{data:{categories[],featured[]}}` |
| GET `/categories` | `{data:[{id,name,localized_name,slug,parent_id,products_count,...}]}` |
| GET `/products` | `{data:[product],meta:{current_page,last_page,per_page,total},filters}` |
| GET `/products/{slug}` | `{data:{product,breadcrumbs[],related[]}}`; 404 otherwise |

`/products` query params: `q`, `category` (slug), `sort`
(`price_asc`|`price_desc`), `page`; `filters` echoes `q/category/sort`.
`meta` = `{total,current_page,per_page,last_page}`. Products
carry `variants[]` (with `stock`, `display_price`), `display_price`
`{gross_cents,currency}`, `images[]`, `specifications[]`, `brand_record`,
`localized_name/localized_description`, and optionally `pricing_error`
when an exchange rate is unavailable.

### Cart (guest + user, session-scoped)

| Method + path | Purpose |
|---|---|
| GET `/cart` | `{data:cart}` — fields below |
| POST `/cart/items` [CSRF] | `{variant_id, quantity}` → 201 `{data:cart}`; 422/404 on cart errors (`detail`) |
| PATCH `/cart/items/{id}` [CSRF] | `{quantity}` (0–20; `0` removes the line) → `{data:cart}` |
| DELETE `/cart/items/{id}` [CSRF] | → `{data:cart}` |

Cart shape: `items`, `count`, `subtotal_cents` always; once priced it also
carries the quote totals (`currency`, `market`, `locale`, `country`,
`exchange_rate`, `rate_source`, `vat_rate_basis_points`,
`net_subtotal_cents`, `tax_cents`, `delivery_net_cents`,
`delivery_tax_cents`, `delivery_cents`, `total_cents`) and
`delivery_quotes` (`{standard: quote, express: quote}`); `pricing_error`
replaces them on failure. Every mutation returns the whole repriced cart.

### Checkout / orders

| Method + path | Purpose |
|---|---|
| GET `/checkout` | `{data:{cart,user\|null,address\|null}}` |
| POST `/orders` [CSRF] | body below → 201 `{data:{id,number}}`; 422 field `errors` (cart errors on `errors.cart`) |
| GET `/orders/{number}` [OWN] | `{data:order}` — fields below |
| GET `/me/orders` [USER] | `{data:[order]}` newest first (same order shape incl. `shop_pay`) |

An `order` includes `items[]`, `address`, `customer`, `user_id`, totals,
`status`, `currency`, `market`, `locale`, `exchange_rate`,
`vat_rate_basis_points`, and `shop_pay` (see below).

Order placement body (`PlaceOrderPayload`):

```json
{
    "email": "ana@example.com", "phone": "+491701234567",
    "first_name": "Ana", "last_name": "Lopez",
    "line1": "Hauptstr. 12", "line2": null,
    "city": "Berlin", "postal_code": "10115", "country": "DE",
    "delivery_method": "standard"
}
```

`delivery_method` ∈ `standard|express`; `country` must be in
`countries` (422 otherwise). On success the session `last_order` is set so
the guest confirmation page and Shop Pay flow can read the order.

### Auth

| Method + path | Purpose |
|---|---|
| POST `/auth/register` [CSRF] | register body → 201 `{data:{user}}`; logs in (session migrates — refetch) |
| POST `/auth/login` [CSRF] | `{email,password,remember_me}` → 200 `{data:{user}}`; 401/422; throttled 5/min |
| POST `/auth/logout` [CSRF] | 200 `{data:{logged_out:true}}`; session invalidated — refetch context |
| POST `/auth/forgot-password` [CSRF] | `{email}` → `{data:{message}}` (always generic) |
| POST `/auth/reset-password` [CSRF] | reset body → `{data:{password_reset:true}}`; 422 `errors.email` on bad token |

Register body: `{name,email,password,password_confirmation,accepted_terms}`.
Reset body: `{token,email,password,password_confirmation}` — tokens are
single-use with a 1-hour TTL; failures surface as 422 `errors.email`.

### Profile [USER, CSRF on mutations]

| Method + path | Purpose |
|---|---|
| GET `/profile` | `{data:{user,address\|null}}` |
| PATCH `/profile` | `{name,email}` → `{data:{user,address}}`; email change clears `email_verified_at` |
| PUT `/profile/address` | address body → `{data:{user,address}}`; 422 unsupported country |
| PUT `/profile/password` | password body → `{data:{password_updated:true}}`; 422 on wrong/mismatched passwords |

Address body: `{first_name,last_name,line1,line2,city,postal_code,country}`.
Password body: `{current_password,password,password_confirmation}`.
| DELETE `/profile` | `{password}` → 204; session invalidated |

### Shop Pay (pending orders) [OWN, CSRF]

Only functional when `SHOP_PAY_ENABLED=true`; otherwise every endpoint
returns 503 `Shop Pay is not available.`

| Method + path | Purpose |
|---|---|
| POST `/orders/{number}/payments/shop-pay/session` | → `{data:{token,checkoutUrl,sourceIdentifier,paymentRequest}}` |
| PATCH `/orders/{number}/payments/shop-pay/quote` | quote body → `{data:{paymentRequest}}` |

Quote body: `{session_token, shipping_address{first_name,last_name,line1,line2,
city,postal_code,country}, delivery_method}`.
| POST `/orders/{number}/payments/shop-pay/submit` | `{session_token,payment_method}` → `{data:{status}}` |
| POST `/orders/{number}/payments/shop-pay/reconcile` | → `{data:{paid}}` |

`GET /orders/{number}` adds `data.shop_pay` only when the feature is
enabled and the order is `pending_payment`:

```json
"shop_pay": {
    "config": {"shopId": 12345678, "clientId": "shp_..."},
    "state": "pending",
    "payment_request": { "...Shop Pay payment request object..." }
}
```

`config` is the only public surface — `shopId`/`clientId`. The Storefront
private token, Admin access token and app client secret never leave the
backend. Business errors are 422 `Shop Pay request failed.` with `detail`.

### Webhook

`POST /api/v1/webhooks/shopify/orders` — not CSRF-guarded. Verifies
`X-Shopify-Hmac-Sha256` (base64 HMAC-SHA256 of the raw body with
`SHOP_PAY_APP_CLIENT_SECRET`); 401 on mismatch. Dedupes on
`X-Shopify-Webhook-Id` (UUID) via `shopify_webhook_deliveries`
(INSERT … ON CONFLICT DO NOTHING). Bodies whose `source_identifier` /
`sourceIdentifier` start with `shop-pay:` trigger reconciliation of the
referenced order number; everything returns empty 200 (invalid headers
included — Shopify probes are acked). Register the subscription with
`bin/console shop-pay:subscribe-webhooks`.

### Admin [ADMIN, CSRF on PATCH]

| Method + path | Purpose |
|---|---|
| GET `/admin/orders` | admin list payload — fields below; query `q`, `status`, `page` |
| GET `/admin/orders/{id}` | `{data:{order,next_statuses[]}}`; order adds `items[]`, `address`, `customer`, counts |
| PATCH `/admin/orders/{id}/status` | `{status}` must be in `next_statuses` → `{data:{status,next_statuses}}` |

Admin list payload: `{data:[order-summary], meta:{current_page,last_page,
per_page,total}, filters:{q,status}, statuses:[...]}`. Status PATCH answers
422 `errors.status` on an invalid transition (including failed stock
release) and 404 for an unknown id.

Status enum: `pending_payment, paid, processing, shipped, delivered,
cancelled, refunded`. Valid transitions are defined by
`Domain\Orders\ValueObjects\OrderStatus::allowedNextStatuses`.
