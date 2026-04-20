<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Services\GrantStore;

final class GrantStoreTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_transients'] = array();
	}

	public function test_has_grant_false_by_default(): void {
		$store = new GrantStore();
		$this->assertFalse( $store->has_grant( '0xabc', '/foo' ) );
	}

	public function test_issue_then_has_grant(): void {
		$store = new GrantStore();
		$store->issue( '0xABC', '/foo', 60, array( 'tx' => '0x1' ) );
		// Wallet match is case-insensitive.
		$this->assertTrue( $store->has_grant( '0xabc', '/foo' ) );
	}

	public function test_grant_is_scoped_to_path(): void {
		$store = new GrantStore();
		$store->issue( '0xabc', '/foo', 60, array() );
		$this->assertFalse( $store->has_grant( '0xabc', '/bar' ) );
	}

	public function test_empty_wallet_never_matches(): void {
		$store = new GrantStore();
		$store->issue( '', '/foo', 60, array() );
		$this->assertFalse( $store->has_grant( '', '/foo' ) );
	}

	public function test_non_positive_ttl_is_ignored(): void {
		$store = new GrantStore();
		$store->issue( '0xabc', '/foo', 0, array() );
		$this->assertFalse( $store->has_grant( '0xabc', '/foo' ) );
	}
}
