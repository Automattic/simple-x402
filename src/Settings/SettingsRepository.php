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
 *   - selected_facilitator_id:  Connector ID dispatching verify/settle. '' means
 *                               no facilitator selected (paywall inert).
 *   - facilitators:             Map of connector_id → { wallet_address, default_price }.
 *                               Each registered facilitator remembers its own
 *                               wallet + price so swapping the picker recalls
 *                               whichever values were last configured for that one.
 *   - paywall_mode:             'none' | 'category' | 'all-posts'.
 *   - paywall_audience:         'everyone' | 'bots'.
 *   - paywall_category_term_id: term_id used in `category` mode.
 *
 * Getters trust `sanitize()` as the only writer — they do not re-validate
 * stored values.
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
	 * Wallet address for the active facilitator, or '' if unset / no facilitator.
	 */
	public function wallet_address(): string {
		return $this->wallet_address_for( $this->selected_facilitator_id() );
	}

	/**
	 * Default price for the active facilitator, falling back to DEFAULT_PRICE.
	 */
	public function default_price(): string {
		return $this->default_price_for( $this->selected_facilitator_id() );
	}

	/**
	 * Wallet address stored for a specific connector ID.
	 */
	public function wallet_address_for( string $facilitator_id ): string {
		$slot = $this->slot_for( $facilitator_id );
		return (string) ( $slot['wallet_address'] ?? '' );
	}

	/**
	 * Price stored for a specific connector ID, or DEFAULT_PRICE if unset.
	 */
	public function default_price_for( string $facilitator_id ): string {
		$slot  = $this->slot_for( $facilitator_id );
		$price = (string) ( $slot['default_price'] ?? '' );
		return '' === $price ? self::DEFAULT_PRICE : $price;
	}

	/**
	 * Every stored facilitator slot, keyed by connector ID. Used by the
	 * SettingsPage bootstrap so the React picker can swap values locally
	 * without refetching.
	 *
	 * @return array<string,array{wallet_address:string,default_price:string}>
	 */
	public function facilitator_slots(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		$slots  = is_array( $stored['facilitators'] ?? null ) ? $stored['facilitators'] : array();
		$out    = array();
		foreach ( $slots as $id => $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}
			$out[ (string) $id ] = array(
				'wallet_address' => (string) ( $slot['wallet_address'] ?? '' ),
				'default_price'  => (string) ( $slot['default_price'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * ID of the x402 facilitator connector to dispatch through. Empty string
	 * (default) means no facilitator is selected.
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

		$selected_facilitator_id = $this->sanitize_connector_id( $input['selected_facilitator_id'] ?? '' );
		$facilitators            = $this->sanitize_facilitators( $input['facilitators'] ?? array() );

		return array(
			'selected_facilitator_id'  => $selected_facilitator_id,
			'facilitators'             => $facilitators,
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
	 */
	public function set_paywall_category_term_id( int $term_id ): void {
		$stored                             = get_option( self::OPTION_NAME, array() );
		$stored['paywall_category_term_id'] = $term_id;
		update_option( self::OPTION_NAME, $stored );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function slot_for( string $facilitator_id ): array {
		if ( '' === $facilitator_id ) {
			return array();
		}
		$slots = $this->facilitator_slots();
		return $slots[ $facilitator_id ] ?? array();
	}

	/**
	 * Validate a connector ID against the Connectors API's a-z0-9_- rule.
	 * Invalid characters are stripped; an all-invalid input becomes ''.
	 */
	private function sanitize_connector_id( mixed $raw ): string {
		return (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $raw ) );
	}

	/**
	 * Canonicalise the submitted facilitators map. Unknown keys are dropped;
	 * each slot is normalised to { wallet_address, default_price }. Invalid
	 * connector IDs are filtered out.
	 *
	 * @param mixed $raw Raw input (expected to be array<string,array>).
	 * @return array<string,array{wallet_address:string,default_price:string}>
	 */
	private function sanitize_facilitators( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $id => $slot ) {
			$clean_id = $this->sanitize_connector_id( (string) $id );
			if ( '' === $clean_id || ! is_array( $slot ) ) {
				continue;
			}
			$out[ $clean_id ] = array(
				'wallet_address' => isset( $slot['wallet_address'] ) ? trim( (string) $slot['wallet_address'] ) : '',
				'default_price'  => $this->sanitize_price( $slot['default_price'] ?? '' ),
			);
		}
		return $out;
	}

	private function sanitize_price( mixed $raw ): string {
		$price = trim( (string) $raw );
		if ( ! is_numeric( $price ) || (float) $price <= 0 ) {
			return self::DEFAULT_PRICE;
		}
		return $price;
	}
}
