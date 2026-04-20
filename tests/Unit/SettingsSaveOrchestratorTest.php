<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Services\CategoryRepository;
use SimpleX402\Services\SettingsChangeNotifier;
use SimpleX402\Services\SettingsSaveOrchestrator;
use SimpleX402\Settings\SettingsRepository;

final class SettingsSaveOrchestratorTest extends TestCase {

	private SettingsSaveOrchestrator $orchestrator;

	protected function setUp(): void {
		$GLOBALS['__sx402_existing_terms']  = array();
		$GLOBALS['__sx402_inserted_terms']  = array();
		$GLOBALS['__sx402_settings_errors'] = array();

		$this->orchestrator = new SettingsSaveOrchestrator(
			new CategoryRepository(),
			new SettingsChangeNotifier()
		);
	}

	// on_pre_update

	public function test_pre_update_passes_through_when_category_unchanged(): void {
		$value = array( 'paywall_category' => 'paywall', 'paywall_mode' => 'category' );
		$out   = $this->orchestrator->on_pre_update( $value, $value );
		$this->assertSame( $value, $out );
		$this->assertSame( array(), $GLOBALS['__sx402_settings_errors'] );
	}

	public function test_pre_update_passes_through_on_first_save(): void {
		$out = $this->orchestrator->on_pre_update(
			array(),
			array( 'paywall_category' => 'paywall', 'paywall_mode' => 'category' )
		);
		$this->assertSame( 'paywall', $out['paywall_category'] );
		$this->assertSame( array(), $GLOBALS['__sx402_settings_errors'] );
	}

	public function test_pre_update_passes_through_when_no_collision(): void {
		// Renaming from `paywall` → `Premium` with no existing `Premium` term.
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 1, 'name' => 'paywall', 'taxonomy' => 'category' ),
		);
		$out = $this->orchestrator->on_pre_update(
			array( 'paywall_category' => 'paywall' ),
			array( 'paywall_category' => 'Premium' )
		);
		$this->assertSame( 'Premium', $out['paywall_category'] );
		$this->assertSame( array(), $GLOBALS['__sx402_settings_errors'] );
	}

	public function test_pre_update_reverts_on_collision_and_emits_error(): void {
		// `Premium` already exists — rename must be blocked.
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 1, 'name' => 'paywall', 'taxonomy' => 'category' ),
			array( 'term_id' => 2, 'name' => 'Premium', 'taxonomy' => 'category' ),
		);
		$out = $this->orchestrator->on_pre_update(
			array( 'paywall_category' => 'paywall' ),
			array( 'paywall_category' => 'Premium' )
		);
		$this->assertSame( 'paywall', $out['paywall_category'] );
		$this->assertCount( 1, $GLOBALS['__sx402_settings_errors'] );
		$this->assertSame( 'error', $GLOBALS['__sx402_settings_errors'][0]['type'] );
	}

	public function test_pre_update_ignores_non_array_value(): void {
		$out = $this->orchestrator->on_pre_update( array(), 'garbage' );
		$this->assertSame( 'garbage', $out );
	}

	// on_update

	public function test_on_update_ensures_category_on_first_save(): void {
		$this->orchestrator->on_update(
			array(),
			array( 'paywall_category' => 'Premium', 'paywall_mode' => 'category' )
		);
		$this->assertCount( 1, $GLOBALS['__sx402_inserted_terms'] );
		$this->assertSame( 'Premium', $GLOBALS['__sx402_inserted_terms'][0]['name'] );
	}

	public function test_on_update_renames_existing_term_and_notifies(): void {
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 1, 'name' => 'paywall', 'taxonomy' => 'category' ),
		);
		$this->orchestrator->on_update(
			array( 'paywall_category' => 'paywall', 'paywall_mode' => 'category' ),
			array( 'paywall_category' => 'Premium', 'paywall_mode' => 'category' )
		);
		// Renamed in place: same term_id, new name.
		$this->assertSame( 'Premium', $GLOBALS['__sx402_existing_terms'][0]['name'] );
		$this->assertSame( 1, $GLOBALS['__sx402_existing_terms'][0]['term_id'] );
		// Rename-succeeded notice emitted.
		$codes = array_column( $GLOBALS['__sx402_settings_errors'], 'code' );
		$this->assertContains( 'simple_x402_category_renamed', $codes );
	}

	public function test_on_update_falls_back_to_ensure_when_old_term_missing(): void {
		// Old term was deleted manually — no rename possible, just create new one.
		$this->orchestrator->on_update(
			array( 'paywall_category' => 'paywall', 'paywall_mode' => 'category' ),
			array( 'paywall_category' => 'Premium', 'paywall_mode' => 'category' )
		);
		$this->assertCount( 1, $GLOBALS['__sx402_inserted_terms'] );
		$this->assertSame( 'Premium', $GLOBALS['__sx402_inserted_terms'][0]['name'] );
		$codes = array_column( $GLOBALS['__sx402_settings_errors'], 'code' );
		$this->assertNotContains( 'simple_x402_category_renamed', $codes );
	}

	public function test_on_update_emits_all_posts_notice_when_mode_switches(): void {
		$this->orchestrator->on_update(
			array( 'paywall_mode' => 'category', 'paywall_category' => 'paywall' ),
			array( 'paywall_mode' => 'all-posts', 'paywall_category' => 'paywall' )
		);
		$codes = array_column( $GLOBALS['__sx402_settings_errors'], 'code' );
		$this->assertContains( 'simple_x402_all_posts_mode', $codes );
	}

	public function test_on_update_does_not_emit_all_posts_notice_when_mode_unchanged(): void {
		$this->orchestrator->on_update(
			array( 'paywall_mode' => 'all-posts', 'paywall_category' => 'paywall' ),
			array( 'paywall_mode' => 'all-posts', 'paywall_category' => 'paywall' )
		);
		$codes = array_column( $GLOBALS['__sx402_settings_errors'], 'code' );
		$this->assertNotContains( 'simple_x402_all_posts_mode', $codes );
	}

	public function test_on_update_ignores_non_array_value(): void {
		$this->orchestrator->on_update( array(), 'garbage' );
		$this->assertSame( array(), $GLOBALS['__sx402_inserted_terms'] );
		$this->assertSame( array(), $GLOBALS['__sx402_settings_errors'] );
	}
}
