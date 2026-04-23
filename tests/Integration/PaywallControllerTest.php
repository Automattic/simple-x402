<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SimpleX402\Connectors\ConnectorRegistry;
use SimpleX402\Facilitator\Facilitator;
use SimpleX402\Facilitator\FacilitatorResolver;
use SimpleX402\Facilitator\TestResult;
use SimpleX402\Http\PaywallController;
use SimpleX402\Services\GrantStore;
use SimpleX402\Services\RuleResolver;
use SimpleX402\Services\X402HeaderCodec;
use SimpleX402\Settings\SettingsRepository;

final class PaywallControllerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_filters']    = array();
		$GLOBALS['__sx402_transients'] = array();
		$GLOBALS['__sx402_options']    = array(
			'simple_x402_settings' => array(
				'mode' => 'test',
				'test' => array(
					'wallet_address' => '0xreceiver',
					'default_price'  => '0.01',
				),
			),
		);
		$GLOBALS['__sx402_response']   = array(
			'status'  => 200,
			'headers' => array(),
			'body'    => null,
			'exited'  => false,
		);
		$GLOBALS['__sx402_http']            = null;
		$GLOBALS['__sx402_http_next']       = null;
		$GLOBALS['__sx402_http_queue']      = array();
		$GLOBALS['__sx402_current_user_caps'] = array();
	}

	private function controller( ?SettingsRepository $settings = null ): PaywallController {
		return new PaywallController(
			new RuleResolver(),
			new GrantStore(),
			$settings ?? new SettingsRepository()
		);
	}

	/**
	 * Assert 402 JSON body includes requirements and the expected human-readable price (from the resolved rule).
	 */
	private function assert_402_body_has_price_and_requirements( string $expected_price ): void {
		$body = json_decode( (string) $GLOBALS['__sx402_response']['body'], true );
		$this->assertIsArray( $body );
		$this->assertSame( $expected_price, $body['price'] );
		$this->assertArrayHasKey( 'requirements', $body );
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
		$this->assert_402_body_has_price_and_requirements( '0.01' );
		$this->assertTrue( $GLOBALS['__sx402_response']['exited'] );
	}

	public function test_administrator_bypasses_paywall(): void {
		add_filter( 'simple_x402_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		$GLOBALS['__sx402_current_user_caps'] = array( 'manage_options' );

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

	public function test_bypass_filter_can_widen_to_non_admin(): void {
		add_filter( 'simple_x402_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		add_filter( 'simple_x402_bypass_paywall', static fn () => true, 10, 3 );

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

	public function test_bypass_filter_can_override_admin_default(): void {
		add_filter( 'simple_x402_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		$GLOBALS['__sx402_current_user_caps'] = array( 'manage_options' );
		add_filter( 'simple_x402_bypass_paywall', static fn () => false, 10, 3 );

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array(),
			)
		);

		$this->assertSame( 402, $GLOBALS['__sx402_response']['status'] );
		$this->assertTrue( $GLOBALS['__sx402_response']['exited'] );
	}

	public function test_bypass_filter_receives_request_and_rule(): void {
		add_filter( 'simple_x402_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		$seen = null;
		add_filter(
			'simple_x402_bypass_paywall',
			static function ( $bypass, $request, $rule ) use ( &$seen ) {
				$seen = array( 'request' => $request, 'rule' => $rule );
				return $bypass;
			},
			10,
			3
		);

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 42,
				'headers' => array(),
			)
		);

		$this->assertIsArray( $seen );
		$this->assertSame( '/foo', $seen['request']['path'] );
		$this->assertSame( 42, $seen['request']['post_id'] );
		$this->assertSame( '0.01', $seen['rule']['price'] );
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
		$this->assert_402_body_has_price_and_requirements( '0.01' );
		$body = json_decode( (string) $GLOBALS['__sx402_response']['body'], true );
		$this->assertIsArray( $body );
		$this->assertSame( 'verify_failed', $body['error'] );
		$this->assertTrue( $GLOBALS['__sx402_response']['exited'] );
	}

	public function test_invalid_signature_header_responds_402_with_price(): void {
		add_filter( 'simple_x402_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );

		$this->controller()->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array( 'PAYMENT-SIGNATURE' => 'not-valid-base64!!!' ),
			)
		);

		$this->assertSame( 402, $GLOBALS['__sx402_response']['status'] );
		$this->assert_402_body_has_price_and_requirements( '0.01' );
		$body = json_decode( (string) $GLOBALS['__sx402_response']['body'], true );
		$this->assertIsArray( $body );
		$this->assertSame( 'invalid_signature_header', $body['error'] );
		$this->assertTrue( $GLOBALS['__sx402_response']['exited'] );
	}

	public function test_settle_failure_responds_402_with_price(): void {
		add_filter( 'simple_x402_rule_for_request', static fn () => array( 'price' => '0.25' ), 10, 2 );

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
				'body'     => '{"success":false,"errorReason":"on_chain_revert"}',
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
		$this->assert_402_body_has_price_and_requirements( '0.25' );
		$body = json_decode( (string) $GLOBALS['__sx402_response']['body'], true );
		$this->assertIsArray( $body );
		$this->assertSame( 'settle_failed', $body['error'] );
		$this->assertTrue( $GLOBALS['__sx402_response']['exited'] );
	}

	/**
	 * Regression guard for the lazy-profile refactor: a request that matches
	 * no paywall rule must never touch the facilitator layer. Without this
	 * guard, every admin dashboard hit, AJAX poll, REST call, and cron tick
	 * would pay for profile resolution + service construction it never needs.
	 *
	 * We assert it via reflection on the private fields — they're null iff
	 * the lazy accessors were never invoked.
	 */
	public function test_no_rule_match_never_constructs_facilitator_services(): void {
		$controller = $this->controller();
		$controller->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array(),
			)
		);

		$this->assertNull( $this->private_field( $controller, 'profile' ) );
		$this->assertNull( $this->private_field( $controller, 'builder' ) );
		$this->assertNull( $this->private_field( $controller, 'facilitator_svc' ) );
	}

	/**
	 * The full verify + settle path must populate every lazy field: profile,
	 * builder, facilitator_svc. If any one stays null, the controller reached
	 * deeper code paths without going through the memoized accessors — a
	 * regression we want to catch.
	 */
	public function test_full_verify_and_settle_populates_all_lazy_fields(): void {
		$payload = X402HeaderCodec::encode(
			array( 'payload' => array( 'authorization' => array( 'from' => '0xwallet' ) ) )
		);
		add_filter(
			'simple_x402_rule_for_request',
			static fn() => array( 'price' => '0.01', 'ttl' => 60, 'description' => 'Test' )
		);
		$GLOBALS['__sx402_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"isValid":true}' ),
			array( 'response' => array( 'code' => 200 ), 'body' => '{"success":true,"transaction":"0xtx"}' ),
		);

		$controller = $this->controller();
		$controller->handle(
			array(
				'path'    => '/premium',
				'method'  => 'GET',
				'post_id' => 1,
				'headers' => array( 'PAYMENT-SIGNATURE' => $payload ),
			)
		);

		$this->assertNotNull( $this->private_field( $controller, 'profile' ) );
		$this->assertNotNull( $this->private_field( $controller, 'builder' ) );
		$this->assertNotNull( $this->private_field( $controller, 'facilitator_svc' ) );
	}

	private function private_field( object $obj, string $name ): mixed {
		$prop = new \ReflectionProperty( $obj::class, $name );
		$prop->setAccessible( true );
		return $prop->getValue( $obj );
	}

	public function test_uses_resolver_facilitator_when_selected_id_is_set(): void {
		// Point the setting at a registered connector and wire our recording mock
		// to the filter the resolver applies.
		$GLOBALS['__sx402_options']['simple_x402_settings']['selected_facilitator_id'] = 'simple_x402_test';
		$GLOBALS['__sx402_connectors']['simple_x402_test']                              = array(
			'type' => ConnectorRegistry::FACILITATOR_TYPE,
		);

		$calls = array();
		$mock  = new class($calls) implements Facilitator {
			/** @param array<int,string> $calls */
			public function __construct( private array &$calls ) {}
			public function verify( array $r, array $p ): array {
				$this->calls[] = 'verify';
				return array( 'isValid' => true, 'error' => null, 'raw' => array() );
			}
			public function settle( array $r, array $p ): array {
				$this->calls[] = 'settle';
				return array( 'success' => true, 'transaction' => '0xmock', 'network' => 'test', 'error' => null, 'raw' => array() );
			}
			public function test_connection(): TestResult {
				return new TestResult( ok: true );
			}
		};
		add_filter(
			FacilitatorResolver::FILTER,
			fn ( $existing, $id ) => 'simple_x402_test' === $id ? $mock : $existing,
			10,
			2
		);

		add_filter( 'simple_x402_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		$payload = X402HeaderCodec::encode(
			array(
				'scheme'  => 'exact',
				'payload' => array( 'authorization' => array( 'from' => '0xbuyer' ) ),
			)
		);

		$controller = new PaywallController(
			new RuleResolver(),
			new GrantStore(),
			new SettingsRepository(),
			new FacilitatorResolver( new ConnectorRegistry() ),
		);
		$controller->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array( 'PAYMENT-SIGNATURE' => $payload ),
			)
		);

		// Resolver-backed facilitator received both calls; no HTTP request was made.
		$this->assertSame( array( 'verify', 'settle' ), $calls );
		$this->assertNull( $GLOBALS['__sx402_http'] );
	}

	public function test_falls_back_to_legacy_client_when_resolver_returns_null(): void {
		// Setting points at an unregistered connector — resolver gives up, legacy
		// X402FacilitatorClient takes over and the normal HTTP flow happens.
		$GLOBALS['__sx402_options']['simple_x402_settings']['selected_facilitator_id'] = 'nonexistent';

		add_filter( 'simple_x402_rule_for_request', static fn () => array( 'price' => '0.01' ), 10, 2 );
		$payload = X402HeaderCodec::encode(
			array(
				'scheme'  => 'exact',
				'payload' => array( 'authorization' => array( 'from' => '0xbuyer' ) ),
			)
		);
		$GLOBALS['__sx402_http_queue'] = array(
			array( 'response' => array( 'code' => 200 ), 'body' => '{"isValid":true}' ),
			array( 'response' => array( 'code' => 200 ), 'body' => '{"success":true,"transaction":"0xlegacy"}' ),
		);

		$controller = new PaywallController(
			new RuleResolver(),
			new GrantStore(),
			new SettingsRepository(),
			new FacilitatorResolver( new ConnectorRegistry() ),
		);
		$controller->handle(
			array(
				'path'    => '/foo',
				'method'  => 'GET',
				'post_id' => 0,
				'headers' => array( 'PAYMENT-SIGNATURE' => $payload ),
			)
		);

		// Legacy HTTP client was used — this proves the fallback didn't short-circuit to 402.
		$this->assertNotNull( $GLOBALS['__sx402_http'] );
		$this->assertTrue( ( new GrantStore() )->has_grant( '0xbuyer', '/foo' ) );
	}
}
