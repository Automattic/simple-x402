<?php
declare(strict_types=1);

namespace SimpleX402\Jetpack\Tests;

use PHPUnit\Framework\TestCase;
use SimpleX402\Jetpack\ConnectorRegistrar;
use SimpleX402\Jetpack\JetpackSiteState;

final class JetpackSiteStateTest extends TestCase {

	protected function tearDown(): void {
		putenv( 'SIMPLE_X402_WPCOM_POOL_ADDRESS' );
		parent::tearDown();
	}

	public function test_managed_pool_filter_returns_env_address_for_wpcom_connector(): void {
		putenv( 'SIMPLE_X402_WPCOM_POOL_ADDRESS=0x2222222222222222222222222222222222222222' );
		$out = JetpackSiteState::filter_managed_pool_pay_to( '', ConnectorRegistrar::ID );
		$this->assertSame( '0x2222222222222222222222222222222222222222', $out );
	}

	public function test_managed_pool_filter_respects_non_empty_existing(): void {
		putenv( 'SIMPLE_X402_WPCOM_POOL_ADDRESS=0x2222222222222222222222222222222222222222' );
		$out = JetpackSiteState::filter_managed_pool_pay_to( '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', ConnectorRegistrar::ID );
		$this->assertSame( '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $out );
	}

	public function test_managed_pool_filter_ignores_non_wpcom_connector(): void {
		putenv( 'SIMPLE_X402_WPCOM_POOL_ADDRESS=0x2222222222222222222222222222222222222222' );
		$out = JetpackSiteState::filter_managed_pool_pay_to( '', 'simple_x402_test' );
		$this->assertSame( '', $out );
	}
}
