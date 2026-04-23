<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit\Connectors;

use PHPUnit\Framework\TestCase;
use SimpleX402\Connectors\TestConnectorRegistrar;
use SimpleX402\Facilitator\Facilitator;
use SimpleX402\Facilitator\TestResult;
use SimpleX402\Services\X402FacilitatorClient;

final class TestConnectorProvideFacilitatorTest extends TestCase {

	public function test_provides_x402_client_for_its_own_id(): void {
		$client = ( new TestConnectorRegistrar() )->provide_facilitator( null, TestConnectorRegistrar::ID );

		$this->assertInstanceOf( X402FacilitatorClient::class, $client );
		$this->assertInstanceOf( Facilitator::class, $client );
	}

	public function test_does_not_override_a_client_already_provided(): void {
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
		};

		$result = ( new TestConnectorRegistrar() )->provide_facilitator( $existing, TestConnectorRegistrar::ID );
		$this->assertSame( $existing, $result );
	}

	public function test_ignores_other_connector_ids(): void {
		$result = ( new TestConnectorRegistrar() )->provide_facilitator( null, 'some_other_id' );
		$this->assertNull( $result );
	}
}
