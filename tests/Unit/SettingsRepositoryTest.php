<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Settings\SettingsRepository;

final class SettingsRepositoryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_options'] = array();
	}

	public function test_defaults_when_nothing_stored(): void {
		$repo = new SettingsRepository();
		$this->assertSame( '', $repo->wallet_address() );
		$this->assertSame( '0.01', $repo->default_price() );
	}

	public function test_save_then_read(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'wallet_address' => '0xabc', 'default_price' => '0.25' ) );
		$this->assertSame( '0xabc', $repo->wallet_address() );
		$this->assertSame( '0.25', $repo->default_price() );
	}

	public function test_save_rejects_negative_price(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'wallet_address' => '0xabc', 'default_price' => '-1' ) );
		$this->assertSame( '0.01', $repo->default_price() );
	}
}
