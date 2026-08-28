# Paymos for Easy Digital Downloads

Official Paymos gateway for Easy Digital Downloads. The buyer picks it at checkout, pays a
stablecoin invoice on the Paymos hosted page, and returns to a pending order. The order becomes
`complete` — the status at which EDD releases the download links and sends its purchase receipt —
when a signed webhook reports the transfer confirmed on-chain. Returning from the payment page is
not what unlocks the file.

## Requirements

- WordPress 6.2 or newer, tested up to 7.0 — the floor in the plugin header of
  `paymos-easy-digital-downloads.php`
- Easy Digital Downloads, declared in that header as a required plugin. The order model this gateway
  writes to is the EDD 3.x one
- PHP 7.4 or newer with OpenSSL — credentials are sealed with AES-256-GCM
- A publicly reachable HTTPS site. The webhook is a REST route on your own domain, and deliveries
  are never sent to loopback, private or link-local addresses
- A Paymos account, with the dashboard already on the project this store sells through

## Install

Download `paymos-edd-<version>.zip` from
[Releases](https://github.com/Paymos-labs/edd/releases/latest), or pull the package from the
**CMS integration** panel in the Paymos dashboard. Install it from **Plugins → Add New Plugin →
Upload Plugin** — older WordPress labels that screen **Add New** — then activate it.

The Paymos PHP SDK travels inside the archive, so there is no Composer step. Every merchant
downloads the identical file, and it contains no API key, API secret, project id, webhook secret,
OAuth token or device code.

## Connect

Connecting needs EDD's `manage_shop_settings` capability — a shop manager or an administrator.

1. Put the Paymos dashboard on the project this store sells through — that one gets bound, and
   there is nothing to select later.
2. Go to **Downloads → Settings → Payments → Paymos** and press **Connect Paymos**.
3. Approve the site URL and project in the tab that opens. If the browser blocked it, the link and
   the user code appear next to the button instead.
4. **Connection status** fills in: active mode, whether Sandbox and Live are configured, the masked
   API key and the project id.

That one approval delivers Sandbox and Live together. Your single active Payment key is reused, or
created when there is none, and an Invoice webhook is registered at

```text
https://your-store.example/wp-json/paymos-edd/v1/webhook
```

An existing webhook is reused only when callback URL, category and project all match.

Credentials are stored in a non-autoloaded WordPress option, sealed with AES-256-GCM keyed from the
site's `auth` and `secure_auth` salts. The short-lived authorization token is discarded, and every
later Merchant API call is HMAC-signed.

## Settings

| Setting | Default |
|---|---|
| Checkout label | `Pay with stablecoins` |
| Mode | Sandbox |
| Webhook URL | Read-only; registered for you at connect |
| Connection status | Read-only |
| Debug logging | Off — writes redacted lines to PHP `error_log` |

There is no field for a secret and no project picker; both are provisioned by the approval.

## When the download unlocks

| Paymos event | EDD order |
|---|---|
| Buyer chooses Paymos, order recorded, cart emptied | `pending`, with a note carrying the invoice id |
| `invoice.confirming` | `pending` — "Paymos payment is confirming." |
| `invoice.underpaid_waiting` | `pending`, and the note names the amount still outstanding and its token |
| `invoice.awaiting_payment` | `pending`, and the note says a confirmed transfer was rolled back on-chain |
| `invoice.paid`, `invoice.paid_over` | `complete` — downloads released, receipt sent |
| `invoice.underpaid` | `failed` |
| `invoice.expired`, `invoice.cancelled` | `abandoned`, EDD's status for a pending order that never completed |

Completion writes the on-chain transaction hash as the EDD transaction id, plus `_paymos_tx_hash`
and `_paymos_explorer_url` meta. A second terminal event on an order that is already complete is
recognised as a re-completion and adds no duplicate note, while a later `confirming`, `underpaid`,
`expired` or `cancelled` is filed as disregarded and changes nothing.

An order whose amount or currency drifted away from the invoice is not completed at all. The meta
key `_paymos_amount_mismatch` is set to `yes`, the mismatch is written into the order notes, and a
human settles it. The download stays locked meanwhile.

## What the buyer sees afterwards

The purchase receipt and the order-receipt email each gain a **Payment confirmation** line: the
explorer link when the payload carried one, the bare transaction hash otherwise. Nothing is rendered
while there is no hash, which is the case for every sandbox order.

## Test before going live

1. Leave **Mode** on Sandbox and buy a download through the storefront.
2. Find that invoice in the Paymos dashboard while the dashboard is still in Sandbox, and drive it
   with **Pay Full**, **Pay 50%**, **Pay 150%** or **Cancel**. Real lifecycle events, no funds
   anywhere.
3. Follow the order: its status, its notes, and whether the download link is released.
4. Switch **Mode** to Live. One approval issued both credential sets, so no second setup exists.

## Webhooks

The route is registered on the WordPress REST API and accepts `POST` only. `X-Webhook-Signature` is
verified against the secrets for both environments at once, and the payload's own `is_test` flag is
then checked against whichever environment verified it — a Live payload cannot close a Sandbox order
and the reverse is refused just as hard. An `event_id` seen before is acknowledged and skipped.
Terminal events are read back from the Merchant API and compared with the order's stored project,
amount and currency before anything is written.

Failures answer with a status the sender can act on, because a non-2xx is what keeps the event in
the retry ladder: `401` for a bad signature or a stale timestamp, `400` when processing failed,
`500` when no webhook secret is stored. That ladder is 11 attempts across roughly 16 hours. This
plugin ships no local reconciliation job, so an event that outlives it is replayed by hand from the
Paymos dashboard.

## Troubleshooting

**The buyer paid and the file is still locked.** The order is waiting for a webhook that has not
arrived. Confirm the endpoint answers from outside the site — a security plugin, HTTP basic auth or
a "disable the REST API" switch blocks it silently, and deliveries do not follow redirects to a
different address.

**The endpoint answers `not_configured`.** No credentials are stored, so there is no secret to
verify against. Press **Connect Paymos** on the settings page and finish the approval.

**Everything stops after editing `wp-config.php`.** The credential envelope is keyed from the `auth`
and `secure_auth` salts. Rotating them, or restoring a database into a site with different salts,
makes it unreadable; reconnect to have a new envelope issued.

**Nothing works after moving the site to a new domain.** The approved site URL and the registered
webhook still point at the old host. Reconnect from the new one.

## Links

- Documentation: [paymos.io/docs/cms-easy-digital-downloads](https://paymos.io/docs/cms-easy-digital-downloads)
- Source and releases: [Paymos-labs/edd](https://github.com/Paymos-labs/edd)
- Support: [support@paymos.io](mailto:support@paymos.io)
