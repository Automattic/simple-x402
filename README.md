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

## What it does

- Adds a `paywall` tag and category on activation.
- Adds a Settings → Simple x402 page with two fields: wallet address, default price.
- On singular views (single post, page, CPT, etc.), requires x402 for detected bots/crawlers (via `jaybizzle/crawler-detect`); humans still need the `paywall` tag or category on the content.
- On any frontend request that matches a rule, responds HTTP 402 with a `PAYMENT-REQUIRED` header and a JSON body, unless the request carries a valid `PAYMENT-SIGNATURE` (verified + settled via x402.org) or a live grant.

## Extending

See the `simple_x402_rule_for_request` filter in `src/Services/RuleResolver.php`.

## Changelog

### 0.1.0

- Initial MVP: paywall posts tagged or categorised `paywall`, pay with x402 on Base Sepolia via x402.org.
