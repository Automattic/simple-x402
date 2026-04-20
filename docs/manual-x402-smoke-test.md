# Manual smoke test: 402 → pay → grant (testnet)

Step-by-step flow using **Base Sepolia** (what this plugin ships today) and the **official x402 buyer SDK**. No PHPUnit — this is against a real WordPress site and real facilitator traffic.

> **Chain note:** Simple x402 hardcodes **Base Sepolia** (`eip155:84532`) and USDC in `PaymentRequirementsBuilder`. To smoke-test **another EVM chain**, you must change that code (or test against a different x402 resource that advertises your target network). The buyer script below still works: it registers `eip155:*` and pays whatever network the **402 response** requires.

---

## 0. References (official)

- Buyers / clients: [Quickstart for Buyers](https://docs.x402.org/getting-started/quickstart-for-buyers) (`@x402/fetch`, `@x402/evm`, …).
- Example repo: [x402-foundation/x402 — `examples/typescript/clients/fetch`](https://github.com/x402-foundation/x402/tree/main/examples/typescript/clients/fetch).
- Facilitator (this plugin): **x402.org** on Base Sepolia — see [Facilitator](https://docs.x402.org/core-concepts/facilitator) in x402 docs.

There is **no separate “x402 CLI”** maintained as a first-class product in those docs; the supported path is **Node + `@x402/fetch`**, or **Go / Python** equivalents from the same org.

---

## 1. Prepare WordPress (Base Sepolia)

1. Install and activate **Simple x402**.
2. **Settings → Simple x402**
   - Set **Receiving wallet** to an address you control on **Base Sepolia** (receives USDC).
   - Set **Default price** (e.g. `0.01` USDC).
   - Ensure paywall mode / category so a **specific front-end URL** is gated (singular post, bot rule, etc.).
3. Copy the full **https** URL of that resource (include path; keep it stable for all steps below).

---

## 2. Prepare the payer wallet (Base Sepolia)

1. Create or use a **test EVM wallet** (export private key as `0x…`).
2. Fund it with **Base Sepolia USDC** (faucet + USDC faucet for the official test token matching `PaymentRequirementsBuilder`).
3. Export:

   ```bash
   export EVM_PRIVATE_KEY=0xYourTestnetPrivateKey
   ```

   Never commit this key. Use a throwaway test wallet only.

---

## 3. Phase A — Inspect 402 (optional)

Confirms the site returns **402** and a decodable **`PAYMENT-REQUIRED`** header (no payment, no key needed beyond hitting a public URL):

```bash
node scripts/inspect-402.mjs 'https://your-site.example/paywalled-path/'
```

Expect **HTTP 402**, decoded JSON with `network` **`eip155:84532`**, `asset`, `payTo`, `maxAmountRequired`, and body fields like `requirements`, `price`, `error`.

---

## 4. Phase B — Pay with the official x402 fetch client

From the repo (installs official packages into a small subfolder):

```bash
cd scripts/x402-paid-fetch
npm install
export EVM_PRIVATE_KEY=0x...   # same wallet as §2
node paid-fetch.mjs 'https://your-site.example/paywalled-path/'
```

What this does:

- Uses **`@x402/fetch`** `wrapFetchWithPayment` + **`@x402/evm`** `ExactEvmScheme` (see buyer quickstart).
- Registers **`eip155:*`** so the client can pay **whatever EVM network** the 402 advertises (here: Base Sepolia).
- On **402**, the client builds **`PAYMENT-SIGNATURE`**, calls the facilitator (**x402.org** for this plugin), retries the request, and prints the final status and body snippet.

Expect **HTTP 200** and HTML/JSON content from WordPress if verify + settle succeed.

Equivalent npm script:

```bash
npm run paid-fetch -- 'https://your-site.example/paywalled-path/'
```

---

## 5. Phase C — Grant (wallet header only)

After a successful paid request, the same path should allow access **without** a signature, using the **payer** address:

```bash
node scripts/verify-grant.mjs 'https://your-site.example/paywalled-path/' '0xYourPayerWallet'
```

Expect **200**, not **402**. Same URL string as in §4.

---

## 6. Troubleshooting

| Symptom | Check |
|--------|--------|
| 402 forever after `paid-fetch` | Payer has enough **USDC on Base Sepolia**; `payTo` in 402 matches settings; site can reach **https://x402.org/facilitator/** |
| `paid-fetch` throws “No scheme registered” | Ensure `@x402/evm` is installed and `eip155:*` registration is present (see `paid-fetch.mjs`) |
| Grant phase still 402 | **Exact same path** as payment; wallet matches payer; grant TTL not expired |
| Want another chain | Plugin must emit that `network` + `asset` in requirements; fund payer on that chain; same client pattern with `eip155:*` |

---

## 7. Repo tests vs this doc

PHPUnit uses **stubbed** `wp_remote_post` — no real facilitator. This document covers **live** Base Sepolia + **x402.org** + **@x402/fetch** behaviour end-to-end for humans running smoke tests.
