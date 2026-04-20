<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Services\SettingsChangeNotifier;
use SimpleX402\Settings\SettingsRepository;

final class SettingsChangeNotifierTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_settings_errors'] = array();
	}

	public function test_notify_rename_succeeded_emits_info(): void {
		( new SettingsChangeNotifier() )->notify_rename_succeeded( 'paywall', 'Premium' );
		$this->assertCount( 1, $GLOBALS['__sx402_settings_errors'] );
		$err = $GLOBALS['__sx402_settings_errors'][0];
		$this->assertSame( SettingsRepository::OPTION_NAME, $err['setting'] );
		$this->assertSame( 'info', $err['type'] );
		$this->assertStringContainsString( 'paywall', $err['message'] );
		$this->assertStringContainsString( 'Premium', $err['message'] );
		$this->assertStringContainsString( 'remain paywalled', $err['message'] );
	}

	public function test_notify_rename_collision_emits_error(): void {
		( new SettingsChangeNotifier() )->notify_rename_collision( 'News' );
		$this->assertCount( 1, $GLOBALS['__sx402_settings_errors'] );
		$err = $GLOBALS['__sx402_settings_errors'][0];
		$this->assertSame( SettingsRepository::OPTION_NAME, $err['setting'] );
		$this->assertSame( 'error', $err['type'] );
		$this->assertStringContainsString( 'News', $err['message'] );
		$this->assertStringContainsString( 'already exists', $err['message'] );
	}

	public function test_notify_mode_switched_to_all_posts_emits_info(): void {
		( new SettingsChangeNotifier() )->notify_mode_switched_to_all_posts();
		$this->assertCount( 1, $GLOBALS['__sx402_settings_errors'] );
		$err = $GLOBALS['__sx402_settings_errors'][0];
		$this->assertSame( SettingsRepository::OPTION_NAME, $err['setting'] );
		$this->assertSame( 'info', $err['type'] );
		$this->assertStringContainsString( 'Every published post', $err['message'] );
	}
}
