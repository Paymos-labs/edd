=== Paymos for Easy Digital Downloads ===
Contributors: paymos
Tags: payments, stablecoin, usdt, usdc, easy digital downloads
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept stablecoin payments in Easy Digital Downloads with Paymos hosted checkout.

== Description ==

The official package contains no merchant credentials. Open Downloads -> Settings -> Payments -> Paymos and click Connect Paymos. Approve the current project in Paymos; the plugin receives Sandbox and Live credentials once, stores them in an AES-256-GCM encrypted WordPress option, and discards the short-lived OAuth token.

Runtime Merchant API requests remain HMAC signed. Signed webhooks update payments and the reconciliation path recovers missed deliveries.

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

= 1.0.0 =
* Initial official release.
