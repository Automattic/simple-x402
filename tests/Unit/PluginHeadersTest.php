<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Plugin;

final class PluginHeadersTest extends TestCase {

	/**
	 * Bug fix regression test: $_SERVER delivers HTTP_* keys in ALL_CAPS
	 * with underscores. Our collector used to emit them as `X-WALLET-ADDRESS`,
	 * but the PaywallController looks up `X-Wallet-Address` — so the wallet-
	 * hint grant reuse silently didn't work on real traffic. Now normalised
	 * to canonical HTTP title-case at collection time.
	 */
	public function test_collect_headers_normalises_to_title_case(): void {
		$_SERVER['HTTP_X_WALLET_ADDRESS']  = '0xBuyer';
		$_SERVER['HTTP_PAYMENT_SIGNATURE'] = 'abc';
		$_SERVER['HTTP_USER_AGENT']        = 'Mozilla/5.0';

		$reflection = new \ReflectionMethod( Plugin::class, 'collect_headers' );
		$reflection->setAccessible( true );
		$headers = $reflection->invoke( null );

		$this->assertArrayHasKey( 'X-Wallet-Address', $headers );
		$this->assertSame( '0xBuyer', $headers['X-Wallet-Address'] );
		$this->assertArrayHasKey( 'Payment-Signature', $headers );
		$this->assertArrayHasKey( 'User-Agent', $headers );

		unset( $_SERVER['HTTP_X_WALLET_ADDRESS'], $_SERVER['HTTP_PAYMENT_SIGNATURE'], $_SERVER['HTTP_USER_AGENT'] );
	}

	public function test_collect_headers_always_includes_accept_and_sec_fetch_keys(): void {
		$reflection = new \ReflectionMethod( Plugin::class, 'collect_headers' );
		$reflection->setAccessible( true );
		$headers = $reflection->invoke( null );

		$this->assertArrayHasKey( 'Accept', $headers );
		$this->assertArrayHasKey( 'Sec-Fetch-Mode', $headers );
		$this->assertArrayHasKey( 'Sec-Fetch-Dest', $headers );
		$this->assertSame( '', $headers['Accept'] );
		$this->assertSame( '', $headers['Sec-Fetch-Mode'] );
		$this->assertSame( '', $headers['Sec-Fetch-Dest'] );
	}

	public function test_collect_headers_maps_accept_and_sec_fetch_from_server(): void {
		$_SERVER['HTTP_ACCEPT']         = 'text/html, application/json;q=0.9';
		$_SERVER['HTTP_SEC_FETCH_MODE'] = 'navigate';
		$_SERVER['HTTP_SEC_FETCH_DEST'] = 'document';

		$reflection = new \ReflectionMethod( Plugin::class, 'collect_headers' );
		$reflection->setAccessible( true );
		$headers = $reflection->invoke( null );

		$this->assertSame( 'text/html, application/json;q=0.9', $headers['Accept'] );
		$this->assertSame( 'navigate', $headers['Sec-Fetch-Mode'] );
		$this->assertSame( 'document', $headers['Sec-Fetch-Dest'] );

		unset( $_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_SEC_FETCH_MODE'], $_SERVER['HTTP_SEC_FETCH_DEST'] );
	}
}
