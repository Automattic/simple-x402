<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Services\X402FacilitatorClient;

final class X402FacilitatorClientTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_http']       = null;
		$GLOBALS['__sx402_http_next']  = null;
		$GLOBALS['__sx402_http_queue'] = array();
	}

	public function test_verify_posts_to_x402_org_verify(): void {
		$GLOBALS['__sx402_http_next'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"isValid":true}',
		);
		$client = new X402FacilitatorClient();
		$result = $client->verify( array( 'scheme' => 'exact' ), array( 'signature' => 'x' ) );

		$this->assertSame( 'https://x402.org/facilitator/verify', $GLOBALS['__sx402_http']['url'] );
		$this->assertTrue( $result['isValid'] );
		$this->assertSame(
			wp_json_encode(
				array(
					'paymentRequirements' => array( 'scheme' => 'exact' ),
					'paymentPayload'      => array( 'signature' => 'x' ),
				)
			),
			$GLOBALS['__sx402_http']['args']['body']
		);
	}

	public function test_settle_posts_to_x402_org_settle(): void {
		$GLOBALS['__sx402_http_next'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"success":true,"transaction":"0xabc"}',
		);
		$client = new X402FacilitatorClient();
		$result = $client->settle( array( 'scheme' => 'exact' ), array( 'signature' => 'x' ) );

		$this->assertSame( 'https://x402.org/facilitator/settle', $GLOBALS['__sx402_http']['url'] );
		$this->assertTrue( $result['success'] );
		$this->assertSame( '0xabc', $result['transaction'] );
	}

	public function test_wp_error_becomes_failure(): void {
		$GLOBALS['__sx402_http_next'] = new \WP_Error( 'http_fail', 'boom' );
		$client = new X402FacilitatorClient();
		$result = $client->verify( array(), array() );
		$this->assertFalse( $result['isValid'] );
		$this->assertSame( 'boom', $result['error'] );
	}

	public function test_non_2xx_becomes_failure(): void {
		$GLOBALS['__sx402_http_next'] = array(
			'response' => array( 'code' => 500 ),
			'body'     => '{"error":"bad"}',
		);
		$client = new X402FacilitatorClient();
		$result = $client->settle( array(), array() );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'bad', $result['error'] );
	}
}
