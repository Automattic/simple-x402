# x402 paid fetch (smoke helper)

Uses the **official** x402 Foundation packages (`@x402/fetch`, `@x402/evm`) — same stack as [Quickstart for Buyers](https://docs.x402.org/getting-started/quickstart-for-buyers).

```bash
cd scripts/x402-paid-fetch
npm install
export EVM_PRIVATE_KEY=0x...   # funded on the chain your resource requires
npm run paid-fetch -- 'https://your-wp-site.example/paywalled-path/'
```

Upstream examples (copy/paste starting points):  
[TypeScript fetch client](https://github.com/x402-foundation/x402/tree/main/examples/typescript/clients/fetch).
