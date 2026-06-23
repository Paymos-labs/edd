=== Paymos for Easy Digital Downloads ===
Contributors: paymos
Tags: easy-digital-downloads, payments, crypto, paymos, stablecoins
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Requires Plugins: easy-digital-downloads
Stable tag: 1.0.0
License: GPL-2.0-or-later

Accept USDT and USDC in Easy Digital Downloads — native stablecoin settlement, no chargebacks.

== Description ==

Paymos for Easy Digital Downloads creates a Paymos invoice during checkout and
redirects the customer to the hosted Paymos payment page. Customers pay in USDT
or USDC across 13 networks; the order is marked complete on a signed, reverse-
verified webhook.

The plugin supports:

* Easy Digital Downloads 3.x.
* One dashboard-generated ZIP with embedded sandbox and live Paymos credentials.
* Signed Paymos webhooks with timestamp validation and event_id deduplication.
* Reverse API verification for terminal invoice webhooks before changing payment state.
* Amount-change protection before marking EDD payments complete.
* On-chain transaction hash and explorer link on the purchase receipt and order emails.

The dashboard-generated ZIP includes Paymos credentials and the Paymos PHP SDK.
No Composer install is required on the WordPress server.

== Requirements ==

* WordPress 6.2 or newer.
* Easy Digital Downloads 3.x.
* PHP 7.4 or newer.
* HTTPS on checkout (TLS 1.2+).

== Installation ==

1. In Paymos Dashboard, open CMS and select Easy Digital Downloads.
2. Enter the public HTTPS store URL.
3. Download the generated ZIP.
4. Upload the ZIP through WordPress Plugins.
5. Activate the plugin.
6. Enable Paymos under Downloads -> Settings -> Payments.

Dashboard-generated archives include `paymos-config.php`, so merchants do not
paste API keys, API secrets, project IDs, webhook secrets, or base URLs manually.
The Paymos API host defaults to `https://api.paymos.io`.

== Webhook URL ==

The plugin receives Paymos webhooks at:

`/wp-json/paymos-edd/v1/webhook`

The full URL is shown in Downloads -> Settings -> Payments -> Paymos.
Dashboard-generated ZIPs register sandbox and live webhooks in Paymos automatically.

== Security ==

Webhook requests must include `X-Webhook-Signature`. The Paymos PHP SDK verifies
the signature as HMAC-SHA256 over `timestamp.raw_body`, trying the sandbox and
live webhook secrets from `paymos-config.php`; the secret that verifies determines
the authenticated environment. The plugin rejects invalid signatures, stale
timestamps, duplicate committed `event_id` values, and project or environment
mismatches before changing any EDD payment.

For terminal invoice statuses (`paid`, `paid_over`, `underpaid`, `expired`,
`cancelled`), the plugin also calls the Paymos API to confirm the current invoice
state before changing the payment. The event is committed to the dedup store only
after the payment update succeeds, so a failed attempt can still be retried.

== Changelog ==

= 1.0.0 =
* Initial release.
