# Storefront caching

`App\Infrastructure\Cache\StorefrontCache` is a generation-namespaced,
stale-while-revalidate wrapper over the `cache.storefront` Redis pool.
`CachedStorefrontCatalog` (the `StorefrontCatalog` implementation wired
in `services.yaml`) is the only consumer; every catalog read goes through
it. Enable/disable globally with `STOREFRONT_CACHE_ENABLED`.

## Key design

```text
generation key : sf.gen.{group}
data key       : sf.{group}.{generation}.{sha256(logical key)}
entry lock     : {full data key}.lock       (per-entry stampede lock)
generation lock: sf.gen.{group}.lock
```

- `generation()` memoizes a non-empty token per group in-process; on a
  miss it takes a lock, re-reads, then writes `bin2hex(random_bytes(8))`
  with `storefront.cache.generation_ttl` (2592000 s / 30 d). A missing
  token always mints a new namespace — rotation never reuses keys.
- Data values are JSON-safe arrays/scalars only; no cache tags and no
  global `clear()` — invalidation is by generation rotation or explicit
  `forget` of hashed logical keys.
- Generation lookup failure (pool/lock down) returns `null` **unmemoized**,
  so reads degrade to the resolver and recover when Redis comes back.

## Groups and TTL

`config/packages/storefront.yaml`:

| Group | Mechanism | Fresh | Total |
|---|---|---|---|
| `tree` | `remember()` envelope `{value, fresh_until}` | 600 s | 1800 s |
| `list` | `remember()` envelope | 120 s | 600 s |
| `card` | `readMany`/`writeMany` flat value | — | `storefront.cache.card_ttl` = 1800 s |

`remember('card')` is intentionally unsupported — card entries have no
SWR envelope (the TTL map has no `card` key and `remember()` throws
`InvalidArgumentException` for unknown groups before touching cache or
the resolver). Search is bounded: catalog pages are only cached when
`mb_strlen(q) <= max_search_length` (32) and `page <= max_page` (20);
bigger queries hit the DB directly.

Logical keys (what gets hashed):

```text
tree : categories.{locale}.{translationsVersion}
list : featured.{limit}
list : page.{sha256(json_encode{q,category,sort,page,per_page})}
card : card.{productId}.{locale}.{translationsVersion}
card : detail.{slug}.{locale}.{translationsVersion}
```

`translationsVersion` = `md5(mtime:size)` of
`translations/store.yaml` — deploying changed translations re-keys every
card/detail automatically; stale entries simply expire by TTL.

## SWR and deferred refresh

- `remember()` miss → `fill()`: try a non-blocking per-key lock; acquired
  → run resolver **outside** any cache catch, save envelope with the
  total TTL, release in `finally`. Busy → poll the key every 25 ms for at
  most `lock_wait_ms` (500 ms) and return the winner's value even if
  stale; on timeout resolve directly and save.
- Fresh hit → value. Stale hit → value returned immediately plus **one**
  deferred refresh registered in `DeferredTaskRegistry` keyed by the full
  cache key (first registration wins).
- `RunDeferredTasksSubscriber` (`kernel.terminate`) drains the registry
  after the response is sent, logging each failure as
  `Deferred storefront cache refresh failed.` — refresh never delays or
  breaks the response.
- Deferred refresh re-acquires the same lock non-blocking; if busy it
  does nothing (another worker is already refreshing).

## Failure behaviour

- Any pool/lock `Throwable` logs `warning('Storefront cache store failure.')`
  **once per service instance** and degrades to the resolver; lock-acquire
  or lock-create failure resolves immediately (never enters the wait loop);
  `release()` failures are caught so they can't mask resolver exceptions.
- Resolver/business exceptions always propagate, are never logged as cache
  failures, and are never cached.
- `rotate(...$groups)` deletes only generation keys (+ in-process memo);
  `flush()` rotates exactly `tree`, `list`, `card`. `forget()` deletes the
  current-generation hashed keys. Disabled mode short-circuits all of it.

## Post-commit invalidation

Business writes evict per-card keys (not whole generations):

- `PlaceOrderService` forgets `card.{id}.{locale}.{ver}` and
  `detail.{slug}.{locale}.{ver}` for every affected product × configured
  locale (`commerce.locales` = en/ru/pl) after the order transaction
  commits — covers the stock that was reserved.
- `DbalAdminOrderService::changeStatus` does the same for products whose
  stock is released on cancellation.

## Other cache consumers of the pools

`cache.yaml` defines three Redis pools (`cache.app`, `cache.storefront`,
`cache.rate`); in `APP_ENV=test` all become array adapters.

- Catalog tree/list/card + GeoIP + translations → `cache.storefront`
  (keys above); `geoip.{sha256(ip)}` 30 d hit / `missTtl` 600 s miss;
  `tr.{locale}.{ver}` 3600 s.
- `ExchangeRateService` → `cache.rate`: `rate.{CCY}` item TTL 300 s,
  quotes usable up to 6 h.
- Sessions → `RedisSessionHandler` (not a cache pool): `sess_*`,
  TTL 7200 s.
- Scheduler `stateful` → `cache.app`: last-run timestamps for
  missed-run logic.
- Storefront stampede locks → `lock.factory` via `LOCK_DSN` (Redis;
  `flock` in test): lock TTL `lock_seconds` = 10 s.

## Commands

```bash
php bin/console storefront:cache-warm [--pages=N] [--locale=en]...
    # per locale: categories + featured + first N catalog pages
    # (default N = storefront.cache.warm_pages = 2; all locales when omitted)
php bin/console storefront:cache-clear
    # rotates tree/list/card generations (old data orphans until TTL expiry)
php bin/console cache:pool:clear cache.storefront   # last resort, wipes all
```

Warm runs every 5 minutes via the `default` scheduler
(`messenger:consume scheduler_default`), so generations self-heal after
rotation.
