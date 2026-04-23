# Stub endpoints for the Simple x402 Jetpack integration

**Audience:** the a8c engineer adding the WP.com-side routes for `simple-x402-jetpack`.
**Goal for v0:** ship three stub endpoints under `/wpcom/v2/x402/` that the plugin can talk to, returning canonical happy-path JSON. No actual payment processing, no Coinbase CDP calls, no chain settlement. This unblocks the plugin team and validates that blog-token signing reaches us.

See `WPCOM_CONTRACT.md` in this directory for the full spec once real logic is added behind each route.

## The three stubs you need

All under the `wpcom/v2` REST namespace. Auth: standard Jetpack blog-token, same as every other `wpcom/v2/*` route.

### 1. `GET /wpcom/v2/x402/health`

Liveness probe. The plugin hits this from its "Test connection" button.

**Stub response (200):**
```json
{"ok": true, "stub": true}
```

The `stub: true` marker is optional but useful — lets the plugin team confirm they hit the stub vs. a real implementation later.

### 2. `POST /wpcom/v2/x402/verify`

Plugin sends a JSON body `{paymentRequirements: {...}, paymentPayload: {...}}`. For the stub, validate that both keys are present objects; don't inspect their contents.

**Stub response on valid body (200):**
```json
{"isValid": true, "invalidReason": null, "stub": true}
```

**Stub response on missing fields (400):** use the OAuth-style error shape (matches the plugin's preferred parser):
```json
{"error": "invalid_request", "error_description": "Missing paymentRequirements or paymentPayload"}
```

### 3. `POST /wpcom/v2/x402/settle`

Same request body shape as `/verify`.

**Stub response on valid body (200):**
```json
{
  "success": true,
  "transaction": "0xstub0000000000000000000000000000000000000000000000000000000000000001",
  "network": "base-sepolia",
  "errorReason": null,
  "stub": true
}
```

**Stub response on missing fields:** same 400 shape as `/verify`.

## Auth

Every request arrives **blog-token-signed** via `Automattic\Jetpack\Connection\Client::wpcom_json_api_request_as_blog()`. By the time your handler runs, wpcom's REST auth middleware has already resolved the signature into a `blog_id`. Follow whatever `permission_callback` pattern the team's other `wpcom/v2/*` routes use — the stub doesn't need to check anything beyond "blog-authed." For the stub, `__return_true` on the permission callback is fine **if** wpcom's routing layer pre-gates on auth; adjust for your conventions.

Unauthenticated requests should 401 with:
```json
{"error": "invalid_token", "error_description": "Invalid or expired access token"}
```
(The plugin already handles this shape.)

## Sketch (PHP)

Standard `register_rest_route` shape. Adjust for wpcom's specific endpoint-registration patterns (class-based, auto-loaded, whatever).

```php
add_action( 'rest_api_init', function () {
    register_rest_route( 'wpcom/v2', '/x402/health', [
        'methods'             => 'GET',
        'callback'            => 'simple_x402_health_stub',
        'permission_callback' => '__return_true', // gated upstream by blog auth
    ] );
    register_rest_route( 'wpcom/v2', '/x402/verify', [
        'methods'             => 'POST',
        'callback'            => 'simple_x402_verify_stub',
        'permission_callback' => '__return_true',
    ] );
    register_rest_route( 'wpcom/v2', '/x402/settle', [
        'methods'             => 'POST',
        'callback'            => 'simple_x402_settle_stub',
        'permission_callback' => '__return_true',
    ] );
} );

function simple_x402_health_stub( WP_REST_Request $r ) {
    return [ 'ok' => true, 'stub' => true ];
}

function simple_x402_verify_stub( WP_REST_Request $r ) {
    $body = $r->get_json_params();
    if ( ! is_array( $body )
      || ! isset( $body['paymentRequirements'] )
      || ! isset( $body['paymentPayload'] ) ) {
        return new WP_Error(
            'invalid_request',
            'Missing paymentRequirements or paymentPayload',
            [ 'status' => 400 ]
        );
    }
    return [ 'isValid' => true, 'invalidReason' => null, 'stub' => true ];
}

function simple_x402_settle_stub( WP_REST_Request $r ) {
    $body = $r->get_json_params();
    if ( ! is_array( $body )
      || ! isset( $body['paymentRequirements'] )
      || ! isset( $body['paymentPayload'] ) ) {
        return new WP_Error(
            'invalid_request',
            'Missing paymentRequirements or paymentPayload',
            [ 'status' => 400 ]
        );
    }
    return [
        'success'     => true,
        'transaction' => '0xstub' . str_repeat( '0', 58 ) . '1',
        'network'     => 'base-sepolia',
        'errorReason' => null,
        'stub'        => true,
    ];
}
```

## How to verify the stub works

A WP.com-hosted site with the following installed:

- [`simple-x402`](https://github.com/tellyworth/simple-x402/pull/12) (main paywall plugin)
- [`simple-x402-jetpack`](https://github.com/tellyworth/simple-x402/pull/14) (the companion plugin that hits your endpoints)

Zips are attachable from the PR branches' `composer install --no-dev` output.

Once both are activated:

1. In wp-admin, go to **Settings → Simple x402**.
2. Under **Facilitator**, pick "WordPress.com (via Jetpack)".
3. Set any wallet address in the field. Save.
4. Click **Test connection** under the facilitator dropdown.
5. Expected: `✓ Succeeded in Nms` (previously showed `✗ HTTP 404` because endpoints didn't exist).

If the stub returns 200 for `/health`, the plugin flips from a 404 failure to a success badge — confirms end-to-end plumbing works: plugin → Jetpack blog-token signing → WP.com auth → your route → response → plugin UI.

Push a test purchase through (optional, proves verify+settle stubs too):

```bash
# from the plugin's scripts/ dir, on any paywalled URL:
PRIVATE_KEY=0x... node pay.mjs https://testmdom.wordpress.com/some-paywalled-post
```

The bot's 402 → signed retry flow should now resolve against the stub and succeed.

## What the stub intentionally does NOT do

- **Call Coinbase CDP or any real facilitator.** No outbound HTTP, no API keys needed server-side.
- **Validate payment payloads.** It checks the keys exist; it doesn't check the signature is real or that the amount is right. Any object passes.
- **Track nonces or prevent replay.** Idempotency comes with the real implementation.
- **Emit a fake transaction that resolves on-chain.** The `transaction` hash is a placeholder string, not a real tx. If the plugin ever verifies-the-verification by looking up the tx, it won't find anything. (It doesn't today.)
- **Rate-limit.** Fine for dogfood traffic; real version needs the standard wpcom REST rate limits applied.

## When real logic replaces the stub

Preserve the request/response shapes documented in `WPCOM_CONTRACT.md` so the plugin doesn't need to change. The `stub: true` markers can go away. The `WP_Error` shape for malformed requests stays. The real implementation adds:

- Actual CDP facilitator dispatch (server-side credentials, not per-site).
- Real tx hashes, real network strings.
- Proper 403 `{code, message}` shapes for scope failures if/when an `x402` scope gets defined.
- Structured logging: `(site_id, timestamp, action, network, asset, amount, success, tx_hash, cdp_error_code)`.

## Rough effort

Three stubs as specified above: **~30 lines of PHP** plus whatever boilerplate your team's `wpcom/v2` routes need. Should be an afternoon including local testing against a site with the plugin installed.
