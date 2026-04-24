<?php
/**
 * admin-ajax handler for per-card settings saves.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Admin;

use SimpleX402\Http\PaywallController;
use SimpleX402\Settings\SettingsRepository;

/**
 * Powers the React Settings → Simple x402 per-card "Save changes" buttons.
 *
 * Registered on `wp_ajax_simple_x402_save_settings`. Admin-only,
 * nonce-checked. Accepts a partial `fields` payload and forwards it to
 * SettingsRepository::update(), which merges into the stored option without
 * clobbering unrelated keys. Returns the merged row so the React state can
 * reset its per-card `saved` snapshot.
 */
final class SettingsAjax {

	public const ACTION = 'simple_x402_save_settings';
	public const NONCE  = 'simple_x402_save_settings_nonce';

	public function __construct( private readonly SettingsRepository $settings ) {}

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
			return;
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		$raw     = isset( $_POST['fields'] )
			? wp_unslash( (string) $_POST['fields'] )
			: '';
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			wp_send_json_error( array( 'error' => 'invalid_fields' ), 400 );
			return;
		}

		$merged = $this->settings->update( $decoded );

		$data          = array( 'values' => $merged );
		$scope_changed = array_key_exists( 'paywall_mode', $decoded )
			|| array_key_exists( 'paywall_category_term_id', $decoded );
		if ( $scope_changed ) {
			$mode = $merged['paywall_mode'] ?? SettingsRepository::DEFAULT_PAYWALL_MODE;
			if ( SettingsRepository::PAYWALL_MODE_NONE === $mode ) {
				$data['probe'] = null;
			} else {
				$url = $this->settings->sample_paywalled_post_permalink( $merged );
				if ( null !== $url && '' !== $url ) {
					$data['probe'] = array(
						'url'   => $url,
						'nonce' => wp_create_nonce( PaywallController::PROBE_NONCE_ACTION ),
					);
				} else {
					$data['probe'] = array( 'reason' => 'no_matching_post' );
				}
			}
		}

		wp_send_json_success( $data );
	}
}
