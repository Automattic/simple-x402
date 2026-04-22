# Simple x402

Minimal WordPress plugin that gates selected posts behind an x402 payment using the public x402.org facilitator on Base Sepolia.

## Status

MVP. Bots/API clients only — there is no human checkout UI.

## Requirements

- PHP 8.1+
- WordPress 6.4+
- Composer (for development)

## Install (development)

```bash
composer install
composer test
composer lint
```

## Test client

A small Node script under `scripts/` walks the full `402` → sign → retry flow against a paywalled URL on Base Sepolia.

```bash
cd scripts
npm install                                       # one time
PRIVATE_KEY=0x... node pay.mjs <paywalled-url>
```

The wallet must hold Base Sepolia USDC. No ETH needed — the x402.org facilitator pays gas via EIP-3009 `transferWithAuthorization`.

## What it does

- Adds an `x402paywall` category on activation (distinctive so it won't collide with existing editorial categories).
- Adds a Settings → Simple x402 page with: wallet address, default price, paywall audience, paywall mode, paywall category (picked from existing categories).
- **Mode** decides which posts qualify:
  - **No posts** (default): paywall disabled; pick another option to turn it on.
  - **All posts**: gate every published post of type `post`.
  - **Category**: gate only posts assigned to the configured category.
- **Audience** decides who sees the paywall once a post qualifies:
  - **Everyone**: humans and detected bots.
  - **Only bots/crawlers** (default): bots only, via `jaybizzle/crawler-detect`.
- On any frontend request that matches both audience and mode, responds HTTP 402 with a `PAYMENT-REQUIRED` header and a JSON body, unless the request carries a valid `PAYMENT-SIGNATURE` (verified + settled via x402.org) or a live grant.

Renaming the category in settings does not relabel existing posts — reassign them yourself if you rename.

## Extending

See the `simple_x402_rule_for_request` filter in `src/Services/RuleResolver.php`.

## Suggested improvements

- **Search-engine bots** — Today, detected crawlers get the same JSON 402 as other clients, which may hurt indexing. Consider treating verified search bots differently, e.g. returning `200` with a short excerpt, summary, or `meta description` in the body instead of a bare 402 (policy and implementation TBD).

## Changelog

### 0.1.0

- Initial MVP: paywall posts by category (configurable) or gate all posts for humans; gate singular views for detected bots/crawlers; pay with x402 on Base Sepolia via x402.org.
