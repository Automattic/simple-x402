<?php
declare(strict_types=1);

namespace SimpleX402\Jetpack\Tests;

use PHPUnit\Framework\TestCase;
use SimpleX402\Jetpack\JetpackFacilitator;

final class JetpackFacilitatorTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_jp']      = null;
		$GLOBALS['__sx402_jp_next'] = null;
	}

	public function test_verify_delegates_to_jetpack_client(): void {
		$GLOBALS['__sx402_jp_next'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"isValid":true}',
		);

		$result = ( new JetpackFacilitator() )->verify(
			array( 'scheme' => 'exact' ),
			array( 'signature' => 'x' )
		);

		$this->assertTrue( $result['isValid'] );
		$this->assertSame( '/x402/verify', $GLOBALS['__sx402_jp']['path'] );
		$this->assertSame( '2', $GLOBALS['__sx402_jp']['version'] );
		$this->assertSame( 'wpcom', $GLOBALS['__sx402_jp']['base_api_path'] );
		$this->assertSame( 'POST', $GLOBALS['__sx402_jp']['args']['method'] );

		$body = json_decode( (string) $GLOBALS['__sx402_jp']['body'], true );
		$this->assertSame( array( 'scheme' => 'exact' ), $body['paymentRequirements'] );
		$this->assertSame( array( 'signature' => 'x' ), $body['paymentPayload'] );
	}

	public function test_settle_returns_transaction_on_success(): void {
		$GLOBALS['__sx402_jp_next'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"success":true,"transaction":"0xdead"}',
		);

		$result = ( new JetpackFacilitator() )->settle( array(), array() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( '0xdead', $result['transaction'] );
		$this->assertSame( '/x402/settle', $GLOBALS['__sx402_jp']['path'] );
	}

	public function test_wp_error_from_jetpack_client_becomes_failure(): void {
		$GLOBALS['__sx402_jp_next'] = new \WP_Error( 'jp_down', 'not connected' );

		$result = ( new JetpackFacilitator() )->verify( array(), array() );

		$this->assertFalse( $result['isValid'] );
		$this->assertSame( 'not connected', $result['error'] );
	}

	public function test_non_2xx_becomes_failure_with_error_body(): void {
		$GLOBALS['__sx402_jp_next'] = array(
			'response' => array( 'code' => 500 ),
			'body'     => '{"error":"server_exploded"}',
		);

		$result = ( new JetpackFacilitator() )->settle( array(), array() );

		$this->assertFalse( $result['success'] );
		$this->assertSame( 'server_exploded', $result['error'] );
	}

	public function test_test_connection_probes_health_endpoint(): void {
		$GLOBALS['__sx402_jp_next'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"ok":true}',
		);

		$probe = ( new JetpackFacilitator() )->test_connection();

		$this->assertTrue( $probe->ok );
		$this->assertSame( '/x402/health', $GLOBALS['__sx402_jp']['path'] );
		$this->assertSame( 'GET', $GLOBALS['__sx402_jp']['args']['method'] );
	}

	public function test_describe_returns_testnet_profile_for_now(): void {
		$profile = ( new JetpackFacilitator() )->describe();
		$this->assertSame( 'base-sepolia', $profile->network );
	}
}
