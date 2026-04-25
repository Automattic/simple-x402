# WPCOM-side contract for `simple-x402-jetpack`

This document describes what the `/wpcom/v2/x402/*` endpoints need to accept and return for the `simple-x402-jetpack` companion plugin to work end-to-end. The plugin already signs and dispatches the requests described below; all that's outstanding is the server implementation.

## Auth

Every request is a **Jetpack blog-token-authenticated** REST call — the plugin uses `Automattic\Jetpack\Connection\Client::wpcom_json_api_request_as_blog()` to sign. Routes should be registered under the `wpcom/v2` namespace with Jetpack's standard `jetpack` auth scheme, which resolves the blog token into a `blog_id` (and transitively an owning user).

Request scopes needed: reading `blog_id` from auth context. No additional OAuth scopes beyond what `global` already grants today. A narrower `x402` scope can be added later (PR against `class.oauth2-1-data.php`) — not blocking.

## Endpoints

All routes live under `/wpcom/v2/x402/`. The plugin sends JSON with `Content-Type: application/json`.

### `GET /wpcom/v2/x402/health`

Liveness probe for the admin-side "Test connection" button.

- **Request body:** none.
- **Success response (200):** any JSON body — the plugin only checks the HTTP code. A minimal `{"ok":true}` is enough.
- **Error response:** 4xx/5xx as appropriate. Plugin surfaces the HTTP code and any `{error, error_description}` or `{code, message}` body to the admin.

### `POST /wpcom/v2/x402/verify`

Verify that a submitted payment signature covers the stated payment requirements. The wpcom implementation proxies to an x402 facilitator (Coinbase CDP or equivalent) and returns the facilitator's verdict.

- **Request body:**
  ```json
  {
    "paymentRequirements": { ...x402 PaymentRequirements object... },
    "paymentPayload":      { ...decoded PAYMENT-SIGNATURE payload... }
  }
  ```
  Both sub-objects follow the [x402 protocol spec](https://x402.org) shapes. `paymentRequirements` is what the plugin originally put in the `PAYMENT-REQUIRED` header; `paymentPayload` is the base64-decoded body of the client's `PAYMENT-SIGNATURE` header.

- **Success response (200):**
  ```json
  {
    "isValid": true,
    "invalidReason": null
  }
  ```
  Or, on protocol-level failure (bad signature, expired validity, wrong asset):
  ```json
  {
    "isValid": false,
    "invalidReason": "signature_invalid"
  }
  ```
  The plugin only reads `isValid` and `invalidReason`; additional fields are fine and pass through in the raw response.

- **Error response (4xx/5xx):** service-level failures. Plugin propagates.

### `POST /wpcom/v2/x402/settle`

Settle a verified payment on-chain via the wpcom-managed facilitator.

- **Request body:** same shape as `/verify`:
  ```json
  {
    "paymentRequirements": { ... },
    "paymentPayload":      { ... }
  }
  ```

- **Success response (200):**
  ```json
  {
    "success": true,
    "transaction": "0xabc...def",
    "network": "base-sepolia",
    "errorReason": null
  }
  ```
  Or, on settle failure (insufficient balance, nonce reuse, chain reverted):
  ```json
  {
    "success": false,
    "transaction": null,
    "network": "base-sepolia",
    "errorReason": "insufficient_balance"
  }
  ```
  The plugin reads `success`, `transaction`, `network`, and `errorReason`.

- **Error response (4xx/5xx):** same treatment as verify.

## Error body shapes the plugin already handles

Two conventions, both get parsed into the plugin's `error` field for display to site admins:

1. **OAuth-compliant**, for token problems (401):
   ```json
   {"error": "invalid_token", "error_description": "Invalid or expired access token"}
   ```
   Surfaces as `invalid_token: Invalid or expired access token`.

2. **WPCOM-flavored**, for scope/authorization problems (403):
   ```json
   {"code": "unauthorized", "message": "Required scope: x402."}
   ```
   Surfaces as `Required scope: x402.`.

Anything else (missing both `error` and `message`) surfaces as `HTTP {status}`.

## Managed pool `payTo` (WordPress.com path)

For the `wpcom_x402` connector, the main plugin resolves `paymentRequirements.payTo` from the filter `simple_x402_managed_pool_pay_to` when non-empty. The Jetpack companion supplies an address from the environment variable `SIMPLE_X402_WPCOM_POOL_ADDRESS` (production would set this to the shared receive wallet). Site owners then **do not** enter a receiving wallet in wp-admin for that facilitator.

The facilitator `/verify` and `/settle` bodies are unchanged; `payTo` inside `paymentRequirements` must still match what the facilitator expects on-chain.

## Ledger reporting (site plugin)

After a successful settle, the main plugin:

1. Fires `do_action( 'simple_x402_payment_settled', $context )` with keys such as `connector_id`, `post_id`, `path`, `transaction`, `network`, `amount`, `resource_url`, `pay_to`, `payer_wallet`.
2. Optionally POSTs JSON to the URL returned by `apply_filters( 'simple_x402_ledger_report_url', '', $context )` (non-blocking). The companion does **not** set this filter; Dotcom can document a URL when the ledger API exists.

The plugin does **not** de-duplicate repeated `notify()` calls (same `transaction` may surface from retries or concurrency). The **ledger API** must treat `transaction` (or another stable id in `$context`) as idempotent so duplicate HTTP deliveries or duplicate hook-driven writes do not double-count.

If WordPress.com prefers to own attribution entirely, the wpcom `/settle` implementation can call the ledger instead and the site plugin can stop doing (2) or both — product decision, not wire format.

## What the plugin does NOT expect the wpcom side to do

- **On-chain fee splits.** The default integration is pass-through to `paymentRequirements.payTo` (seller wallet or managed pool address above). If a platform fee is eventually added (splitter contract, off-chain invoicing), it's a server-side concern and doesn't change this contract unless `paymentRequirements` grows a new field.
- **Scope-gating beyond what Jetpack already provides.** A dedicated `x402` scope can be added later but isn't required for v0.
- **Origin/referer validation for clone-DB protection.** The plugin doesn't include any origin signal beyond what Jetpack's signature carries. If clone-protection is desired, the wpcom endpoint stores `site_url` at first-settle and checks the `Origin`/`Referer` header against it on every subsequent call.

## What's verified on the plugin side (and you can assume works)

Confirmed live on a WordPress.com-hosted site with this plugin installed as of 2026-04-23:

- Jetpack `Client::wpcom_json_api_request_as_blog` signs the request correctly (WP.com returned 404 for the non-existent route, not 401).
- The `wpcom` API base + `2` version + `/x402/*` path resolves to `https://public-api.wordpress.com/wpcom/v2/x402/*`.
- Full round-trip latency (Jetpack signing + network + response) is ~170ms.
- The plugin's admin UI surfaces the HTTP code and error body to site owners.

## Testing recipe once endpoints land

1. Stand up the routes under `/wpcom/v2/x402/` following the above contract.
2. On any Jetpack-connected site with `simple-x402` + `simple-x402-jetpack` installed:
   - Settings → Simple x402 → select "WordPress.com (via Jetpack)".
   - Click "Test connection" — should flip from `✗ HTTP 404` to `✓ Succeeded in Nms`.
3. Trigger a paywalled request (bot with a signed `PAYMENT-SIGNATURE` header) and watch the admin log for verify/settle round-trips.
