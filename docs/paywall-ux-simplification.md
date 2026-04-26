# Paywall UX simplification — product spec & implementation checklist

WordPress plugin only. Dotcom / Jetpack facilitator / ledger services are out of scope here.

## Goals

1. **402 with negotiated body:** JSON (current shape) or minimal **HTML** (excerpt + payment notice), driven by client signals—not one body for everyone.
2. **Smarter client classification:** Combine **CrawlerDetect** with **`Accept`**, **`Sec-Fetch-Mode`**, **`Sec-Fetch-Dest`** to separate “JSON / API-style” clients from “HTML document” clients (especially among bots).
3. **Implicit audience:** **Humans always get full content**; only **bots / API-style clients** hit the paywall. Remove or hide the **audience** setting (no human payment path today).
4. **Single facilitator path:** Product UX assumes **Jetpack + pooled managed** `payTo`—no facilitator picker for normal installs (dev escape hatches may remain via env / filters).
5. **Admin probes:** One primary control (e.g. **“Run checks”**) that runs **facilitator test** and **paywall probe** in a single flow (order: facilitator first, then paywall—or document chosen order).

---

## Behaviour matrix (authoritative)

| Client | Paywall? | On block |
|--------|-----------|----------|
| **Non-bot** (not classified as a bot) | **No** — always **full content** | — |
| **Bot + HTML document signals** | Yes | **402** + `Content-Type: text/html` — **excerpt-only** template + short text that payment is required (amount + pointer to x402 / `PAYMENT-REQUIRED` headers). |
| **Bot + JSON / API signals** (see classification) | Yes | **402** + `Content-Type: application/json` — preserve / evolve current JSON + `PAYMENT-REQUIRED` header behaviour. |

**Status code:** Always **402** when the paywall blocks (including HTML path).

---

## Classification order (v1)

Apply in order; first strong match wins where noted; otherwise combine bot flag with “preferred response family”.

1. **`Sec-Fetch-Mode` / `Sec-Fetch-Dest`** (when present): **`navigate` + `document`** → treat as **HTML document** intent (typical browser navigation).
2. **`Accept`:** contains **`application/json`** or a **`+json`** subtype → **JSON** intent for the response body when blocking.
3. **`User-Agent`:** **CrawlerDetect** → **bot** vs non-bot.

**Heuristic defaults:**

- **Non-bot** → never paywalled (full content).
- **Bot + HTML document intent** → if paywalled, **HTML 402** excerpt path.
- **Bot + JSON intent** (or API-style `Accept` without document navigation) → if paywalled, **JSON 402** path.
- **Bot with ambiguous signals:** prefer **JSON** if `Accept` strongly suggests API; else **HTML** if document-like fetch metadata exists; else define a safe default (document in issue/PR—suggest **JSON** to match current ecosystem expectations for unknown bots).

**Edge case (explicit):** Non-crawler **API clients** (automation, `curl`, scripts) with `Accept: application/json` may need to be paywalled with **JSON 402** even when CrawlerDetect is false—product call: either extend “paywall applies” beyond strict bot UA, or document that only crawler UA + JSON `Accept` gets JSON path. **Recommendation:** allow paywall for **JSON-first non-navigate** requests on in-scope URLs even without bot UA, **or** add a separate “API paywall” flag later; v1 can start **strict bot-only** paywall application and still use `Accept` only **among bots** to choose JSON vs HTML body.

---

## Implementation phases (suggested PRs)

### Phase A — Request plumbing & classifier (no UX change to 402 body yet optional)

- [x] Extend `Plugin::collect_headers()` / `PaywallController` request array to include **`Accept`**, **`Sec-Fetch-Mode`**, **`Sec-Fetch-Dest`** (canonical header names already normalized).
- [x] New small service (`PaywallClientProfile`), with pure PHP + unit tests:
  - inputs: `User-Agent`, `Accept`, `Sec-Fetch-Mode`, `Sec-Fetch-Dest` (and optionally `X-Requested-With`);
  - outputs (for Phase B): `is_bot`, `document_navigation_intent`, `json_accept_intent`, `xml_http_request` (see class docblock; no `prefers_*` until ambiguous-bot policy lands).
- [x] Thread profile into `RuleResolver` / `DefaultPaywallRule` **or** only into `PaywallController` after rule match—pick the smallest coupling (likely controller + rule context).

### Phase B — 402 response negotiation

- [ ] Split `PaywallController::respond_402` (or parallel paths) to emit:
  - same **402** + **`PAYMENT-REQUIRED`** header;
  - **JSON** body (existing shape) vs **HTML** minimal template.
- [ ] HTML template: post **excerpt** (from `$post_id` / queried post), site title optional, payment line with **configured price** + note to inspect x402 headers.
- [ ] Filters, e.g. `simple_x402_paywall_html_402_body` / `simple_x402_paywall_excerpt_text` (exact names TBD in PR) for themes.
- [ ] Integration tests: header `Accept` + bot UA → JSON body; Sec-Fetch navigate + bot UA → HTML contains excerpt substring.

### Phase C — Audience & facilitator simplification

- [ ] **Audience:** remove from admin UI; stop reading stored `paywall_audience` for product behaviour **or** hard-default to “bots only” and migrate old `everyone` rows; optional `simple_x402_paywall_audience` filter for rare overrides.
- [ ] **Facilitator:** single code path—constant connector id (`wpcom_x402`), hide facilitator card / wallet for managed pool; keep **dev** path (env, `SIMPLE_X402_*`, or mu-plugin) if needed for local testing without Jetpack.
- [ ] Update **README**, **PaywallIndicator** copy, and any **REST/settings** payloads.

### Phase D — Admin “Run checks”

- [ ] Replace two separate probe entry points with one **primary** action that runs facilitator test then paywall probe; keep detailed step output (expandable or two lines under one button).
- [ ] i18n strings; avoid losing nonce / error surfacing from current flows.

---

## Code touchpoints (non-exhaustive)

| Area | Files / symbols |
|------|------------------|
| Headers | `src/Plugin.php` (`collect_headers`) |
| 402 orchestration | `src/Http/PaywallController.php` |
| Rule / audience | `src/Services/DefaultPaywallRule.php`, `src/Services/RuleResolver.php`, `src/Settings/SettingsRepository.php` |
| Bot UA | `src/Services/BotDetector.php` — compose with new classifier or inject |
| Admin UI | `assets/src/index.jsx`, `src/Admin/SettingsPage.php`, `src/Admin/PaywallProbeAjax.php`, `src/Admin/TestConnectionAjax.php` |
| Managed pool | `src/Services/FacilitatorHooks.php`, companion `JetpackSiteState.php` |

---

## Open items (resolve in first implementing PR)

1. **Strict bot-only vs JSON `Accept` for non-crawlers** — pick v1 rule (see edge case above).
2. **Ambiguous bot** default (JSON vs HTML) when both/neither signals present.
3. **HTML template** location: inline string in PHP vs small view file under `templates/` vs `wp_kses_post` + block template hook.

---

## Changelog

- **2026-04-26** — Phase A: `PaywallClientProfile` classifier, stable `Accept` / `Sec-Fetch-*` keys on paywall requests, `simple_x402_paywall_client_profile` filter (402 body unchanged).
- **2026-04-25** — Initial doc from agreed product decisions.
