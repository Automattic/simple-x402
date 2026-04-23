<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit\Connectors;

use PHPUnit\Framework\TestCase;
use SimpleX402\Connectors\ConnectorRegistry;
use SimpleX402\Connectors\TestConnectorRegistrar;

final class TestConnectorRegistrarTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_connectors'] = array();
	}

	public function test_payload_uses_only_core_preserved_fields(): void {
		$payload = TestConnectorRegistrar::payload();

		// Core keeps name/description/type/authentication/plugin and drops the
		// rest; verified on WP 7.0-RC2. Fail loudly if we ever slip in extras.
		$this->assertSame(
			array( 'name', 'description', 'type', 'authentication', 'plugin' ),
			array_keys( $payload )
		);
		$this->assertSame( ConnectorRegistry::FACILITATOR_TYPE, $payload['type'] );
		$this->assertSame( 'none', $payload['authentication']['method'] );
	}

	public function test_invoke_registers_connector_when_constant_is_enabled(): void {
		if ( ! defined( 'SIMPLE_X402_TEST_CONNECTOR' ) ) {
			define( 'SIMPLE_X402_TEST_CONNECTOR', true );
		}
		// Skip if a previous test run defined it as false — not re-definable.
		if ( ! SIMPLE_X402_TEST_CONNECTOR ) {
			$this->markTestSkipped( 'SIMPLE_X402_TEST_CONNECTOR was pre-defined to false.' );
		}

		$registry   = new \WP_Connector_Registry();
		$registrar  = new TestConnectorRegistrar();
		$registrar( $registry );

		$this->assertTrue( $registry->is_registered( TestConnectorRegistrar::ID ) );
		$this->assertSame(
			ConnectorRegistry::FACILITATOR_TYPE,
			$registry->get_registered( TestConnectorRegistrar::ID )['type']
		);
	}
}
