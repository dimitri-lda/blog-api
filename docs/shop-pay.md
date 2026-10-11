# Shop Pay Wallet integration

Shop Pay appears only on the pending-order page (`Store/Order`) as the official
hosted popup, loaded from
`https://cdn.shopify.com/shopifycloud/shop-js/shop-pay-payment-request.js`.
The integration is disabled by default and activates only when every credential
below is configured. Orders are never marked paid from a browser event: only
Admin API reconciliation (`shop-pay:reconcile`, the reconcile endpoint, or the
`orders/create` webhook) can transition `pending_payment` to `paid`.

## Account setup

1. **Shopify store**: use a development or production store where you can install
   apps and edit payment settings.
2. **Shopify Payments test mode**: in *Settings → Payments* activate Shopify
   Payments and enable test mode so card payments can be simulated.
3. **Shop channel / origin allow-list**: during Shop Pay Wallet onboarding
   (`shop.app` partner onboarding), register this site's exact origin
   (e.g. `https://blog-api.local`). Shop Pay only renders on allow-listed
   origins.
4. **Shop ID and Client ID**: the onboarding email provides the `clientId`.
   The numeric `shopId` comes from the Admin API `shop { id }` object (the
   integer id, not the gid).
5. **Private Storefront token**: create a private Storefront API access token
   for the store (Storefront API integration). It authenticates the
   `ShopPayPaymentRequestSessionCreate` / `...Submit` mutations server-side via
   the `Shopify-Storefront-Private-Token` header.
6. **Admin access token**: create a custom app in *Settings → Apps and sales
   channels → Develop apps* with an Admin API access token.
7. **Webhook secret**: the app's client secret (`SHOP_PAY_APP_CLIENT_SECRET`)
   verifies `X-Shopify-Hmac-Sha256` on incoming webhooks.
8. **Flag**: set `SHOP_PAY_ENABLED=true` only after every other variable is
   filled; otherwise endpoints keep returning 503.

Required `.env` variables (see `.env.example`):

```
SHOP_PAY_ENABLED=false
SHOP_PAY_SHOP_DOMAIN=your-store.myshopify.com
SHOP_PAY_API_VERSION=2026-07
SHOP_PAY_SHOP_ID=
SHOP_PAY_CLIENT_ID=
SHOP_PAY_STOREFRONT_PRIVATE_TOKEN=
SHOP_PAY_ADMIN_ACCESS_TOKEN=
SHOP_PAY_APP_CLIENT_SECRET=
```

## API scopes and webhook

- **Storefront API (private token)**: access to the
  `ShopPayPaymentRequestSessionCreate` / `...Submit` mutations is provisioned
  as part of Shop Pay Wallet onboarding rather than through a self-serve
  scope. Without that grant both session mutations fail with user errors.
- **Admin API token**: grant the permissions Shopify lists for Shop Pay
  receipt reconciliation, plus `read_orders` and `write_webhooks` only for
  what this integration uses. Confirm the exact granted scopes in the Dev
  Dashboard / onboarding materials — Shop Pay receipt queries are exclusive
  to Shop Component merchants.
- **Webhook**: subscribe to `ORDERS_CREATE` pointing at
  `POST /webhooks/shopify/orders` (JSON). You can create it in the Shopify
  admin (*Settings → Notifications → Webhooks*, signed with the app client
  secret) or via the `webhookSubscriptionCreate` Admin mutation implemented by
  `php artisan shop-pay:subscribe-webhooks`. The endpoint verifies the HMAC,
  deduplicates `X-Shopify-Webhook-Id`, and reconciles `shop-pay:*` sources.

## Sandbox checklist

- [ ] Store origin allow-listed with Shop Pay and page served over HTTPS.
- [ ] All credentials set, `SHOP_PAY_ENABLED=true`, `config:clear` run.
- [ ] Shopify Payments test mode enabled.
- [ ] Place a local order; the Shop Pay button renders on the pending-order
      page and the hosted popup opens.
- [ ] Address and delivery-method changes update totals via the quote
      endpoint (same country only).
- [ ] Test card completes; `paymentcomplete` fires and the page reconciles to
      paid via the Admin API — never directly from the browser event.
- [ ] `php artisan shop-pay:reconcile` (scheduled every five minutes) picks up
      processing sessions if the webhook is not configured.
- [ ] Webhook delivery for `orders/create` returns 200 and deduplicates
      retries.
