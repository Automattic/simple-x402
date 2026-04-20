<?php
/**
 * Builds the x402 PaymentRequirements payload for a single request.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Services;

/**
 * Assembles the `PaymentRequirements` array that goes into the
 * PAYMENT-REQUIRED response header and JSON body.
 *
 * Base Sepolia + USDC are hardcoded here; swapping networks is intentionally
 * a code change (MVP constraint).
 */
final class PaymentRequirementsBuilder {

	private const NETWORK = 'eip155:84532';
	// phpcs:ignore PHPCompatibility.Miscellaneous.ValidIntegers.HexNumericStringFound -- EVM contract address literal, not a numeric string.
	private const ASSET          = '0x036CbD53842c5426634e7929541eC2318f3dCF7e';
	private const ASSET_DECIMALS = 6;
	private const SCHEME         = 'exact';
	private const MAX_TIMEOUT    = 120;

	/**
	 * Build a PaymentRequirements array.
	 *
	 * @param string $pay_to       Receiving wallet address (EVM).
	 * @param string $price        Decimal price in USDC, e.g. "0.01".
	 * @param string $resource_url Absolute URL being paywalled.
	 * @param string $description  Human-readable description.
	 */
	public function build(
		string $pay_to,
		string $price,
		string $resource_url,
		string $description
	): array {
		return array(
			'scheme'            => self::SCHEME,
			'network'           => self::NETWORK,
			'asset'             => self::ASSET,
			'payTo'             => $pay_to,
			'maxAmountRequired' => $this->to_base_units( $price ),
			'resource'          => $resource_url,
			'description'       => $description,
			'mimeType'          => 'application/json',
			'maxTimeoutSeconds' => self::MAX_TIMEOUT,
		);
	}

	/**
	 * Convert a decimal string amount into base units (atomic USDC units).
	 */
	private function to_base_units( string $decimal ): string {
		if ( ! is_numeric( $decimal ) || (float) $decimal <= 0 ) {
			return '0';
		}
		[ $whole, $frac ] = array_pad( explode( '.', $decimal, 2 ), 2, '' );
		$frac             = substr( $frac, 0, self::ASSET_DECIMALS );
		$frac             = str_pad( $frac, self::ASSET_DECIMALS, '0' );
		$combined         = ltrim( $whole . $frac, '0' );
		return '' === $combined ? '0' : $combined;
	}
}
