# Changelog

All notable changes to the Paymos for Easy Digital Downloads plugin are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and
this project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

The public release history also lives at [paymos.io/changelog](https://paymos.io/changelog).

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
