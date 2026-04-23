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
 * Schema:
 *   - wallet_address:           Receiving wallet for the selected facilitator.
 *                               Network is decided by the facilitator itself.
 *   - default_price:            Decimal USDC price per paywalled request.
 *   - selected_facilitator_id:  Connector ID of the x402 facilitator to
 *                               dispatch verify/settle through. '' = none
 *                               selected; paywall is inert until a facilitator
 *                               is chosen and the wallet is entered.
 *   - paywall_mode:             'none' | 'category' | 'all-posts'. Picks which
 *                               posts get gated.
 *   - paywall_audience:         'everyone' | 'bots'. Who sees the paywall.
 *   - paywall_category_term_id: term_id of the category used in `category` mode.
 *
 * Getters trust `sanitize()` as the only writer — they do not re-validate
 * stored values. Fresh installs return declared defaults; invalid data that
 * somehow lands in the option (external writes, DB corruption) passes through,
 * which surfaces bugs rather than silently masking them.
 */
final class SettingsRepository {

	public const OPTION_NAME      = 'simple_x402_settings';
	public const DEFAULT_PRICE    = '0.01';
	public const DEFAULT_CATEGORY = 'x402paywall';

	public const PAYWALL_MODE_NONE      = 'none';
	public const PAYWALL_MODE_CATEGORY  = 'category';
	public const PAYWALL_MODE_ALL_POSTS = 'all-posts';
	public const VALID_PAYWALL_MODES    = array(
		self::PAYWALL_MODE_NONE,
		self::PAYWALL_MODE_CATEGORY,
		self::PAYWALL_MODE_ALL_POSTS,
	);
	public const DEFAULT_PAYWALL_MODE   = self::PAYWALL_MODE_NONE;

	public const AUDIENCE_EVERYONE = 'everyone';
	public const AUDIENCE_BOTS     = 'bots';
	public const VALID_AUDIENCES   = array(
		self::AUDIENCE_EVERYONE,
		self::AUDIENCE_BOTS,
	);
	public const DEFAULT_AUDIENCE  = self::AUDIENCE_BOTS;

	public function __construct() {}

	/**
	 * Configured receiving wallet, or '' if not set.
	 */
	public function wallet_address(): string {
		$stored = get_option( self::OPTION_NAME, array() );
		return isset( $stored['wallet_address'] ) ? (string) $stored['wallet_address'] : '';
	}

	/**
	 * Configured default price, falling back to DEFAULT_PRICE.
	 */
	public function default_price(): string {
		$stored = get_option( self::OPTION_NAME, array() );
		$stored_price = isset( $stored['default_price'] ) ? (string) $stored['default_price'] : '';
		return '' === $stored_price ? self::DEFAULT_PRICE : $stored_price;
	}

	/**
	 * ID of the x402 facilitator connector to dispatch verify/settle through.
	 * Empty string (default) means no facilitator is selected; the paywall
	 * sits inert until the site owner picks one.
	 */
	public function selected_facilitator_id(): string {
		$stored = get_option( self::OPTION_NAME, array() );
		return (string) ( $stored['selected_facilitator_id'] ?? '' );
	}

	public function paywall_mode(): string {
		$stored = get_option( self::OPTION_NAME, array() );
		return $stored['paywall_mode'] ?? self::DEFAULT_PAYWALL_MODE;
	}

	public function paywall_audience(): string {
		$stored = get_option( self::OPTION_NAME, array() );
		return $stored['paywall_audience'] ?? self::DEFAULT_AUDIENCE;
	}

	public function paywall_category_term_id(): int {
		$stored = get_option( self::OPTION_NAME, array() );
		return $stored['paywall_category_term_id'] ?? 0;
	}

	/**
	 * Sanitise raw input into the canonical storage shape. Safe to call from a
	 * `register_setting` sanitize_callback: it reads stored state but must not
	 * write (calling `update_option` here recurses).
	 *
	 * @param array $input Raw input.
	 */
	public function sanitize( array $input ): array {
		$paywall_mode = isset( $input['paywall_mode'] ) ? (string) $input['paywall_mode'] : '';
		if ( ! in_array( $paywall_mode, self::VALID_PAYWALL_MODES, true ) ) {
			$paywall_mode = self::DEFAULT_PAYWALL_MODE;
		}

		$audience = isset( $input['paywall_audience'] ) ? (string) $input['paywall_audience'] : '';
		if ( ! in_array( $audience, self::VALID_AUDIENCES, true ) ) {
			$audience = self::DEFAULT_AUDIENCE;
		}

		$term_id = (int) ( $input['paywall_category_term_id'] ?? 0 );
		if ( $term_id <= 0 || ! term_exists( $term_id, 'category' ) ) {
			$term_id = $this->paywall_category_term_id();
		}

		// Connector IDs are constrained by the Connectors API to a-z0-9_-.
		$selected_facilitator_id = isset( $input['selected_facilitator_id'] )
			? (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $input['selected_facilitator_id'] ) )
			: '';

		$wallet = isset( $input['wallet_address'] ) ? trim( (string) $input['wallet_address'] ) : '';
		$price  = $this->sanitize_price( $input['default_price'] ?? '' );

		return array(
			'wallet_address'           => $wallet,
			'default_price'            => $price,
			'selected_facilitator_id'  => $selected_facilitator_id,
			'paywall_mode'             => $paywall_mode,
			'paywall_audience'         => $audience,
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
		$stored['paywall_category_term_id'] = $term_id;
		update_option( self::OPTION_NAME, $stored );
	}

	private function sanitize_price( mixed $raw ): string {
		$price = trim( (string) $raw );
		if ( ! is_numeric( $price ) || (float) $price <= 0 ) {
			return self::DEFAULT_PRICE;
		}
		return $price;
	}
}
