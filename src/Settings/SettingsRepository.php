<?php
/**
 * Plugin-wide settings accessor backed by the WordPress options API.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Settings;

use SimpleX402\Services\FacilitatorProfile;

/**
 * Thin wrapper around a single wp_options row.
 *
 * Schema:
 *   - mode:                     'test' | 'live'. Selects which nested block is
 *                               active and which FacilitatorProfile to use.
 *                               Overridable at runtime via `simple_x402_mode`.
 *   - test / live:              Per-mode blocks with `wallet_address` and
 *                               `default_price`. The `live` block additionally
 *                               carries `facilitator_url` (optional override of
 *                               the default CDP endpoint) and `facilitator_api_key`.
 *   - paywall_mode:             'category' | 'all-posts'. Shared across modes.
 *   - paywall_audience:         'everyone' | 'bots' | 'none'. Shared.
 *   - paywall_category_term_id: term_id of the category used in `category` mode.
 *                               Stable identity — survives renames in Settings →
 *                               Categories without any action from this plugin.
 */
final class SettingsRepository {

	public const OPTION_NAME      = 'simple_x402_settings';
	public const DEFAULT_PRICE    = '0.01';
	public const DEFAULT_CATEGORY = 'x402paywall';

	public const PAYWALL_MODE_CATEGORY  = 'category';
	public const PAYWALL_MODE_ALL_POSTS = 'all-posts';
	public const VALID_PAYWALL_MODES    = array(
		self::PAYWALL_MODE_CATEGORY,
		self::PAYWALL_MODE_ALL_POSTS,
	);
	public const DEFAULT_PAYWALL_MODE   = self::PAYWALL_MODE_CATEGORY;

	public const AUDIENCE_EVERYONE = 'everyone';
	public const AUDIENCE_BOTS     = 'bots';
	public const AUDIENCE_NONE     = 'none';
	public const VALID_AUDIENCES   = array(
		self::AUDIENCE_EVERYONE,
		self::AUDIENCE_BOTS,
		self::AUDIENCE_NONE,
	);
	public const DEFAULT_AUDIENCE  = self::AUDIENCE_NONE;

	public const VALID_X402_MODES  = array( FacilitatorProfile::MODE_TEST, FacilitatorProfile::MODE_LIVE );
	public const DEFAULT_X402_MODE = FacilitatorProfile::MODE_TEST;

	public const MODE_FILTER = 'simple_x402_mode';

	/**
	 * Memoized copy of the stored option blob. null = not yet loaded.
	 * Invalidated by `invalidate_cache()` after any write through this class.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $cached_stored = null;

	/**
	 * Memoized resolved mode (filter-applied). null = not yet computed.
	 */
	private ?string $cached_mode = null;

	/**
	 * Active x402 mode: 'test' or 'live'.
	 *
	 * Filterable via `simple_x402_mode` so a staging wp-config.php can force
	 * test mode without touching stored settings. The filter fires at most
	 * once per instance — subsequent reads hit the memoized result.
	 */
	public function mode(): string {
		if ( null !== $this->cached_mode ) {
			return $this->cached_mode;
		}
		$mode = (string) ( $this->stored()['mode'] ?? '' );
		if ( ! in_array( $mode, self::VALID_X402_MODES, true ) ) {
			$mode = self::DEFAULT_X402_MODE;
		}
		$filtered          = (string) apply_filters( self::MODE_FILTER, $mode );
		$this->cached_mode = in_array( $filtered, self::VALID_X402_MODES, true ) ? $filtered : $mode;
		return $this->cached_mode;
	}

	/**
	 * Configured receiving wallet for the active mode, or '' if not set.
	 */
	public function wallet_address(): string {
		return $this->mode_string( 'wallet_address', '' );
	}

	/**
	 * Configured default price for the active mode, falling back to DEFAULT_PRICE.
	 */
	public function default_price(): string {
		$price = $this->mode_string( 'default_price', '' );
		if ( ! is_numeric( $price ) || (float) $price <= 0 ) {
			return self::DEFAULT_PRICE;
		}
		return $price;
	}

	/**
	 * Live-mode facilitator URL override (blank = use the profile default).
	 */
	public function live_facilitator_url(): string {
		return (string) ( $this->mode_block( FacilitatorProfile::MODE_LIVE )['facilitator_url'] ?? '' );
	}

	/**
	 * Live-mode facilitator API key (blank if not configured).
	 */
	public function live_facilitator_api_key(): string {
		return (string) ( $this->mode_block( FacilitatorProfile::MODE_LIVE )['facilitator_api_key'] ?? '' );
	}

	/**
	 * Build the FacilitatorProfile for the active mode, overlaying stored
	 * live-mode overrides (facilitator URL, API key) onto the canonical defaults.
	 */
	public function facilitator_profile(): FacilitatorProfile {
		return FacilitatorProfile::MODE_LIVE === $this->mode()
			? FacilitatorProfile::for_live( $this->live_facilitator_url(), $this->live_facilitator_api_key() )
			: FacilitatorProfile::for_test();
	}

	public function paywall_mode(): string {
		$mode = (string) ( $this->stored()['paywall_mode'] ?? '' );
		return in_array( $mode, self::VALID_PAYWALL_MODES, true ) ? $mode : self::DEFAULT_PAYWALL_MODE;
	}

	public function paywall_audience(): string {
		$audience = (string) ( $this->stored()['paywall_audience'] ?? '' );
		return in_array( $audience, self::VALID_AUDIENCES, true ) ? $audience : self::DEFAULT_AUDIENCE;
	}

	public function paywall_category_term_id(): int {
		return (int) ( $this->stored()['paywall_category_term_id'] ?? 0 );
	}

	/**
	 * Sanitise raw input into the canonical storage shape. Safe to call from a
	 * `register_setting` sanitize_callback: it reads stored state but must not
	 * write (calling `update_option` here recurses).
	 *
	 * Per-mode blocks are sanitized independently so an admin can keep live
	 * settings filled while editing test, and vice versa.
	 *
	 * @param array $input Raw input.
	 */
	public function sanitize( array $input ): array {
		$mode = isset( $input['mode'] ) ? (string) $input['mode'] : '';
		if ( ! in_array( $mode, self::VALID_X402_MODES, true ) ) {
			$mode = self::DEFAULT_X402_MODE;
		}

		$test_raw = isset( $input[ FacilitatorProfile::MODE_TEST ] ) && is_array( $input[ FacilitatorProfile::MODE_TEST ] )
			? $input[ FacilitatorProfile::MODE_TEST ]
			: array();
		$live_raw = isset( $input[ FacilitatorProfile::MODE_LIVE ] ) && is_array( $input[ FacilitatorProfile::MODE_LIVE ] )
			? $input[ FacilitatorProfile::MODE_LIVE ]
			: array();

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

		return array(
			'mode'                        => $mode,
			FacilitatorProfile::MODE_TEST => $this->sanitize_test_block( $test_raw ),
			FacilitatorProfile::MODE_LIVE => $this->sanitize_live_block( $live_raw ),
			'paywall_mode'                => $paywall_mode,
			'paywall_audience'            => $audience,
			'paywall_category_term_id'    => $term_id,
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
		$this->invalidate_cache();
	}

	/**
	 * Replace just the paywall_category_term_id, preserving every other field.
	 *
	 * Deliberately bypasses sanitize(): callers reacting to an external taxonomy
	 * event (e.g. the delete-term guard) must not wipe unknown fields.
	 */
	public function set_paywall_category_term_id( int $term_id ): void {
		$stored                             = $this->stored();
		$stored['paywall_category_term_id'] = $term_id;
		update_option( self::OPTION_NAME, $stored );
		$this->invalidate_cache();
	}

	/**
	 * Clear memoized state. Call after any write so subsequent reads see the
	 * fresh option. Public so external code that writes the option directly
	 * (bypassing `save()` / `set_paywall_category_term_id()`) can still
	 * resync a long-lived instance.
	 */
	public function invalidate_cache(): void {
		$this->cached_stored = null;
		$this->cached_mode   = null;
	}

	/**
	 * Fetch the full stored option, loading once per instance.
	 *
	 * @return array<string,mixed>
	 */
	private function stored(): array {
		if ( null === $this->cached_stored ) {
			$raw                 = get_option( self::OPTION_NAME, array() );
			$this->cached_stored = is_array( $raw ) ? $raw : array();
		}
		return $this->cached_stored;
	}

	/**
	 * Read a string field from the active mode's block.
	 */
	private function mode_string( string $key, string $fallback ): string {
		$block = $this->mode_block( $this->mode() );
		return (string) ( $block[ $key ] ?? $fallback );
	}

	/**
	 * Fetch the nested block for a given mode, or an empty array if unset.
	 *
	 * @return array<string,mixed>
	 */
	private function mode_block( string $mode ): array {
		$block = $this->stored()[ $mode ] ?? array();
		return is_array( $block ) ? $block : array();
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,string>
	 */
	private function sanitize_test_block( array $raw ): array {
		return array(
			'wallet_address' => isset( $raw['wallet_address'] ) ? trim( (string) $raw['wallet_address'] ) : '',
			'default_price'  => $this->sanitize_price( $raw['default_price'] ?? '' ),
		);
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,string>
	 */
	private function sanitize_live_block( array $raw ): array {
		$url = isset( $raw['facilitator_url'] ) ? trim( (string) $raw['facilitator_url'] ) : '';
		if ( '' !== $url && ! preg_match( '#^https?://#i', $url ) ) {
			$url = '';
		}
		return array(
			'wallet_address'      => isset( $raw['wallet_address'] ) ? trim( (string) $raw['wallet_address'] ) : '',
			'default_price'       => $this->sanitize_price( $raw['default_price'] ?? '' ),
			'facilitator_url'     => $url,
			'facilitator_api_key' => isset( $raw['facilitator_api_key'] ) ? trim( (string) $raw['facilitator_api_key'] ) : '',
		);
	}

	private function sanitize_price( mixed $raw ): string {
		$price = trim( (string) $raw );
		if ( ! is_numeric( $price ) || (float) $price <= 0 ) {
			return self::DEFAULT_PRICE;
		}
		return $price;
	}
}
