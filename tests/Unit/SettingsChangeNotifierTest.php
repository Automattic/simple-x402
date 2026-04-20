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

	public function test_no_messages_when_nothing_relevant_changed(): void {
		$notifier = new SettingsChangeNotifier();
		$old      = array(
			'paywall_mode'     => 'category',
			'paywall_category' => 'paywall',
		);
		$new = array(
			'paywall_mode'     => 'category',
			'paywall_category' => 'paywall',
			'default_price'    => '0.99',
		);
		$notifier->notify( $old, $new );
		$this->assertSame( array(), $GLOBALS['__sx402_settings_errors'] );
	}

	public function test_warns_on_category_rename(): void {
		$notifier = new SettingsChangeNotifier();
		$notifier->notify(
			array( 'paywall_category' => 'paywall', 'paywall_mode' => 'category' ),
			array( 'paywall_category' => 'Premium', 'paywall_mode' => 'category' )
		);
		$this->assertCount( 1, $GLOBALS['__sx402_settings_errors'] );
		$err = $GLOBALS['__sx402_settings_errors'][0];
		$this->assertSame( SettingsRepository::OPTION_NAME, $err['setting'] );
		$this->assertSame( 'warning', $err['type'] );
		$this->assertStringContainsString( 'paywall', $err['message'] );
		$this->assertStringContainsString( 'Premium', $err['message'] );
		$this->assertStringContainsString( 'reassign', $err['message'] );
	}

	public function test_no_rename_message_on_first_save(): void {
		// No previous category set — nothing to rename "from".
		$notifier = new SettingsChangeNotifier();
		$notifier->notify(
			array(),
			array( 'paywall_category' => 'paywall', 'paywall_mode' => 'category' )
		);
		$this->assertSame( array(), $GLOBALS['__sx402_settings_errors'] );
	}

	public function test_info_when_mode_switches_to_all_posts(): void {
		$notifier = new SettingsChangeNotifier();
		$notifier->notify(
			array( 'paywall_mode' => 'category', 'paywall_category' => 'paywall' ),
			array( 'paywall_mode' => 'all-posts', 'paywall_category' => 'paywall' )
		);
		$this->assertCount( 1, $GLOBALS['__sx402_settings_errors'] );
		$err = $GLOBALS['__sx402_settings_errors'][0];
		$this->assertSame( 'info', $err['type'] );
		$this->assertStringContainsString( 'Every published post', $err['message'] );
	}

	public function test_no_info_when_mode_stays_all_posts(): void {
		$notifier = new SettingsChangeNotifier();
		$notifier->notify(
			array( 'paywall_mode' => 'all-posts' ),
			array( 'paywall_mode' => 'all-posts' )
		);
		$this->assertSame( array(), $GLOBALS['__sx402_settings_errors'] );
	}

	public function test_info_fires_on_first_save_with_all_posts(): void {
		// No previous mode → we still want to warn that every post is now gated.
		$notifier = new SettingsChangeNotifier();
		$notifier->notify(
			array(),
			array( 'paywall_mode' => 'all-posts', 'paywall_category' => 'paywall' )
		);
		$this->assertCount( 1, $GLOBALS['__sx402_settings_errors'] );
		$this->assertSame( 'info', $GLOBALS['__sx402_settings_errors'][0]['type'] );
	}

	public function test_both_messages_when_both_change(): void {
		$notifier = new SettingsChangeNotifier();
		$notifier->notify(
			array( 'paywall_mode' => 'category', 'paywall_category' => 'paywall' ),
			array( 'paywall_mode' => 'all-posts', 'paywall_category' => 'Premium' )
		);
		$this->assertCount( 2, $GLOBALS['__sx402_settings_errors'] );
		$types = array_column( $GLOBALS['__sx402_settings_errors'], 'type' );
		$this->assertContains( 'warning', $types );
		$this->assertContains( 'info', $types );
	}
}
