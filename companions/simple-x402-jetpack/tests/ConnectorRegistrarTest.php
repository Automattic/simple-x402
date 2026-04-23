<?php
declare(strict_types=1);

namespace SimpleX402\Jetpack\Tests;

use PHPUnit\Framework\TestCase;
use SimpleX402\Connectors\ConnectorRegistry;
use SimpleX402\Facilitator\Facilitator;
use SimpleX402\Facilitator\TestResult;
use SimpleX402\Jetpack\ConnectorRegistrar;
use SimpleX402\Jetpack\JetpackFacilitator;
use SimpleX402\Services\FacilitatorProfile;

final class ConnectorRegistrarTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_connectors'] = array();
	}

	public function test_payload_uses_core_preserved_fields_with_none_auth(): void {
		$payload = ConnectorRegistrar::payload();

		$this->assertSame(
			array( 'name', 'description', 'type', 'authentication', 'plugin' ),
			array_keys( $payload )
		);
		$this->assertSame( ConnectorRegistry::FACILITATOR_TYPE, $payload['type'] );
		// Jetpack handles auth out-of-band, so the connector declares 'none'.
		$this->assertSame( 'none', $payload['authentication']['method'] );
	}

	public function test_invoke_registers_the_connector(): void {
		$registry = new \WP_Connector_Registry();
		( new ConnectorRegistrar() )( $registry );

		$this->assertTrue( $registry->is_registered( ConnectorRegistrar::ID ) );
		$this->assertSame(
			ConnectorRegistry::FACILITATOR_TYPE,
			$registry->get_registered( ConnectorRegistrar::ID )['type']
		);
	}

	public function test_provide_facilitator_ignores_other_connector_ids(): void {
		$this->assertNull(
			( new ConnectorRegistrar() )->provide_facilitator( null, 'simple_x402_test' )
		);
	}

	public function test_provide_facilitator_does_not_override_an_existing_client(): void {
		$existing = new class() implements Facilitator {
			public function verify( array $r, array $p ): array {
				return array( 'isValid' => false, 'error' => null, 'raw' => array() );
			}
			public function settle( array $r, array $p ): array {
				return array( 'success' => false, 'transaction' => null, 'network' => null, 'error' => null, 'raw' => array() );
			}
			public function test_connection(): TestResult {
				return new TestResult( ok: true );
			}
			public function describe(): FacilitatorProfile {
				return FacilitatorProfile::for_test();
			}
		};
		$this->assertSame(
			$existing,
			( new ConnectorRegistrar() )->provide_facilitator( $existing, ConnectorRegistrar::ID )
		);
	}

	public function test_provide_facilitator_returns_jetpack_facilitator_when_jetpack_is_available(): void {
		// The bootstrap class_aliases a stub to Automattic\\Jetpack\\Connection\\Client,
		// so class_exists() in the registrar resolves to true during tests.
		$client = ( new ConnectorRegistrar() )->provide_facilitator( null, ConnectorRegistrar::ID );
		$this->assertInstanceOf( JetpackFacilitator::class, $client );
	}
}
