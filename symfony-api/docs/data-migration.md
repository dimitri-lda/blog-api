# Data migration: Laravel → Symfony

Goal: point this application at the existing PostgreSQL database and keep
all business data — users, catalog, carts, orders, saved addresses,
payment sessions — while secrets move to the new cipher. The Symfony app
was designed to share the Laravel schema, so **no business-table rebuild
is required**; the work is baseline marking, verification, re-encryption
of legacy encrypted columns, and controlled cut-over.

## Ground rules

- Never run a destructive command (`doctrine:schema:*`, `migrate:fresh`,
  `TRUNCATE`, `DROP`) against an unspecified or shared database.
  `doctrine:migrations:migrate` on the existing DB is safe *only after*
  the baseline is marked (below) — it would otherwise try to create
  tables that already exist.
- All commands run with `APP_ENV=prod` and an explicit `DATABASE_URL`
  pointed at the target database. Rehearse on a restored production dump
  first; nothing below should touch prod unverified.
- Sessions live in Redis, not the database — see caveats.

## 1. Schema compatibility rehearsal

The baseline `migrations/Version20261012000000.php` creates these tables
(19): `users`, `password_reset_tokens`, `brands`, `manufacturers`,
`categories`, `products`, `product_variants`, `product_images`,
`product_specifications`, `carts`, `cart_items`, `orders`,
`order_addresses`, `order_items`, `saved_addresses`, `exchange_rates`,
`payment_sessions`, `payment_attempts`, `shopify_webhook_deliveries`.

A second migration, `Version20261012001000` ("Upgrade a Laravel schema
that predates Shop Pay and inventory hardening"), is the **compatibility
upgrade** for legacy databases that predate the two 2026-10-11 Laravel
migrations. Idempotent (`IF NOT EXISTS` / constraint-existence guards),
it adds `payment_sessions`, `payment_attempts`,
`shopify_webhook_deliveries`, `orders.inventory_reserved_at` /
`inventory_released_at`, `payment_attempts.amount_cents`/`currency`, and
the `order_items → product_variants` FK (plus backfill UPDATEs). It is
deliberately **irreversible** (`down()` throws `IrreversibleMigration`)
— rollback for it is backup restore only. On a fresh empty DB it runs
after the baseline as a harmless no-op.

Against a **restored dump** (never prod directly):

1. Point `DATABASE_URL` at the restored database.
2. Prove schema compatibility **structurally, not via
   `doctrine:migrations:diff`** — `auto_mapping` is off, so diff has no
   entity metadata to compare against and its output is meaningless here:
   - create a fresh empty database and run the baseline migration into it
     (`doctrine:migrations:migrate`);
   - `pg_dump --schema-only` both databases and diff the DDL (plus a
     catalog comparison of indexes/constraints/column types — `pg_dump`
     ordering differences need manual review);
   - verify the comparison shows **no business-table drift**. If it does,
     stop and reconcile the schema or write a companion migration before
     continuing.
3. Mark **only** the baseline as already applied **without running its
   SQL**:
   `php bin/console doctrine:migrations:version
   'DoctrineMigrations\Version20261012000000' --add --no-interaction`
   (requires `doctrine_migration_versions` to exist; create the
   migrations metadata table first via `doctrine:migrations:sync-metadata-storage`
   if the dump lacks it). The compatibility migration stays **pending**
   at this point — that is expected, not a drift signal.
4. Apply the pending compatibility upgrade:
   `php bin/console doctrine:migrations:migrate --no-interaction`
   — on a verified legacy restore this executes 32 SQL statements and
   leaves all 3 new tables, both `orders` columns and 3 FKs present;
   confirm `doctrine:migrations:status` / `migrations:list` shows
   `latest` with nothing pending.

## 2. Caveats: sessions and reset tokens

- **Sessions are Redis-side** (`RedisSessionHandler`, `sess_*` keys).
  Cut-over does not move them: either flush Redis (everyone logs in
  again — acceptable and simplest) or point the new stack at the same
  Redis and accept that serialized Symfony session bags differ from
  Laravel's. Active sessions/`market`/`last_order` guest state will not
  survive format changes; plan the cut-over as a re-login event.
- **`password_reset_tokens`**: the schema is compatible (email + hashed
  token + created_at) and Laravel tokens were also hashed — but Laravel
  emailed links to its own reset route. Outstanding reset links issued
  by Laravel will hit whichever app serves `/reset-password`; tokens
  older than the 1-hour window are dead anyway. In-flight reset emails
  are an accepted casualty of cut-over; advise users to re-request.
- **`remember_me`**: Laravel `remember_token` column is preserved
  (users.remember_token exists in baseline), but Symfony's remember-me
  signature (`storefront_remember` cookie + `%kernel.secret%`) differs —
  persistent logins reset. Expected; document for support.

## 3. Legacy encrypted fields (AES-256-CBC → v1 sodium)

Laravel's `Crypt` payload (base64 JSON `{iv,value,mac}`, AES-256-CBC,
HMAC-SHA256 over `iv+value` under `APP_KEY`) is read by
`LegacyLaravelDecryptor`. New writes use `SodiumFieldCipher`
(XChaCha20-Poly1305-IET, `v1:` + base64(nonce‖ciphertext) under
`PAYMENT_ENCRYPTION_KEY`, per-field AAD `"{table}.{field}:{id}"`).
`PaymentPayloadCodec` routes by prefix: `v1:` → sodium, everything else →
Laravel decrypt — **so the app can read old rows immediately with both
keys set, before any rewrite**.

Fields covered (`LegacyPaymentReencryptor::FIELDS`):

| Table | Columns |
|---|---|
| `payment_sessions` | `session_token`, `receipt_token` |
| `payment_attempts` | `payload`, `receipt_token` |

Required env (placeholders only — never commit real keys):

```bash
PAYMENT_ENCRYPTION_KEY='base64:<32 random bytes, e.g. openssl rand -base64 32>'
LARAVEL_LEGACY_APP_KEY='base64:<exact decoded Laravel APP_KEY>'
```

`LARAVEL_LEGACY_APP_KEY` must decode to exactly 32 bytes or the service
fails construction.

## 4. Re-encrypt (dry-run gate → apply)

```bash
php bin/console payments:reencrypt-legacy                 # dry-run, read-only
php bin/console payments:reencrypt-legacy --apply         # writes v1: values
php bin/console payments:reencrypt-legacy --apply --batch-size=250
```

- Dry-run decrypts every legacy row and reports
  `readable / rewritten / unreadable` — it exits FAILURE if any row is
  unreadable. **Do not `--apply` until dry-run reports 0 unreadable**;
  an unreadable row means a wrong/missing legacy key or drifted data and
  `--apply` aborts on the first such row.
- `--apply` rewrites `v1:`-prefixed values per batch in a transaction;
  it is idempotent (`v1:` values are skipped) and resumable.
- `--batch-size` range 1–1000, default 100.

## 5. Reconciliation and rollback gates

Before flipping traffic (each gate must pass on the rehearsal copy first):

- `payments:reencrypt-legacy` dry-run → `unreadable = 0`, then `--apply`
  → `unreadable = 0`.
- `RUN_DB_TESTS=1 php bin/phpunit` against the migrated test DB green
  (auth, cart, order placement, admin status, profile, re-encrypt,
  Shop Pay, password reset suites all exercise these tables).
- Spot-check: login works (`password_hash`/`bcrypt` is shared), an old
  order renders `GET /api/v1/orders/{number}`, **authenticated** carts
  survive (they resolve through the `carts.user_id` FK), and `payments`
  rows still decrypt through the codec. Legacy **guest** carts do *not*
  remain reachable: guest lookup goes through the session-held
  `cart_token` → `carts.token`, and neither Laravel sessions nor their
  token linkage is portable — orphaned guest cart rows stay in the table
  unreachable (no reclaim command exists today; prune them deliberately
  if desired).
- Keep `LARAVEL_LEGACY_APP_KEY` configured until **every** legacy
  ciphertext field is verified `v1:` or provably empty — removing it
  early makes remaining legacy rows undecryptable.
- Rollback gate: Laravel can still run read-only against the same DB
  during the window; `v1:` values are unreadable to Laravel, so after
  `--apply`, rollback of the *payment-session read path* requires keeping
  the Laravel `Crypt` layer's writes off those columns or re-encrypting
  back — decide the point of no return before `--apply` on production.

Do not delete the Laravel codebase or its env until all gates pass and
the rollback window has closed. Note the compatibility migration itself
is irreversible by design — after it runs, rollback of the *schema* is
backup restore only (see `docs/operations.md`).
