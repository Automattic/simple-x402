#!/usr/bin/env node
/**
 * Official x402 buyer client (fetch) — pays a 402-gated URL on testnet/mainnet
 * using whatever network the server advertises in PAYMENT-REQUIRED (e.g. Base Sepolia).
 *
 * Prereq: npm install (in this directory).
 *
 * Usage:
 *   export EVM_PRIVATE_KEY=0x...   # test wallet with USDC on the chain the resource requires
 *   node paid-fetch.mjs 'https://your-site.example/path/'
 */

import { wrapFetchWithPayment } from '@x402/fetch';
import { x402Client, x402HTTPClient } from '@x402/core/client';
import { ExactEvmScheme } from '@x402/evm/exact/client';
import { privateKeyToAccount } from 'viem/accounts';

const url = process.argv[2];
const pk = process.env.EVM_PRIVATE_KEY;

if (!url) {
	console.error('Usage: EVM_PRIVATE_KEY=0x... node paid-fetch.mjs <url>');
	process.exit(1);
}
if (!pk) {
	console.error('Missing EVM_PRIVATE_KEY in environment.');
	process.exit(1);
}

const signer = privateKeyToAccount(/** @type {`0x${string}`} */ (pk.startsWith('0x') ? pk : `0x${pk}`));

const client = new x402Client();
// Wildcard: pay any EVM network the resource advertises (Base Sepolia, Base mainnet, etc.).
client.register('eip155:*', new ExactEvmScheme(signer));

const fetchWithPayment = wrapFetchWithPayment(fetch, client);

const response = await fetchWithPayment(url, { method: 'GET' });
const text = await response.text();

console.log('HTTP', response.status, response.statusText);
console.log('--- Body (first 3000 chars) ---\n');
console.log(text.slice(0, 3000));

if (response.ok) {
	const httpClient = new x402HTTPClient(client);
	const settled = httpClient.getPaymentSettleResponse((name) => response.headers.get(name));
	console.log('\n--- Payment settle header (if present) ---\n');
	console.log(settled ?? '(none)');
}
