<?php
/**
 * Bootstrap for the Simple x402 Jetpack companion.
 *
 * @package SimpleX402\Jetpack
 */

declare(strict_types=1);

namespace SimpleX402\Jetpack;

use SimpleX402\Facilitator\Facilitator as FacilitatorInterface;

/**
 * Registers the WordPress.com facilitator connector and its Facilitator
 * implementation, but only when the host site is running Simple x402. If
 * Simple x402 isn't active, this plugin is a no-op — nothing to hook into.
 *
 * Jetpack Connection itself is detected lazily inside ConnectorRegistrar —
 * the plugin still registers the connector so it shows up in the picker,
 * then surfaces a "this needs Jetpack connected" state when a user selects it.
 */
final class Plugin {

	public static function boot(): void {
		if ( ! interface_exists( FacilitatorInterface::class ) ) {
			// Simple x402 isn't loaded; nothing to integrate with.
			return;
		}

		$registrar = new ConnectorRegistrar();
		add_action( 'wp_connectors_init', $registrar );
		add_filter(
			'simple_x402_facilitator_for_connector',
			array( $registrar, 'provide_facilitator' ),
			10,
			2
		);
	}
}
