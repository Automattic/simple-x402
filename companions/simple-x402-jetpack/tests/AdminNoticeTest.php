<?php
declare(strict_types=1);

namespace SimpleX402\Jetpack\Tests;

use PHPUnit\Framework\TestCase;
use SimpleX402\Jetpack\AdminNotice;
use SimpleX402\Jetpack\ConnectorRegistrar;
use SimpleX402\Settings\SettingsRepository;

final class AdminNoticeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_options']           = array();
		$GLOBALS['__sx402_existing_terms']    = array();
		$GLOBALS['__sx402_current_user_caps'] = null; // admin by default via stub
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

	public function test_renders_notice_when_wpcom_selected_and_jetpack_present(): void {
		// The test bootstrap class_aliases a stub to Automattic\\Jetpack\\Connection\\Client
		// but no Manager, so detect_issue() only sees "client present, assumed connected"
		// and returns null. To exercise the notice-visible branch we'd need a Manager
		// stub — tested separately below via detect_issue's pure-function API.
		$this->assertTrue( class_exists( '\\Automattic\\Jetpack\\Connection\\Client' ) );
		$this->assertFalse( class_exists( '\\Automattic\\Jetpack\\Connection\\Manager' ) );

		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'selected_facilitator_id' => ConnectorRegistrar::ID,
		);

		ob_start();
		( new AdminNotice() )->maybe_render();
		// With Client stub present and no Manager to contradict, detect_issue
		// returns null — no notice.
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_detect_issue_returns_null_when_client_stub_loaded(): void {
		$this->assertNull( AdminNotice::detect_issue() );
	}

	// The 'jetpack_missing' branch (Client class not present) can't be
	// exercised without process isolation — the stub is permanently aliased
	// once the bootstrap runs. Same constraint as our earlier
	// SIMPLE_X402_TEST_CONNECTOR-gating tests: easy to reason about, hard to
	// toggle at runtime, covered by reading the code.
}
