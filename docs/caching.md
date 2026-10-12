# Storefront caching

The public catalog (`/`, `/shop`, `/shop/{slug}`) is cached through the
`StorefrontCache` service (`App\Services\Cache\StorefrontCache`) with the read
logic living in `App\Services\Catalog\StorefrontCatalog`. Configuration lives in
`config/storefront.php`.

## Enabling and choosing a store

Caching is on by default and uses the application's default cache store
(`CACHE_STORE`, `database` locally):

```dotenv
STOREFRONT_CACHE_ENABLED=true
# Point the storefront cache at a dedicated store, e.g. redis:
# STOREFRONT_CACHE_STORE=redis
```

With Sail, Redis runs in the `redis` service — set `STOREFRONT_CACHE_STORE=redis`
in `.env`. On the host (outside Sail) the connection host is `127.0.0.1` and the
port is `${FORWARD_REDIS_PORT:-6379}`; on a production host point `REDIS_HOST` /
`REDIS_PORT` at your Redis instance. `STOREFRONT_CACHE_STORE` accepts any store
name from `config/cache.php`; when it is empty the default store is used.

If the configured store throws on any operation, the pages fall back to plain
queries — no user-facing error. The failure is logged once per request as
`Storefront cache store failure.`

## What is cached

All keys are prefixed with `sf:`. Generation-scoped entries use the form
`sf:{group}:{generation}:{key}`; value entries (no generation) use `sf:{key}`.

| Key | Group | TTL | Contents |
| --- | --- | --- | --- |
| `sf:tree:{gen}:tree:{locale}:{tv}` | tree | 600 s fresh / 1800 s total | Category tree incl. `products_count` |
| `sf:list:{gen}:featured` | list | 120 s fresh / 600 s total | Featured product ids (≤ 8) |
| `sf:list:{gen}:page:{sha1}` | list | 120 s fresh / 600 s total | `{ids, total}` for a catalog page + filters |
| `sf:card:{gen}:card:{id}:{locale}:{tv}` | card | 1800 s | Product card (`Product::toArray()` with catalog relations) |
| `sf:card:{gen}:detail:{id}:{locale}:{tv}` | card | 1800 s | Product page payload (product, breadcrumbs, related) |
| `sf:gen:{group}` | — | 30 days | Generation token per group (`tree`, `list`, `card`, `rate`) |
| `sf:rate:{gen}:{currency}` | — | 300 s (never longer than quote freshness) | `{rate, source, quoted_at}` |
| `sf:tr:{locale}:{tv}` | — | 3600 s | Flat translation catalog per locale |
| `geoip:{sha256(ip)}` | — | 30 days on hit, `ttl.geoip_miss` on miss | Resolved country code or miss sentinel |
| `sf:warm:pending` | — | `warm.debounce_seconds` (10 s) | Debounce marker for deferred warm-up |

`{tv}` is the translation-file version: `md5(filemtime:filesize)` of
`lang/store.yaml`, so a translation deploy invalidates every locale-dependent
entry without touching the file contents. Page ids/totals are locale-agnostic
and deliberately do not include the locale in their keys.

Pages are not cached when `strlen(q) > max_cached_search_length` (32) or
`page > max_cached_page` (20) — those requests run the same SQL as before.

## Generations

Each group has a single token stored at `sf:gen:{group}` and created lazily with
`Cache::add()` so concurrent readers converge on one token. Rotating a group
just forgets its token: the next read mints a new token, which moves every
subsequent key into a fresh namespace. Orphaned entries expire on their own
TTL. `Cache::flush()` and tags are never used, so a rotation cannot disturb
unrelated cache entries. Losing a generation key always yields a *new*
namespace — stale data can never be resurrected.

## Stale-while-revalidate

The `tree` and `list` groups store `{value, fresh_until}`. A hit inside
`fresh_until` is served directly; a stale hit is served immediately and the
entry is refreshed after the response (`defer()`, or synchronously in console).
A miss takes the `lock:{key}` lock (stores implementing `LockProvider` only) to
collapse stampedes; when the lock is busy a reader waits at most
`lock_wait_ms` for the other process to publish before computing itself.
Exceptions thrown by the value callback always propagate — they are never
swallowed and never written into the cache.

## Invalidation

`App\Observers\StorefrontCacheObserver` is registered on `Product`,
`ProductVariant`, `ProductImage`, `ProductSpecification`, `Category`, `Brand`
and `Manufacturer`. Every action runs immediately and, inside an open
transaction, once more via `DB::afterCommit()`.

- **Product** (created/updated/deleted): forget its `card:`/`detail:` keys for
  all locales; rotate `list` when structure-affecting fields change (`slug`,
  `active`, `featured`, `category_id`, prices, `name`, brand links); rotate
  `tree` on create/delete or an `active`/`category_id` change.
- **ProductVariant / ProductImage / ProductSpecification**: forget the owning
  product's `card:`/`detail:` keys only (including the previous `product_id`).
  **No generation is rotated** — a stock change affects exactly one card.
- **Category**: rotate `tree` and `card`; additionally `list` on create/delete
  or a `slug`/`parent_id` change.
- **Brand / Manufacturer**: rotate `card` and `list` (names are searchable).

An order placement or cancellation therefore re-reads a single product card;
the page ids, the category tree and all other cards stay hot.

Exchange-rate quotes are cached under a dedicated `rate` generation, separate
from the catalog groups so `storefront:cache-clear` never disturbs them.
`AppServiceProvider::boot()` registers a query listener that rotates that
generation on **any** write to the `exchange_rates` table — including mass
deletes and raw SQL that bypass Eloquent events. A request that actually
fetches a quote from the external sources writes the fresh row and skips the
shared cache entirely (the write already rotated the generation), so the next
request caches the stored row under the new namespace by construction.
`commerce:refresh-exchange-rates` therefore needs no cache code of its own:
it fetches, updates the row, and the listener does the invalidation — while a
source failure throws before any write and leaves the cache untouched.

Pricing (`display_price`, `pricing_error`), `auth`, `flash` and `market` props
are computed per request on top of the cached arrays and never enter the cache.

## Operations

```bash
# Targeted reset: rotate the three generations (unrelated keys survive)
php artisan storefront:cache-clear

# Warm the tree, featured and the first N catalog pages per locale
php artisan storefront:cache-warm --pages=2 --locale=en --locale=pl
```

The scheduler runs `storefront:cache-warm` every five minutes
(`routes/console.php`), so a cold cache self-heals quickly.

With `STOREFRONT_CACHE_WARM_AFTER_INVALIDATION=true`, a web request that
invalidates entries also schedules a deferred warm-up, debounced via the
`sf:warm:pending` marker for `warm.debounce_seconds`.

## Diagnosis

- **Pages look stale** — check `sf:gen:*` tokens; rotating a group is the
  surgical reset (`storefront:cache-clear`).
- **Redis down** — requests degrade to plain SQL (same as before caching) and a
  single warning is logged per request.
- **Translations not updating** — the `{tv}` version comes from file mtime+size;
  on deployments that preserve timestamps, clear the `sf:tr:*` keys or rotate
  the tree/card groups.
- **Staleness boundary** — list/tree entries may be served up to `total` TTL
  old *only* when refresh keeps failing; card entries until their TTL; a geoip
  miss is retried after `ttl.geoip_miss`. Everything else is invalidated on
  write by the observer.

## What this cache does not fix

It removes catalog SQL and YAML parsing from the request path only. It does
not reduce Docker bind-mount latency, the Vite dev server, or the Inertia
overhead in local development, and it does not replace `php artisan
config:cache` / `route:cache` — those production caches must not be run
locally because `config:cache` would freeze your `.env` values.
