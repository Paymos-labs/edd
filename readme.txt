=== Paymos for Easy Digital Downloads ===
Contributors: paymos
Tags: payments, stablecoin, usdt, usdc, easy digital downloads
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.8.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept stablecoin payments in Easy Digital Downloads with Paymos hosted checkout.

== Description ==

The official package contains no merchant credentials. Open Downloads -> Settings -> Payments -> Paymos and click Connect Paymos. Approve the current project in Paymos; the plugin receives Sandbox and Live credentials once, stores them in an AES-256-GCM encrypted WordPress option, and discards the short-lived OAuth token.

Runtime Merchant API requests remain HMAC signed. Signed webhooks update invoices without a local invoice reconciliation job: a delivery that fails is retried by Paymos across roughly 16 hours, and anything that outlives the ladder is replayed from the Paymos dashboard. Pending refunds are reconciled locally by a 1-minute WP-Cron job (`paymos_edd_refunds_reconcile`).

== External services ==

Paymos handles payment requests for this Easy Digital Downloads gateway. To connect, click **Connect Paymos** in your WordPress admin and approve the connection in Paymos. Device authorization starts at `https://app.paymos.io/connect/cms/device`; the plugin polls `https://app.paymos.io/connect/token` for approval. The browser approval URL carries the plugin identifier, store origin, admin return URL, and `user_code`. The plugin sends the secret `device_code` to the token endpoint. It stores the returned Sandbox and Live credentials in AES-256-GCM-encrypted WordPress options and discards the short-lived OAuth token.

For payments, the plugin creates invoices at `https://api.paymos.io/v1/invoices`, checks them with `GET /v1/invoices/{invoice_id}`, and cancels them with `POST /v1/invoices/{invoice_id}/cancel`. Each signed request sends a key ID, timestamp, and HMAC signature. The API secret stays with the plugin for signing. Invoice fields include `project_id`, `amount`, `currency`, `external_order_id`, and, when available, the native customer ID as `client_id`. New orders use a random opaque reference. Existing references remain in use and can contain an EDD purchase key.

For refunds, the plugin sends `POST /v1/refunds` requests to `https://api.paymos.io/v1/refunds` with only `invoice_id`, `mode` (`full` or `overpayment`), and `external_order_id`; Paymos returns funds strictly to the verified `first_sender` address recorded on the invoice. The plugin checks individual refund status with `GET /v1/refunds/{refund_id}`, polls cursor-paginated refund pages with `GET /v1/refunds` (`cursor`, `limit`, `invoice_id`, and `status`), and in Sandbox mode simulates terminal outcomes with `POST /v1/sandbox/refunds/{refund_id}/simulate` sending `status` (`completed`, `failed`, or `cancelled`). Pending refunds are polled by a 1-minute WP-Cron job (`paymos_edd_refunds_reconcile`).

Buyers pay over HTTPS at `https://checkout.paymos.io`. Signed invoice and payment webhooks return to the store to update orders. Service terms and data handling appear in the [Terms](https://paymos.io/terms) and [Privacy Policy](https://paymos.io/privacy).

== Installation ==

1. Install and activate the official release.
2. Open the intended project in Paymos; that current project is used automatically.
3. Open Downloads -> Settings -> Payments -> Paymos.
4. Click Connect Paymos and approve the displayed store URL and current project.
5. Test in Sandbox, then switch to Live.

== Frequently Asked Questions ==

= Does the ZIP contain secrets? =

No. Every merchant downloads the same public package.

= Where are credentials stored? =

In a non-autoloaded WordPress option encrypted with AES-256-GCM using installation security salts.

== Changelog ==

= 1.8.8 =
* chore: rebuild canonical CMS package

= 1.8.7 =
* docs(plugins): add 6-language READMEs (EN/zh-CN/ru/es/de/tr) and YouTube walkthrough previews across all 25 Paymos-labs repos
* chore: bundle Paymos PHP SDK v1.13.0

= 1.8.6 =
* chore: rebuild canonical CMS package

= 1.8.5 =
* chore: bundle Paymos PHP SDK v1.13.0
* chore: rebuild canonical CMS package

= 1.8.4 =
* fix(plugins,sdk,ci): fix PrestaShop/Shopware6/CMS locale & scalar bugs, Python webhook digits, atomic mirror pushes, token redaction, and release CI reliability (BUG-1434..1437, BUG-1461..1467, BUG-1478..1481)
* chore: rebuild canonical CMS package

= 1.8.3 =
* chore: rebuild canonical CMS package

= 1.8.2 =
* chore: rebuild canonical CMS package

= 1.8.1 =
* fix(checkout): enforce PHP SDK compatibility across CMS packages
* refactor(checkout): remove product images from invoice products
* chore: bundle Paymos PHP SDK v1.11.1
* chore: rebuild canonical CMS package

= 1.8.0 =
* feat(plugins): add products[] to WooCommerce and EDD (9.1)
* chore: bundle Paymos PHP SDK v1.11.0
* chore: rebuild canonical CMS package

= 1.7.9 =
* chore: rebuild canonical CMS package

