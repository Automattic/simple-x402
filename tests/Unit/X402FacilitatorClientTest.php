<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Services\FacilitatorProfile;
use SimpleX402\Services\X402FacilitatorClient;

final class X402FacilitatorClientTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_http']       = null;
		$GLOBALS['__sx402_http_next']  = null;
		$GLOBALS['__sx402_http_queue'] = array();
	}

	private function test_client(): X402FacilitatorClient {
		return new X402FacilitatorClient( FacilitatorProfile::for_test() );
	}

	public function test_verify_posts_to_profile_facilitator_verify(): void {
		$GLOBALS['__sx402_http_next'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"isValid":true}',
		);
		$result = $this->test_client()->verify( array( 'scheme' => 'exact' ), array( 'signature' => 'x' ) );

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

	public function test_settle_posts_to_profile_facilitator_settle(): void {
		$GLOBALS['__sx402_http_next'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"success":true,"transaction":"0xabc"}',
		);
		$result = $this->test_client()->settle( array( 'scheme' => 'exact' ), array( 'signature' => 'x' ) );

		$this->assertSame( 'https://x402.org/facilitator/settle', $GLOBALS['__sx402_http']['url'] );
		$this->assertTrue( $result['success'] );
		$this->assertSame( '0xabc', $result['transaction'] );
	}

	public function test_wp_error_becomes_failure(): void {
		$GLOBALS['__sx402_http_next'] = new \WP_Error( 'http_fail', 'boom' );
		$result = $this->test_client()->verify( array(), array() );
		$this->assertFalse( $result['isValid'] );
		$this->assertSame( 'boom', $result['error'] );
	}

	public function test_non_2xx_becomes_failure(): void {
		$GLOBALS['__sx402_http_next'] = array(
			'response' => array( 'code' => 500 ),
			'body'     => '{"error":"bad"}',
		);
		$result = $this->test_client()->settle( array(), array() );
		$this->assertFalse( $result['success'] );
		$this->assertSame( 'bad', $result['error'] );
	}

	public function test_profile_with_api_key_sends_bearer_authorization(): void {
		$GLOBALS['__sx402_http_next'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"isValid":true}',
		);
		$profile = new FacilitatorProfile(
			network: 'base',
			asset: '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913',
			asset_decimals: 6,
			facilitator_url: 'https://facil.example/',
			eip712_name: 'USD Coin',
			eip712_version: '2',
			environment_label: 'Live',
			api_key: 'my-api-key',
		);
		$client  = new X402FacilitatorClient( $profile );
		$client->verify( array(), array() );

		$this->assertSame( 'https://facil.example/verify', $GLOBALS['__sx402_http']['url'] );
		$this->assertSame( 'Bearer my-api-key', $GLOBALS['__sx402_http']['args']['headers']['Authorization'] );
	}

	public function test_test_profile_omits_authorization_header(): void {
		$GLOBALS['__sx402_http_next'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"isValid":true}',
		);
		$this->test_client()->verify( array(), array() );
		$this->assertArrayNotHasKey( 'Authorization', $GLOBALS['__sx402_http']['args']['headers'] );
	}

	public function test_test_connection_hits_base_url_with_head(): void {
		$GLOBALS['__sx402_http_next'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '',
		);
		$result = $this->test_client()->test_connection();

		$this->assertSame( 'https://x402.org/facilitator/', $GLOBALS['__sx402_http']['url'] );
		$this->assertSame( 'HEAD', $GLOBALS['__sx402_http']['method'] );
		$this->assertTrue( $result->ok );
		$this->assertSame( 200, $result->http_code );
	}

	public function test_test_connection_reports_wp_error_as_unreachable(): void {
		$GLOBALS['__sx402_http_next'] = new \WP_Error( 'dns_fail', 'nope' );
		$result = $this->test_client()->test_connection();
		$this->assertFalse( $result->ok );
		$this->assertSame( 'nope', $result->error );
	}

	public function test_test_connection_counts_5xx_as_down(): void {
		$GLOBALS['__sx402_http_next'] = array(
			'response' => array( 'code' => 502 ),
			'body'     => '',
		);
		$result = $this->test_client()->test_connection();
		$this->assertFalse( $result->ok );
		$this->assertSame( 502, $result->http_code );
	}

	public function test_test_connection_treats_4xx_as_reachable(): void {
		$GLOBALS['__sx402_http_next'] = array(
			'response' => array( 'code' => 404 ),
			'body'     => '',
		);
		$result = $this->test_client()->test_connection();
		$this->assertTrue( $result->ok );
		$this->assertSame( 404, $result->http_code );
	}
}
