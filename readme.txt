=== Paymos for Easy Digital Downloads ===
Contributors: paymos
Tags: payments, stablecoin, usdt, usdc, easy digital downloads
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept stablecoin payments in Easy Digital Downloads with Paymos hosted checkout.

== Description ==

The official package contains no merchant credentials. Open Downloads -> Settings -> Payments -> Paymos and click Connect Paymos. Approve the current project in Paymos; the plugin receives Sandbox and Live credentials once, stores them in an AES-256-GCM encrypted WordPress option, and discards the short-lived OAuth token.

Runtime Merchant API requests remain HMAC signed. Signed webhooks update payments. There is no local reconciliation job in this plugin: a delivery that fails is retried by Paymos across roughly 16 hours, and anything that outlives the ladder is replayed by hand from the Paymos dashboard.

== External services ==

Paymos handles payment requests for this Easy Digital Downloads gateway. To connect, click **Connect Paymos** in your WordPress admin and approve the connection in Paymos. Device authorization starts at `https://app.paymos.io/connect/cms/device`; the plugin polls `https://app.paymos.io/connect/token` for approval. The browser approval URL carries the plugin identifier, store origin, admin return URL, and `user_code`. The plugin sends the secret `device_code` to the token endpoint. It stores the returned Sandbox and Live credentials in AES-256-GCM-encrypted WordPress options and discards the short-lived OAuth token.

For payments, the plugin creates invoices at `https://api.paymos.io/v1/invoices`, checks them with `GET /v1/invoices/{invoice_id}`, and cancels them with `POST /v1/invoices/{invoice_id}/cancel`. Each signed request sends a key ID, timestamp, and HMAC signature. The API secret stays with the plugin for signing. Invoice fields include `project_id`, `amount`, `currency`, `external_order_id`, and, when available, the native customer ID as `client_id`. New orders use a random opaque reference. Existing references remain in use and can contain an EDD purchase key.

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

= 1.4.6 =
* chore: rebuild canonical CMS package

* Compare amounts as exact decimals and require complete invoice snapshots plus API confirmation before changing payment state.
* Serialize order updates and recover when native payment completion returns `false` from save or status updates.
* Check ownership when updating event reservations, and purge at most 100 expired records per reservation.

Fixed:
* Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
* A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
* A new invoice now resets the Paymos status recorded on the payment, so an
  event for it is never taken for a stale one after an earlier final status.
* A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.

= 1.4.5 =
* chore: rebuild canonical CMS package

* Compare amounts as exact decimals and require complete invoice snapshots plus API confirmation before changing payment state.
* Serialize order updates and recover when native payment completion returns `false` from save or status updates.
* Check ownership when updating event reservations, and purge at most 100 expired records per reservation.

Fixed:
* Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
* A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
* A new invoice now resets the Paymos status recorded on the payment, so an
  event for it is never taken for a stale one after an earlier final status.
* A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.

= 1.4.4 =
* fix(wordpress): keep compatibility declaration in readme only

* Compare amounts as exact decimals and require complete invoice snapshots plus API confirmation before changing payment state.
* Serialize order updates and recover when native payment completion returns `false` from save or status updates.
* Check ownership when updating event reservations, and purge at most 100 expired records per reservation.

Fixed:
* Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
* A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
* A new invoice now resets the Paymos status recorded on the payment, so an
  event for it is never taken for a stale one after an earlier final status.
* A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.

= 1.4.3 =
* chore: rebuild canonical CMS package

* Compare amounts as exact decimals and require complete invoice snapshots plus API confirmation before changing payment state.
* Serialize order updates and recover when native payment completion returns `false` from save or status updates.
* Check ownership when updating event reservations, and purge at most 100 expired records per reservation.

Fixed:
* Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
* A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
* A new invoice now resets the Paymos status recorded on the payment, so an
  event for it is never taken for a stale one after an earlier final status.
* A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.

= 1.4.2 =
* fix(plugins): harden WordPress payment recovery and release packages
* chore: bundle Paymos PHP SDK v1.6.1
* chore: rebuild canonical CMS package

* Compare amounts as exact decimals and require complete invoice snapshots plus API confirmation before changing payment state.
* Serialize order updates and recover when native payment completion returns `false` from save or status updates.
* Check ownership when updating event reservations, and purge at most 100 expired records per reservation.

Fixed:
* Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
* A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
* A new invoice now resets the Paymos status recorded on the payment, so an
  event for it is never taken for a stale one after an earlier final status.
* A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.

= 1.4.1 =
* Fix invoice-late-payment audit findings (tasks 4.1-4.8)

Fixed:
* Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
* A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
* A new invoice now resets the Paymos status recorded on the payment, so an
  event for it is never taken for a stale one after an earlier final status.
* A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.

= 1.4.0 =
* feat(invoices): accept full late payments without reopening invoices
* chore: bundle Paymos PHP SDK v1.6.0

Fixed:
* Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
* A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
* A new invoice now resets the Paymos status recorded on the payment, so an
  event for it is never taken for a stale one after an earlier final status.
* A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.

= 1.3.22 =
* chore: rebuild canonical CMS package

Fixed:
* Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
* A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
* A new invoice now resets the Paymos status recorded on the payment, so an
  event for it is never taken for a stale one after an earlier final status.
* A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.

= 1.3.21 =
* chore: rebuild canonical CMS package

Fixed:
* Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
* A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
* A new invoice now resets the Paymos status recorded on the payment, so an
  event for it is never taken for a stale one after an earlier final status.
* A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.

= 1.3.20 =
* chore: rebuild canonical CMS package

Fixed:
* Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
* A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
* A new invoice now resets the Paymos status recorded on the payment, so an
  event for it is never taken for a stale one after an earlier final status.
* A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.

