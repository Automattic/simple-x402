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
	//
	// IMPORTANT: WordPress calls `pre_update_option_{option}` with args in
	// (new_value, old_value) order. Tests below use that same convention so
	// they fail if the method's parameter declaration gets swapped.

	public function test_pre_update_passes_through_when_category_unchanged(): void {
		$value = array( 'paywall_category' => 'paywall', 'paywall_mode' => 'category' );
		$out   = $this->orchestrator->on_pre_update( $value, $value );
		$this->assertSame( $value, $out );
		$this->assertSame( array(), $GLOBALS['__sx402_settings_errors'] );
	}

	public function test_pre_update_passes_through_on_first_save(): void {
		// First save: no previously stored value. WP calls us with (new, old=[]).
		$new = array( 'paywall_category' => 'paywall', 'paywall_mode' => 'category' );
		$out = $this->orchestrator->on_pre_update( $new, array() );
		$this->assertSame( 'paywall', $out['paywall_category'] );
		$this->assertSame( array(), $GLOBALS['__sx402_settings_errors'] );
	}

	public function test_pre_update_passes_through_when_no_collision(): void {
		// Renaming from `paywall` → `Premium` with no existing `Premium` term.
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 1, 'name' => 'paywall', 'taxonomy' => 'category' ),
		);
		// WP convention: (new, old).
		$out = $this->orchestrator->on_pre_update(
			array( 'paywall_category' => 'Premium' ),
			array( 'paywall_category' => 'paywall' )
		);
		$this->assertSame( 'Premium', $out['paywall_category'] );
		$this->assertSame( array(), $GLOBALS['__sx402_settings_errors'] );
	}

	public function test_pre_update_reverts_on_collision_and_emits_error(): void {
		// `Premium` already exists separately — rename must be blocked.
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 1, 'name' => 'paywall', 'taxonomy' => 'category' ),
			array( 'term_id' => 2, 'name' => 'Premium', 'taxonomy' => 'category' ),
		);
		$out = $this->orchestrator->on_pre_update(
			array( 'paywall_category' => 'Premium' ),
			array( 'paywall_category' => 'paywall' )
		);
		$this->assertSame( 'paywall', $out['paywall_category'] );
		$this->assertCount( 1, $GLOBALS['__sx402_settings_errors'] );
		$this->assertSame( 'error', $GLOBALS['__sx402_settings_errors'][0]['type'] );
	}

	public function test_pre_update_ignores_non_array_value(): void {
		// First arg is the new value per WP; non-array returns unchanged.
		$out = $this->orchestrator->on_pre_update( 'garbage', array() );
		$this->assertSame( 'garbage', $out );
	}

	// Regression tests for the user-reported bug: renaming a stored category to
	// a non-existing target should *just rename*, not emit a collision error.
	// This fails hard if the method parameters are swapped.

	public function test_pre_update_allows_rename_to_non_existing_target(): void {
		// Reproduces the reported scenario: paywall_category='test' stored,
		// admin submits 'test1'. 'test1' does NOT exist — rename should proceed.
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 1, 'name' => 'test', 'taxonomy' => 'category' ),
		);
		$out = $this->orchestrator->on_pre_update(
			array( 'paywall_category' => 'test1' ),
			array( 'paywall_category' => 'test' )
		);
		$this->assertSame( 'test1', $out['paywall_category'] );
		$this->assertSame(
			array(),
			$GLOBALS['__sx402_settings_errors'],
			'Renaming to a non-existing target must not emit a collision error.'
		);
	}

	public function test_pre_update_emits_exactly_one_collision_notice(): void {
		// User's reported scenario: stored paywall_category='test', submitted
		// 'paywall' (which exists as default). Assert only ONE error notice
		// is queued from a single on_pre_update call. If the user sees the
		// message twice in the admin, the duplication is in the render layer.
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 1, 'name' => 'test', 'taxonomy' => 'category' ),
			array( 'term_id' => 2, 'name' => 'paywall', 'taxonomy' => 'category' ),
		);
		$this->orchestrator->on_pre_update(
			array( 'paywall_category' => 'paywall' ),
			array( 'paywall_category' => 'test' )
		);
		$this->assertCount(
			1,
			$GLOBALS['__sx402_settings_errors'],
			'A single save must queue exactly one collision notice.'
		);
	}

	public function test_pre_update_preserves_other_fields_from_new_value(): void {
		// If pre_update mutates the wrong array, the admin's changes to
		// unrelated fields (mode, price) will be silently dropped and the
		// "old" array will be persisted instead. Catch that.
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 1, 'name' => 'paywall', 'taxonomy' => 'category' ),
		);
		$new = array(
			'paywall_category' => 'paywall',
			'paywall_mode'     => 'all-posts',
			'default_price'    => '0.05',
		);
		$old = array(
			'paywall_category' => 'paywall',
			'paywall_mode'     => 'category',
			'default_price'    => '0.01',
		);
		$out = $this->orchestrator->on_pre_update( $new, $old );
		$this->assertSame( 'all-posts', $out['paywall_mode'] );
		$this->assertSame( '0.05', $out['default_price'] );
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
