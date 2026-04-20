#!/usr/bin/env node
/**
 * Fetch a URL and print HTTP status, PAYMENT-REQUIRED header (decoded JSON), and body preview.
 * Requires Node 18+ (global fetch). No npm dependencies.
 *
 * Usage: node scripts/inspect-402.mjs 'https://example.test/paywalled-post/'
 */

const url = process.argv[2];
if (!url) {
	console.error('Usage: node scripts/inspect-402.mjs <url>');
	process.exit(1);
}

function getPaymentRequiredHeader(headers) {
	const keys = ['payment-required', 'PAYMENT-REQUIRED', 'Payment-Required'];
	for (const k of keys) {
		const v = headers.get(k);
		if (v) {
			return v;
		}
	}
	// Some stacks expose mixed casing in raw form; iterate as fallback.
	for (const [name, value] of headers) {
		if (name.toLowerCase() === 'payment-required') {
			return value;
		}
	}
	return null;
}

async function main() {
	const res = await fetch(url, { redirect: 'manual' });
	console.log('HTTP', res.status, res.statusText);

	const pr = getPaymentRequiredHeader(res.headers);
	if (pr) {
		const raw = pr.trim();
		console.log('\n--- PAYMENT-REQUIRED (base64, first 100 chars) ---\n');
		console.log(raw.slice(0, 100) + (raw.length > 100 ? '...' : ''));
		try {
			const json = JSON.parse(Buffer.from(raw, 'base64').toString('utf8'));
			console.log('\n--- PAYMENT-REQUIRED (decoded JSON) ---\n');
			console.log(JSON.stringify(json, null, 2));
		} catch (e) {
			console.error('\nDecode failed:', e.message);
		}
	} else {
		console.log('\n(No PAYMENT-REQUIRED header.)');
	}

	const text = await res.text();
	console.log('\n--- Body (first 2000 chars) ---\n');
	console.log(text.slice(0, 2000));
}

main().catch((e) => {
	console.error(e);
	process.exit(1);
});
