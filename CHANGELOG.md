# Changelog

All notable changes to the Paymos for Easy Digital Downloads plugin are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public release history also lives at [paymos.io/changelog](https://paymos.io/changelog).

## [Unreleased]

## [1.3.20] - 2026-10-07

- chore: rebuild canonical CMS package

### Fixed
- Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A new invoice now resets the Paymos status recorded on the payment, so an
  event for it is never taken for a stale one after an earlier final status.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.

## [1.3.19] - 2026-10-07

- chore: rebuild canonical CMS package

### Fixed
- Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A new invoice now resets the Paymos status recorded on the payment, so an
  event for it is never taken for a stale one after an earlier final status.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.

## [1.3.18] - 2026-09-29

- chore: bundle Paymos PHP SDK v1.5.0

### Fixed
- Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A new invoice now resets the Paymos status recorded on the payment, so an
  event for it is never taken for a stale one after an earlier final status.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.

## [1.3.17] - 2026-09-26

- chore: bundle Paymos PHP SDK v1.4.4

### Fixed
- Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A new invoice now resets the Paymos status recorded on the payment, so an
  event for it is never taken for a stale one after an earlier final status.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.

## [1.3.16] - 2026-09-25

- docs(plugins): переводы сообщения о заблокированной замене счёта и сверка минимальных версий
- chore: rebuild canonical CMS package

### Fixed
- Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A new invoice now resets the Paymos status recorded on the payment, so an
  event for it is never taken for a stale one after an earlier final status.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.

## [1.3.15] - 2026-09-25

- chore: bundle Paymos PHP SDK v1.4.3
- chore: rebuild canonical CMS package

### Fixed
- Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A new invoice now resets the Paymos status recorded on the payment, so an
  event for it is never taken for a stale one after an earlier final status.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.

## [1.3.14] - 2026-09-25

- fix(plugins): BUG-103 вебхук, который ещё обрабатывается, больше не отвечается 200 «duplicate»
- fix(plugins): BUG-090 оплата больше не ведёт на истёкший или проваленный счёт Paymos
- fix(plugins): BUG-135 поздний нефинальный вебхук больше не оживляет проваленный или отменённый заказ
- chore: bundle Paymos PHP SDK v1.4.2

### Fixed
- Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).
- A late non-final webhook could reopen a finished order. Webhooks are
  delivered at least once and in no particular order, and only paid orders were
  guarded: an `invoice.underpaid_waiting` or `invoice.confirming` arriving after
  the invoice had already ended underpaid, expired or cancelled moved the order
  back into an open state. Nothing leaves a final status on the server, so once
  one is recorded for an invoice every later event for it is ignored and the
  final status stays recorded.
- A new invoice now resets the Paymos status recorded on the payment, so an
  event for it is never taken for a stale one after an earlier final status.
- A webhook retry that arrived while the first delivery was still being
  processed was answered 200 "duplicate". Paymos gives a delivery 10 seconds and
  retries, while a slow reverse-verification call can take longer; the retry was
  acknowledged as delivered, and if the first attempt then failed the event was
  lost. An event that is only locked, not yet committed, is now answered 409 so
  Paymos tries again, and the lock the first delivery holds is left alone.

## [1.3.13] - 2026-09-23

- chore: rebuild canonical CMS package

### Fixed
- Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).

## [1.3.12] - 2026-09-21

- chore: rebuild canonical CMS package

### Fixed
- Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).

## [1.3.11] - 2026-09-15

- chore: bundle Paymos PHP SDK v1.4.1

### Fixed
- Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).

## [1.3.10] - 2026-08-30

- chore: rebuild canonical CMS package

### Fixed
- Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).

## [1.3.9] - 2026-08-30

- fix(plugins): CMS marketplace readiness spec, phases 1-3
- chore: rebuild canonical CMS package

### Fixed
- Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).

## [1.3.8] - 2026-08-30

- chore: rebuild canonical CMS package

### Fixed
- Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).

## [1.3.7] - 2026-08-28

- release: the changelog rot had a cause, and it was not the one I named
- audit: the shipped plugin and SDK docs described a product we stopped shipping
- docs(plugins): eight README stubs become the front pages they already were
- fix(i18n): unblock the production build — the gate was right, the map was stale
- docs(plugins): the changelogs stopped in June and the audit never reached them
- chore: bundle Paymos PHP SDK v1.4.0
- chore: rebuild canonical CMS package

### Fixed
- Entries that were present, non-empty and still English — `Connect Paymos` in
  German and Spanish, the plugin name in Turkish and Chinese, `Webhook URL` in
  Chinese — and one string missing from every catalogue
  (`in the invoice currency`, the fallback in the underpayment notice).

## [1.3.6] - 2026-08-08

- fix(plugins): make the six shipped locales actually reach the merchant
- chore: rebuild canonical CMS package

## [1.3.5] - 2026-08-08

- chore: bundle Paymos PHP SDK v1.3.2

## [1.3.4] - 2026-08-07

- chore: rebuild canonical CMS package

## [1.3.3] - 2026-08-07

- chore: rebuild canonical CMS package

## [1.3.2] - 2026-08-07

- fix(plugins): tell the merchant an invoice was underpaid, not confirming
- fix(plugins): open the approval tab in the six remaining CMS plugins

## [1.3.1] - 2026-08-07

- chore: bundle Paymos PHP SDK v1.3.1

## [1.3.0] - 2026-08-06

- feat(locales): Spanish blog and plugin catalogs
- feat(locales): German blog corpus, plugin catalogs and bot text
- feat(locales): tr + zh-Hans platform rollout — resx, bots, plugins
- chore: bundle Paymos PHP SDK v1.3.0

## [1.2.0] - 2026-08-03

- Merge remote-tracking branch 'origin/main'
- feat: consolidate BotexV2, Rentron, and ecosystem updates
- chore: bundle Paymos PHP SDK v1.3.0
- chore: rebuild canonical CMS package

## [1.1.2] - 2026-08-02

- chore: rebuild canonical CMS package

## [1.1.1] - 2026-08-02

- fix(ecosystem): recover SDK releases
- chore: bundle Paymos PHP SDK v1.2.1
- chore: rebuild canonical CMS package

## [1.1.0] - 2026-07-21

- feat(docs): make the developer surface consumable by LLM agents
- chore: bundle Paymos PHP SDK v1.2.0
- chore: rebuild canonical CMS package

## [1.0.6] - 2026-07-19

- chore: bundle Paymos PHP SDK v1.1.1

## [1.0.5] - 2026-07-13

- chore: rebuild canonical CMS package

## [1.0.4] - 2026-07-12

- fix(plugins): align CMS guidance with secure Connect

## [1.0.3] - 2026-07-12

- chore: rebuild canonical CMS package

## [1.0.2] - 2026-07-12

- chore: rebuild canonical CMS package

## [1.0.1] - 2026-07-12

- fix(release): align package stamping and webhook fixtures
- chore: rebuild canonical CMS package

## [1.0.0] - 2026-06-22

### Added
- Initial release.
- USDT and USDC payments across 13 mainnet networks via the hosted Paymos checkout.
- Payment gateway for Easy Digital Downloads 3.x.
- Pre-registered webhook endpoint with HMAC-SHA256 (`X-Webhook-Signature`) verification and reverse-verification of terminal events.
- Idempotent webhook processing with event-id dedup and a roll-back guard that protects a completed payment from a late downgrade.
- Russian localization (35 strings, premium-fintech tone).
- API credentials and signing secret pre-injected by the dashboard ZIP generator (the merchant types nothing).
- Sandbox / Live mode switch in the EDD admin.
