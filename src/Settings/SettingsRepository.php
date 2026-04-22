<?php
/**
 * Plugin-wide settings accessor backed by the WordPress options API.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Settings;

use SimpleX402\Services\FacilitatorProfile;
use SimpleX402\Services\SettingsChangeNotifier;

/**
 * Thin wrapper around a single wp_options row.
 *
 * Schema:
 *   - mode:                     'test' | 'live'. Selects which nested block is
 *                               active and which FacilitatorProfile to use.
 *   - test / live:              Per-mode blocks with `wallet_address` and
 *                               `default_price`. The `live` block additionally
 *                               carries `facilitator_url` (optional override of
 *                               the default CDP endpoint) and `facilitator_api_key`.
 *   - paywall_mode:             'category' | 'all-posts'. Shared across modes.
 *   - paywall_audience:         'everyone' | 'bots' | 'none'. Shared.
 *   - paywall_category_term_id: term_id of the category used in `category` mode.
 *                               Stable identity — survives renames in Settings →
 *                               Categories without any action from this plugin.
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

	private const WALLET_ADDRESS_PATTERN = '/^0x[0-9a-fA-F]{40}$/';

	/**
	 * @param SettingsChangeNotifier|null $notifier Receives notices when sanitize
	 * has to revert a requested live-mode switch. Optional so callers that only
	 * read the option (activation, plain getters) don't need the wiring.
	 */
	public function __construct( private readonly ?SettingsChangeNotifier $notifier = null ) {}

	/**
	 * Active x402 mode: 'test' or 'live'.
	 */
	public function mode(): string {
		$stored = get_option( self::OPTION_NAME, array() );
		return $stored['mode'] ?? self::DEFAULT_X402_MODE;
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
		return $this->mode_string( 'default_price', self::DEFAULT_PRICE );
	}

	/**
	 * Live-mode facilitator URL override (blank = use the profile default).
	 */
	public function live_facilitator_url(): string {
		return $this->mode_block( FacilitatorProfile::MODE_LIVE )['facilitator_url'] ?? '';
	}

	/**
	 * Live-mode facilitator API key (blank if not configured).
	 */
	public function live_facilitator_api_key(): string {
		return $this->mode_block( FacilitatorProfile::MODE_LIVE )['facilitator_api_key'] ?? '';
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

		$test_block = $this->sanitize_test_block( $test_raw );
		$live_block = $this->sanitize_live_block( $live_raw );

		// Live mode requires a routable destination wallet AND a facilitator
		// API key — without either, every settle request silently fails. Refuse
		// the switch (drop back to test) and surface a notice so the admin
		// understands why the radio appears to have ignored them.
		if ( FacilitatorProfile::MODE_LIVE === $mode ) {
			$missing = $this->live_mode_issues( $live_block );
			if ( array() !== $missing ) {
				$mode = FacilitatorProfile::MODE_TEST;
				$this->notifier?->notify_live_mode_incomplete( $missing );
			}
		}

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
			FacilitatorProfile::MODE_TEST => $test_block,
			FacilitatorProfile::MODE_LIVE => $live_block,
			'paywall_mode'                => $paywall_mode,
			'paywall_audience'            => $audience,
			'paywall_category_term_id'    => $term_id,
		);
	}

	/**
	 * @param array<string,string> $live_block Sanitized live block.
	 * @return string[] Human-readable phrases for each missing requirement.
	 */
	private function live_mode_issues( array $live_block ): array {
		$issues = array();
		$wallet = $live_block['wallet_address'] ?? '';
		if ( '' === $wallet ) {
			$issues[] = __( 'a receiving wallet address', 'simple-x402' );
		} elseif ( 1 !== preg_match( self::WALLET_ADDRESS_PATTERN, $wallet ) ) {
			$issues[] = __( 'a valid wallet address (0x followed by 40 hex characters)', 'simple-x402' );
		}
		if ( '' === ( $live_block['facilitator_api_key'] ?? '' ) ) {
			$issues[] = __( 'a facilitator API key', 'simple-x402' );
		}
		return $issues;
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

	/**
	 * Read a string field from the active mode's block.
	 */
	private function mode_string( string $key, string $fallback ): string {
		return $this->mode_block( $this->mode() )[ $key ] ?? $fallback;
	}

	/**
	 * Fetch the nested block for a given mode, or an empty array if unset.
	 *
	 * @return array<string,mixed>
	 */
	private function mode_block( string $mode ): array {
		$stored = get_option( self::OPTION_NAME, array() );
		return $stored[ $mode ] ?? array();
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
