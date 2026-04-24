<?php
/**
 * admin-ajax handler for per-card settings saves.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Admin;

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

		$raw = isset( $_POST['fields'] )
			? wp_unslash( (string) $_POST['fields'] )
			: '';
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			wp_send_json_error( array( 'error' => 'invalid_fields' ), 400 );
			return;
		}

		$merged = $this->settings->update( $decoded );
		wp_send_json_success( array( 'values' => $merged ) );
	}
}
