<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit\Facilitator;

use PHPUnit\Framework\TestCase;
use SimpleX402\Connectors\ConnectorRegistry;
use SimpleX402\Facilitator\Facilitator;
use SimpleX402\Facilitator\FacilitatorResolver;
use SimpleX402\Facilitator\TestResult;
use SimpleX402\Services\FacilitatorProfile;

final class FacilitatorResolverTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_connectors'] = array();
		$GLOBALS['__sx402_filters']    = array();
	}

	public function test_returns_null_when_connector_not_registered(): void {
		$resolver = new FacilitatorResolver( new ConnectorRegistry() );
		$this->assertNull( $resolver->resolve( 'missing' ) );
	}

	public function test_returns_null_when_no_filter_claims_the_connector(): void {
		$GLOBALS['__sx402_connectors']['orphan'] = array(
			'type' => ConnectorRegistry::FACILITATOR_TYPE,
		);

		$resolver = new FacilitatorResolver( new ConnectorRegistry() );
		$this->assertNull( $resolver->resolve( 'orphan' ) );
	}

	public function test_returns_facilitator_provided_by_filter(): void {
		$GLOBALS['__sx402_connectors']['claimed'] = array(
			'type' => ConnectorRegistry::FACILITATOR_TYPE,
		);
		$fake = new class() implements Facilitator {
			public function verify( array $r, array $p ): array {
				return array(
					'isValid' => true,
					'error'   => null,
					'raw'     => array(),
				);
			}
			public function settle( array $r, array $p ): array {
				return array(
					'success'     => true,
					'transaction' => null,
					'network'     => null,
					'error'       => null,
					'raw'         => array(),
				);
			}
			public function test_connection(): TestResult {
				return new TestResult( ok: true );
			}
			public function describe(): FacilitatorProfile {
				return FacilitatorProfile::for_test();
			}
		};
		add_filter(
			FacilitatorResolver::FILTER,
			fn ( $client, $id ) => 'claimed' === $id ? $fake : $client,
			10,
			2
		);

		$resolver = new FacilitatorResolver( new ConnectorRegistry() );
		$this->assertSame( $fake, $resolver->resolve( 'claimed' ) );
	}
}
