<?php
/**
 * Registers the local "test" x402 facilitator connector.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Connectors;

use SimpleX402\Facilitator\Facilitator;
use SimpleX402\Services\FacilitatorProfile;
use SimpleX402\Services\X402FacilitatorClient;

/**
 * Registers a `simple_x402_test` connector that points at the public
 * x402.org facilitator on Base Sepolia, so the plugin can exercise its
 * connector-selection path without a real facilitator account.
 *
 * Also provides the `Facilitator` client for that connector ID via the
 * `simple_x402_facilitator_for_connector` filter — core strips unknown
 * fields from the registration payload, so the client mapping lives here
 * rather than in the connector metadata.
 *
 * Gated behind the `SIMPLE_X402_TEST_CONNECTOR` constant so production
 * sites never see it unless the operator explicitly opts in.
 */
final class TestConnectorRegistrar {

	public const ID = 'simple_x402_test';

	/**
	 * Hooked to `wp_connectors_init`. Skip if the test connector isn't
	 * explicitly enabled for this install.
	 */
	public function __invoke( \WP_Connector_Registry $registry ): void {
		if ( ! ( defined( 'SIMPLE_X402_TEST_CONNECTOR' ) && \SIMPLE_X402_TEST_CONNECTOR ) ) {
			return;
		}
		$registry->register( self::ID, self::payload() );
	}

	/**
	 * `simple_x402_facilitator_for_connector` filter callback. Returns an
	 * X402FacilitatorClient pointing at x402.org/base-sepolia when asked
	 * about our own connector ID; otherwise forwards the existing value so
	 * other plugins can take over for their IDs.
	 */
	public function provide_facilitator( ?Facilitator $existing, string $id ): ?Facilitator {
		if ( self::ID !== $id || null !== $existing ) {
			return $existing;
		}
		return new X402FacilitatorClient( FacilitatorProfile::for_test() );
	}

	/**
	 * Registration payload for the test connector.
	 *
	 * Core only preserves a fixed whitelist of fields (name, description,
	 * type, authentication, plugin). x402-specific capabilities like endpoint
	 * URL and supported networks are delivered separately through the
	 * `simple_x402_facilitator_for_connector` filter — confirmed against
	 * WordPress 7.0-RC2.
	 *
	 * @return array<string,mixed>
	 */
	public static function payload(): array {
		return array(
			'name'           => 'Simple x402 (test)',
			'description'    => 'Local test facilitator. Routes through x402.org on Base Sepolia — no real funds move.',
			'type'           => ConnectorRegistry::FACILITATOR_TYPE,
			'authentication' => array( 'method' => 'none' ),
			'plugin'         => array( 'file' => 'simple-x402/simple-x402.php' ),
		);
	}
}
