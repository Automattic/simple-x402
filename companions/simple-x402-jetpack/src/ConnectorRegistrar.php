<?php
/**
 * Registers the `wpcom_x402` connector backed by Jetpack Connection.
 *
 * @package SimpleX402\Jetpack
 */

declare(strict_types=1);

namespace SimpleX402\Jetpack;

use SimpleX402\Connectors\ConnectorRegistry;
use SimpleX402\Facilitator\Facilitator;

/**
 * Registers the WordPress.com-backed facilitator connector.
 *
 * Core's Connectors API rejects non-`api_key`/`none` auth methods at
 * registration time (verified against WP 7.0 RC2). Jetpack handles its own
 * auth flow out-of-band, so we declare `method: 'none'` — the connector is
 * effectively a "pointer" and the Facilitator asks Jetpack Connection to
 * sign the outbound calls.
 */
final class ConnectorRegistrar {

	public const ID = 'wpcom_x402';

	/** The Jetpack Connection class we delegate HTTP signing to. */
	private const JETPACK_CLIENT = '\\Automattic\\Jetpack\\Connection\\Client';

	/**
	 * Hooked to `wp_connectors_init`.
	 */
	public function __invoke( \WP_Connector_Registry $registry ): void {
		$registry->register( self::ID, self::payload() );
	}

	/**
	 * `simple_x402_facilitator_for_connector` filter callback.
	 *
	 * Returns a JetpackFacilitator when asked about our connector ID AND the
	 * facilitator has a working transport — either Jetpack Connection is
	 * installed, or a dev override URL is set (see SIMPLE_X402_JETPACK_DEV_URL
	 * in JetpackFacilitator::call). Otherwise forwards the existing value so
	 * other plugins can take over.
	 */
	public function provide_facilitator( ?Facilitator $existing, string $id ): ?Facilitator {
		if ( self::ID !== $id || null !== $existing ) {
			return $existing;
		}
		$has_jetpack = class_exists( self::JETPACK_CLIENT );
		$has_dev_url = '' !== (string) getenv( 'SIMPLE_X402_JETPACK_DEV_URL' );
		if ( ! $has_jetpack && ! $has_dev_url ) {
			// No working transport — the connector stays in the picker but
			// service requests will fail until Jetpack lands on the site.
			return $existing;
		}
		return new JetpackFacilitator();
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function payload(): array {
		return array(
			'name'           => 'WordPress.com (via Jetpack)',
			'description'    => 'Settle x402 payments through WordPress.com. Requires a Jetpack connection.',
			'type'           => ConnectorRegistry::FACILITATOR_TYPE,
			'authentication' => array( 'method' => 'none' ),
			'plugin'         => array( 'file' => 'simple-x402-jetpack/simple-x402-jetpack.php' ),
		);
	}
}
