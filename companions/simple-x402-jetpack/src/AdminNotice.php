<?php
/**
 * wp-admin notice when wpcom_x402 is picked but Jetpack isn't usable.
 *
 * @package SimpleX402\Jetpack
 */

declare(strict_types=1);

namespace SimpleX402\Jetpack;

use SimpleX402\Settings\SettingsRepository;

/**
 * Surfaces a yellow admin notice in the WP-admin header when the user has
 * selected the WordPress.com facilitator but Jetpack Connection isn't
 * available (package not installed, or installed but the site isn't
 * connected). Silently no-op when the selection is a different facilitator
 * — other connectors get their own notices from their own code.
 */
final class AdminNotice {

	private const ISSUE_MISSING       = 'jetpack_missing';
	private const ISSUE_NOT_CONNECTED = 'jetpack_not_connected';

	private const JETPACK_CLIENT  = '\\Automattic\\Jetpack\\Connection\\Client';
	private const JETPACK_MANAGER = '\\Automattic\\Jetpack\\Connection\\Manager';

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'maybe_render' ) );
	}

	public function maybe_render(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! class_exists( SettingsRepository::class ) ) {
			// Main plugin isn't loaded; nothing to notice about.
			return;
		}
		$settings = new SettingsRepository();
		if ( ConnectorRegistrar::ID !== $settings->selected_facilitator_id() ) {
			return;
		}
		$issue = self::detect_issue();
		if ( null === $issue ) {
			return;
		}
		self::render_notice( $issue );
	}

	/**
	 * @return self::ISSUE_*|null
	 */
	public static function detect_issue(): ?string {
		if ( ! class_exists( self::JETPACK_CLIENT ) ) {
			return self::ISSUE_MISSING;
		}
		if ( class_exists( self::JETPACK_MANAGER ) ) {
			$manager = new \Automattic\Jetpack\Connection\Manager( 'simple-x402-jetpack' );
			if ( method_exists( $manager, 'is_connected' ) && ! $manager->is_connected() ) {
				return self::ISSUE_NOT_CONNECTED;
			}
		}
		return null;
	}

	private static function render_notice( string $issue ): void {
		$message = self::ISSUE_MISSING === $issue
			? __(
				'Simple x402 is set to use the WordPress.com facilitator, but Jetpack Connection isn\'t available on this site. Install and activate Jetpack to finish setup.',
				'simple-x402-jetpack'
			)
			: __(
				'Simple x402 is set to use the WordPress.com facilitator, but this site isn\'t connected to WordPress.com via Jetpack. Connect Jetpack to finish setup.',
				'simple-x402-jetpack'
			);
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html( $message )
		);
	}
}
