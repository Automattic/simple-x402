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

	public const OPTION_NAME      = 'simple_x402_settings';
	public const DEFAULT_PRICE    = '0.01';
	public const DEFAULT_CATEGORY = 'paywall';

	public const MODE_CATEGORY  = 'category';
	public const MODE_ALL_POSTS = 'all-posts';
	public const VALID_MODES    = array( self::MODE_CATEGORY, self::MODE_ALL_POSTS );
	public const DEFAULT_MODE   = self::MODE_CATEGORY;

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
	 * Configured paywall selection mode, falling back to DEFAULT_MODE if unset or invalid.
	 */
	public function paywall_mode(): string {
		$stored = get_option( self::OPTION_NAME, array() );
		$mode   = is_array( $stored ) ? (string) ( $stored['paywall_mode'] ?? '' ) : '';
		return in_array( $mode, self::VALID_MODES, true ) ? $mode : self::DEFAULT_MODE;
	}

	/**
	 * Configured category term used by `category` mode, falling back to DEFAULT_CATEGORY.
	 */
	public function paywall_category(): string {
		$stored = get_option( self::OPTION_NAME, array() );
		$term   = is_array( $stored ) ? trim( (string) ( $stored['paywall_category'] ?? '' ) ) : '';
		return '' === $term ? self::DEFAULT_CATEGORY : $term;
	}

	/**
	 * Sanitise raw input into the canonical storage shape. Safe to call from a
	 * `register_setting` sanitize_callback: it reads stored state but must not
	 * write (calling `update_option` here recurses).
	 *
	 * For `paywall_category` we distinguish *absent* from *present+empty*:
	 *  - absent (key missing, e.g. the UI disabled the input) → preserve stored
	 *  - present+empty (admin cleared the field)              → apply default
	 *
	 * Conflating these caused the "switch to All posts silently resets your
	 * category" bug, because a disabled input is omitted from POST entirely.
	 *
	 * @param array $input Raw input.
	 */
	public function sanitize( array $input ): array {
		$wallet = isset( $input['wallet_address'] ) ? trim( (string) $input['wallet_address'] ) : '';
		$price  = isset( $input['default_price'] ) ? trim( (string) $input['default_price'] ) : '';
		if ( ! is_numeric( $price ) || (float) $price <= 0 ) {
			$price = self::DEFAULT_PRICE;
		}
		$mode = isset( $input['paywall_mode'] ) ? (string) $input['paywall_mode'] : '';
		if ( ! in_array( $mode, self::VALID_MODES, true ) ) {
			$mode = self::DEFAULT_MODE;
		}
		if ( array_key_exists( 'paywall_category', $input ) ) {
			$category = trim( (string) $input['paywall_category'] );
			if ( '' === $category ) {
				$category = self::DEFAULT_CATEGORY;
			}
		} else {
			$category = $this->paywall_category();
		}
		return array(
			'wallet_address'   => $wallet,
			'default_price'    => $price,
			'paywall_mode'     => $mode,
			'paywall_category' => $category,
		);
	}

	/**
	 * Persist settings from raw input. For programmatic use; the Settings API
	 * must use `sanitize()` instead (it handles persistence itself).
	 *
	 * @param array $input Raw input.
	 */
	public function save( array $input ): void {
		update_option( self::OPTION_NAME, $this->sanitize( $input ) );
	}
}
