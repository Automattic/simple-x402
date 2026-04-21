<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Services\CategoryRepository;
use SimpleX402\Services\PaywallCategoryGuard;
use SimpleX402\Services\SettingsChangeNotifier;
use SimpleX402\Settings\SettingsRepository;

final class PaywallCategoryGuardTest extends TestCase {

	private PaywallCategoryGuard $guard;

	protected function setUp(): void {
		$GLOBALS['__sx402_options']         = array();
		$GLOBALS['__sx402_existing_terms']  = array();
		$GLOBALS['__sx402_inserted_terms']  = array();
		$GLOBALS['__sx402_settings_errors'] = array();

		$this->guard = new PaywallCategoryGuard(
			new SettingsRepository(),
			new CategoryRepository(),
			new SettingsChangeNotifier()
		);
	}

	/**
	 * Simulate WordPress's `delete_term` action firing — the term is already
	 * gone from the DB by the time the hook runs. In the stub world that means
	 * __sx402_existing_terms does NOT contain the deleted row; we pass the
	 * deleted term object directly.
	 */
	private function fire_delete_term( string $name, string $taxonomy = 'category', int $term_id = 99 ): void {
		$deleted           = new \stdClass();
		$deleted->term_id  = $term_id;
		$deleted->name     = $name;
		$deleted->taxonomy = $taxonomy;
		( $this->guard )( $term_id, $term_id * 10, $taxonomy, $deleted );
	}

	public function test_resets_setting_to_default_when_paywall_category_is_deleted(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'wallet_address'   => '0xabc',
			'default_price'    => '0.25',
			'paywall_mode'     => 'category',
			'paywall_category' => 'News',
		);
		$this->fire_delete_term( 'News' );

		$stored = $GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ];
		$this->assertSame(
			SettingsRepository::DEFAULT_CATEGORY,
			$stored['paywall_category'],
			'paywall_category must be reset to the default after its term is deleted.'
		);
		// Other fields untouched — set_paywall_category must preserve them.
		$this->assertSame( '0xabc', $stored['wallet_address'] );
		$this->assertSame( '0.25', $stored['default_price'] );
		$this->assertSame( 'category', $stored['paywall_mode'] );
	}

	public function test_ensures_default_term_exists_after_reset(): void {
		// Stored paywall_category points at a category that's now being deleted.
		// The default term does NOT currently exist.
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_category' => 'News',
		);
		$this->fire_delete_term( 'News' );

		$this->assertCount(
			1,
			$GLOBALS['__sx402_inserted_terms'],
			'Guard must ensure the default paywall term exists after reset.'
		);
		$this->assertSame(
			SettingsRepository::DEFAULT_CATEGORY,
			$GLOBALS['__sx402_inserted_terms'][0]['name']
		);
	}

	public function test_recreates_default_term_when_default_itself_is_deleted(): void {
		// Edge case: admin deletes the default paywall term itself.
		// delete_term fires after the row is gone, so ensure() must re-create.
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_category' => SettingsRepository::DEFAULT_CATEGORY,
		);
		$this->fire_delete_term( SettingsRepository::DEFAULT_CATEGORY );

		$stored = $GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ];
		$this->assertSame( SettingsRepository::DEFAULT_CATEGORY, $stored['paywall_category'] );
		$this->assertCount( 1, $GLOBALS['__sx402_inserted_terms'] );
		$this->assertSame(
			SettingsRepository::DEFAULT_CATEGORY,
			$GLOBALS['__sx402_inserted_terms'][0]['name']
		);
	}

	public function test_emits_warning_notice_when_paywall_category_deleted(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_category' => 'News',
		);
		$this->fire_delete_term( 'News' );

		$codes = array_column( $GLOBALS['__sx402_settings_errors'], 'code' );
		$this->assertContains( 'simple_x402_category_deleted', $codes );
	}

	public function test_ignores_deletion_of_unrelated_category(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_category' => 'News',
		);
		$this->fire_delete_term( 'Sports' );

		$stored = $GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ];
		$this->assertSame(
			'News',
			$stored['paywall_category'],
			'Deletion of a non-paywall category must not touch the setting.'
		);
		$this->assertSame( array(), $GLOBALS['__sx402_inserted_terms'] );
		$this->assertSame( array(), $GLOBALS['__sx402_settings_errors'] );
	}

	public function test_ignores_non_category_taxonomies(): void {
		// Someone named a post_tag identically to the paywall category and
		// deleted it — ignore (we only care about the `category` taxonomy).
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_category' => SettingsRepository::DEFAULT_CATEGORY,
		);
		$this->fire_delete_term( SettingsRepository::DEFAULT_CATEGORY, 'post_tag' );

		$this->assertSame( array(), $GLOBALS['__sx402_inserted_terms'] );
		$this->assertSame( array(), $GLOBALS['__sx402_settings_errors'] );
	}

	public function test_ignores_deletion_when_deleted_term_arg_is_not_an_object(): void {
		// Defensive: WP passes a WP_Term or WP_Error. If callers wire the hook
		// with mismatched accepted_args, the object could be missing. Don't blow up.
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_category' => 'News',
		);
		( $this->guard )( 99, 990, 'category', null );

		$stored = $GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ];
		$this->assertSame( 'News', $stored['paywall_category'] );
	}
}
