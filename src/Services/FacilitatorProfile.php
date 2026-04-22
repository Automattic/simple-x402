<?php
/**
 * Per-mode x402 facilitator + network + asset configuration.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Services;

/**
 * Bundles every constant a mode needs to produce PaymentRequirements and
 * talk to a facilitator: the network identifier, the ERC-20 token contract
 * and its decimals, the facilitator endpoint, optional bearer auth, and the
 * EIP-712 domain fields the facilitator uses to reconstruct the signature
 * domain.
 *
 * Built once per request via `for_test()` / `for_live()`; passed into
 * PaymentRequirementsBuilder and X402FacilitatorClient.
 */
final class FacilitatorProfile {

	public const MODE_TEST = 'test';
	public const MODE_LIVE = 'live';

	public const LIVE_FACILITATOR_URL_DEFAULT = 'https://api.cdp.coinbase.com/platform/v2/x402/';

	public function __construct(
		public readonly string $mode,
		public readonly string $label,
		public readonly string $network,
		public readonly string $asset,
		public readonly int $asset_decimals,
		public readonly string $facilitator_url,
		public readonly string $eip712_name,
		public readonly string $eip712_version,
		public readonly string $api_key = ''
	) {}

	/**
	 * Profile for Base Sepolia USDC via the public x402.org facilitator.
	 */
	public static function for_test(): self {
		return new self(
			mode: self::MODE_TEST,
			label: 'Test',
			network: 'base-sepolia',
			// phpcs:ignore PHPCompatibility.Miscellaneous.ValidIntegers.HexNumericStringFound -- EVM contract address literal.
			asset: '0x036CbD53842c5426634e7929541eC2318f3dCF7e',
			asset_decimals: 6,
			facilitator_url: 'https://x402.org/facilitator/',
			eip712_name: 'USDC',
			eip712_version: '2',
		);
	}

	/**
	 * Profile for Base mainnet USDC via a configurable facilitator (Coinbase
	 * CDP by default). Requires an API key to actually settle — the caller
	 * enforces that; this factory does not.
	 *
	 * @param string $facilitator_url_override Blank uses the CDP default.
	 * @param string $api_key                  Bearer credential for the facilitator.
	 */
	public static function for_live( string $facilitator_url_override = '', string $api_key = '' ): self {
		return new self(
			mode: self::MODE_LIVE,
			label: 'Live',
			network: 'base',
			// phpcs:ignore PHPCompatibility.Miscellaneous.ValidIntegers.HexNumericStringFound -- EVM contract address literal.
			asset: '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
			asset_decimals: 6,
			facilitator_url: '' !== $facilitator_url_override
				? $facilitator_url_override
				: self::LIVE_FACILITATOR_URL_DEFAULT,
			// Base mainnet USDC's EIP-712 domain name is UNVERIFIED. The proxy
			// at 0x833589fCD… returns this from eip712Domain() on the current
			// implementation, but it has never been confirmed on-chain from
			// this codebase. If the name is wrong, every live-mode signature
			// will fail verification. Verify before shipping live mode.
			eip712_name: 'USD Coin',
			eip712_version: '2',
			api_key: $api_key,
		);
	}
}
