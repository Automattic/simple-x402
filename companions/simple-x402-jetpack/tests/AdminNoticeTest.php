<?php
declare(strict_types=1);

namespace SimpleX402\Jetpack\Tests;

use PHPUnit\Framework\TestCase;
use SimpleX402\Jetpack\AdminNotice;
use SimpleX402\Jetpack\ConnectorRegistrar;
use SimpleX402\Settings\SettingsRepository;

final class AdminNoticeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_options']             = array();
		$GLOBALS['__sx402_existing_terms']      = array();
		$GLOBALS['__sx402_current_user_caps']   = null; // admin by default via stub
		// Pretend we're on the Simple x402 settings screen so the notice
		// reaches its facilitator/jetpack checks; tests that want the
		// screen-gate early-return can unset this.
		$GLOBALS['__sx402_current_screen_id']   = 'settings_page_simple-x402';
	}

	public function test_skips_rendering_off_the_simple_x402_settings_screen(): void {
		$GLOBALS['__sx402_current_screen_id']                           = 'dashboard';
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'selected_facilitator_id' => ConnectorRegistrar::ID,
		);

		ob_start();
		( new AdminNotice() )->maybe_render();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_skips_rendering_when_different_facilitator_selected(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'selected_facilitator_id' => 'simple_x402_test',
		);

		ob_start();
		( new AdminNotice() )->maybe_render();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_skips_rendering_when_no_facilitator_selected(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'selected_facilitator_id' => '',
		);

		ob_start();
		( new AdminNotice() )->maybe_render();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_no_notice_rendered_when_client_present_and_no_manager_to_contradict(): void {
		// Bootstrap class_aliases a Client stub but no Manager. detect_issue()
		// therefore sees "client available, no contrary signal" → null → no
		// notice. The branches that DO emit a notice (Client missing, or
		// Manager present and is_connected()=false) can't be exercised here
		// because the Client alias is installed permanently by bootstrap.
		$this->assertTrue( class_exists( '\\Automattic\\Jetpack\\Connection\\Client' ) );
		$this->assertFalse( class_exists( '\\Automattic\\Jetpack\\Connection\\Manager' ) );

		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'selected_facilitator_id' => ConnectorRegistrar::ID,
		);

		ob_start();
		( new AdminNotice() )->maybe_render();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_detect_issue_returns_null_when_client_stub_loaded(): void {
		$this->assertNull( AdminNotice::detect_issue() );
	}

	// The 'jetpack_missing' branch (Client class not present) and the
	// 'jetpack_not_connected' branch (Manager present + is_connected()=false)
	// can't be exercised without process isolation — the Client stub is
	// permanently aliased once the bootstrap runs, and we'd need to swap in
	// a Manager stub dynamically. Both branches are short enough to audit
	// by reading the code.
}
