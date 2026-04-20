<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SimpleX402\Http\PaywallController;
use SimpleX402\Services\GrantStore;
use SimpleX402\Services\PaymentRequirementsBuilder;
use SimpleX402\Services\RuleResolver;
use SimpleX402\Services\X402FacilitatorClient;
use SimpleX402\Services\X402HeaderCodec;
use SimpleX402\Settings\SettingsRepository;

final class PaywallControllerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_filters']    = array();
		$GLOBALS['__sx402_transients'] = array();
		$GLOBALS['__sx402_options']    = array(
			'simple_x402_settings' => array(
				'wallet_address' => '0xreceiver',
				'default_price'  => '0.01',
			),
		);
		$GLOBALS['__sx402_response']   = array(
			'status'  => 200,
			'headers' => array(),
			'body'    => null,
			'exited'  => false,
		);
		$GLOBALS['__sx402_http']       = null;
		$GLOBALS['__sx402_http_next']  = null;
		$GLOBALS['__sx402_http_queue'] = array();
	}

	private function controller(): PaywallController {
		return new PaywallController(
			new RuleResolver(),
			new PaymentRequirementsBuilder(),
			new X402FacilitatorClient(),
			new GrantStore(),
			new SettingsRepository()
		);
	}

	public function test_passes_through_when_no_rule_matches(): void {
		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array(),
			)
		);
		$this->assertSame( 200, $GLOBALS['__sx402_response']['status'] );
		$this->assertFalse( $GLOBALS['__sx402_response']['exited'] );
	}

	public function test_passes_singular_flag_to_rule_filter(): void {
		$seen = null;
		add_filter(
			'simple_x402_rule_for_request',
			static function ( $rule, $ctx ) use ( &$seen ) {
				$seen = $ctx;
				return null;
			},
			10,
			2
		);
		$this->controller()->handle(
			array(
				'path'     => '/p',
				'method'   => 'GET',
				'post_id'  => 1,
				'singular' => true,
				'headers'  => array(),
			)
		);
		$this->assertIsArray( $seen );
		$this->assertTrue( $seen['singular'] );
		$this->assertSame( 1, $seen['post_id'] );
	}

	public function test_responds_402_when_rule_matches_and_no_signature(): void {
		add_filter( 'simple_x402_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array(),
			)
		);

		$this->assertSame( 402, $GLOBALS['__sx402_response']['status'] );
		$this->assertArrayHasKey( 'PAYMENT-REQUIRED', $GLOBALS['__sx402_response']['headers'] );
		$decoded = X402HeaderCodec::decode( $GLOBALS['__sx402_response']['headers']['PAYMENT-REQUIRED'] );
		$this->assertSame( '0xreceiver', $decoded['payTo'] );
		$this->assertSame( '10000', $decoded['maxAmountRequired'] );
		$body = json_decode( (string) $GLOBALS['__sx402_response']['body'], true );
		$this->assertIsArray( $body );
		$this->assertSame( '0.01', $body['price'] );
		$this->assertArrayHasKey( 'requirements', $body );
		$this->assertTrue( $GLOBALS['__sx402_response']['exited'] );
	}

	public function test_allows_request_with_live_grant(): void {
		add_filter( 'simple_x402_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		( new GrantStore() )->issue( '0xbuyer', '/foo', 60, array() );

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array( 'X-Wallet-Address' => '0xbuyer' ),
			)
		);

		$this->assertSame( 200, $GLOBALS['__sx402_response']['status'] );
		$this->assertFalse( $GLOBALS['__sx402_response']['exited'] );
	}

	public function test_verifies_and_settles_then_issues_grant(): void {
		add_filter( 'simple_x402_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );

		$payload = X402HeaderCodec::encode(
			array(
				'scheme'  => 'exact',
				'payload' => array( 'authorization' => array( 'from' => '0xbuyer' ) ),
			)
		);

		$GLOBALS['__sx402_http_queue'] = array(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"isValid":true}',
			),
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"success":true,"transaction":"0xdead"}',
			),
		);

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array( 'PAYMENT-SIGNATURE' => $payload ),
			)
		);

		$this->assertSame( 200, $GLOBALS['__sx402_response']['status'] );
		$this->assertTrue( ( new GrantStore() )->has_grant( '0xbuyer', '/foo' ) );
	}

	public function test_verify_failure_responds_402(): void {
		add_filter( 'simple_x402_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );

		$payload = X402HeaderCodec::encode(
			array(
				'scheme'  => 'exact',
				'payload' => array(),
			)
		);

		$GLOBALS['__sx402_http_queue'] = array(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"isValid":false,"invalidReason":"bad_sig"}',
			),
		);

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array( 'PAYMENT-SIGNATURE' => $payload ),
			)
		);

		$this->assertSame( 402, $GLOBALS['__sx402_response']['status'] );
		$this->assertTrue( $GLOBALS['__sx402_response']['exited'] );
	}
}
