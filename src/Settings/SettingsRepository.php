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
 *  - wallet_address:           the site's Base Sepolia receiving address.
 *  - default_price:            USDC price applied to any paywalled request that
 *                              does not override it.
 *  - paywall_mode:             `category` or `all-posts`.
 *  - paywall_category_term_id: term_id of the category used in `category` mode.
 *                              Stable identity — survives renames in Settings →
 *                              Categories without any action from this plugin.
 */
final class SettingsRepository {

	public const OPTION_NAME      = 'simple_x402_settings';
	public const DEFAULT_PRICE    = '0.01';
	public const DEFAULT_CATEGORY = 'x402paywall';

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
	 * Configured paywall category term_id, or 0 if unset / invalid.
	 *
	 * Returns the stored value verbatim — callers that need a guaranteed-valid
	 * id should resolve the default via CategoryRepository::ensure_default_term_id().
	 * Activation and the delete-term guard keep the stored id pointing at a real
	 * term, so the happy path always returns a usable value.
	 */
	public function paywall_category_term_id(): int {
		$stored = get_option( self::OPTION_NAME, array() );
		return is_array( $stored ) ? (int) ( $stored['paywall_category_term_id'] ?? 0 ) : 0;
	}

	/**
	 * Sanitise raw input into the canonical storage shape. Safe to call from a
	 * `register_setting` sanitize_callback: it reads stored state but must not
	 * write (calling `update_option` here recurses).
	 *
	 * `paywall_category_term_id` is validated against `term_exists`. A missing
	 * or non-existent id becomes 0 — the UI only submits valid dropdown options,
	 * so invalid input here means a tampered POST.
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
		$term_id = (int) ( $input['paywall_category_term_id'] ?? 0 );
		if ( $term_id <= 0 || ! term_exists( $term_id, 'category' ) ) {
			$term_id = $this->paywall_category_term_id();
		}
		return array(
			'wallet_address'           => $wallet,
			'default_price'            => $price,
			'paywall_mode'             => $mode,
			'paywall_category_term_id' => $term_id,
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

	/**
	 * Replace just the paywall_category_term_id, preserving every other field.
	 *
	 * Deliberately bypasses sanitize(): callers reacting to an external taxonomy
	 * event (e.g. the delete-term guard) must not wipe unknown fields.
	 */
	public function set_paywall_category_term_id( int $term_id ): void {
		$stored                             = get_option( self::OPTION_NAME, array() );
		$stored                             = is_array( $stored ) ? $stored : array();
		$stored['paywall_category_term_id'] = $term_id;
		update_option( self::OPTION_NAME, $stored );
	}
}
