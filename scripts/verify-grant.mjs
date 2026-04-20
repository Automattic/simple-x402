#!/usr/bin/env node
/**
 * GET the same URL with X-Wallet-Address — proves a stored grant for that path + wallet.
 * Requires Node 18+. No npm dependencies.
 *
 * Usage: node scripts/verify-grant.mjs 'https://example.test/path/' '0xPayerWallet'
 */

const url = process.argv[2];
const wallet = process.argv[3];
if (!url || !wallet) {
	console.error('Usage: node scripts/verify-grant.mjs <url> <0xWalletAddress>');
	process.exit(1);
}

async function main() {
	const res = await fetch(url, {
		headers: { 'X-Wallet-Address': wallet },
		redirect: 'manual',
	});
	const text = await res.text();
	console.log('HTTP', res.status, res.statusText);
	console.log('Body length:', text.length);
	if (res.status === 402) {
		console.log('\nInterpretation: still paywalled (no grant, wrong path, wrong wallet, or TTL expired).');
	} else if (res.status >= 200 && res.status < 300) {
		console.log('\nInterpretation: 2xx — grant hit, or URL is not paywalled for this request.');
	}
	console.log('\n--- Body (first 800 chars) ---\n');
	console.log(text.slice(0, 800));
}

main().catch((e) => {
	console.error(e);
	process.exit(1);
});
