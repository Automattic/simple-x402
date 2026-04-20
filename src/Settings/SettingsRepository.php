<?php
/**
 * Plugin-wide settings accessor backed by the WordPress options API.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Settings;

/**
 * Thin wrapper around a single wp_options row.
 *
 * Stores:
 *  - wallet_address: the site's Base Sepolia receiving address.
 *  - default_price:  USDC price applied to any paywalled request that does
 *                    not override it.
 */
final class SettingsRepository {

	public const OPTION_NAME   = 'simple_x402_settings';
	public const DEFAULT_PRICE = '0.01';

	/**
	 * Configured receiving wallet address, or '' if not set.
	 */
	public function wallet_address(): string {
		$stored = get_option( self::OPTION_NAME, array() );
		return is_array( $stored ) ? (string) ( $stored['wallet_address'] ?? '' ) : '';
	}

	/**
	 * Configured default price, falling back to DEFAULT_PRICE if unset or invalid.
	 */
	public function default_price(): string {
		$stored = get_option( self::OPTION_NAME, array() );
		$price  = is_array( $stored ) ? (string) ( $stored['default_price'] ?? '' ) : '';
		if ( ! is_numeric( $price ) || (float) $price <= 0 ) {
			return self::DEFAULT_PRICE;
		}
		return $price;
	}

	/**
	 * Persist settings from user input.
	 *
	 * @param array $input Raw input, typically from the settings form.
	 */
	public function save( array $input ): void {
		$wallet = isset( $input['wallet_address'] ) ? trim( (string) $input['wallet_address'] ) : '';
		$price  = isset( $input['default_price'] ) ? trim( (string) $input['default_price'] ) : '';
		if ( ! is_numeric( $price ) || (float) $price <= 0 ) {
			$price = self::DEFAULT_PRICE;
		}
		update_option(
			self::OPTION_NAME,
			array(
				'wallet_address' => $wallet,
				'default_price'  => $price,
			)
		);
	}
}
