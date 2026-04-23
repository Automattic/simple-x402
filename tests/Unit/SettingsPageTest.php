<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Admin\SettingsPage;
use SimpleX402\Services\FacilitatorProfile;
use SimpleX402\Settings\SettingsRepository;

final class SettingsPageTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_options']             = array();
		$GLOBALS['__sx402_registered_settings'] = array();
		$GLOBALS['__sx402_enqueued_scripts']    = array();
		$GLOBALS['__sx402_enqueued_styles']     = array();
		$GLOBALS['__sx402_localized_data']      = array();
		$GLOBALS['__sx402_existing_terms']      = array(
			array( 'term_id' => 1, 'name' => 'x402paywall', 'taxonomy' => 'category' ),
			array( 'term_id' => 2, 'name' => 'News', 'taxonomy' => 'category' ),
		);
	}

	public function test_enqueue_assets_registers_script_and_bootstrap_on_plugin_page(): void {
		$page = new SettingsPage( new SettingsRepository() );
		$page->enqueue_assets( 'settings_page_' . SettingsPage::MENU_SLUG );

		$this->assertArrayHasKey( SettingsPage::SCRIPT_HANDLE, $GLOBALS['__sx402_enqueued_scripts'] );
		$this->assertArrayHasKey( 'wp-components', $GLOBALS['__sx402_enqueued_styles'] );

		$boot = $GLOBALS['__sx402_localized_data'][ SettingsPage::SCRIPT_HANDLE ]['simpleX402Settings'] ?? null;
		$this->assertIsArray( $boot );
		$this->assertSame( SettingsRepository::OPTION_NAME, $boot['option'] );
		$this->assertSame( SettingsRepository::PAYWALL_MODE_CATEGORY, $boot['modeCategory'] );
	}

	public function test_enqueue_assets_skips_other_admin_pages(): void {
		$page = new SettingsPage( new SettingsRepository() );
		$page->enqueue_assets( 'dashboard' );
		$this->assertSame( array(), $GLOBALS['__sx402_enqueued_scripts'] );
	}

	public function test_sanitize_callback_returns_nested_shape_without_persisting(): void {
		$page = new SettingsPage( new SettingsRepository() );
		$page->register_settings();

		$callback = $GLOBALS['__sx402_registered_settings'][ SettingsPage::GROUP ][ SettingsRepository::OPTION_NAME ]['sanitize_callback'];

		$result = $callback(
			array(
				'mode' => 'test',
				'test' => array( 'wallet_address' => '0xABC', 'default_price' => '0.5' ),
				'live' => array( 'wallet_address' => '0xLIVE', 'default_price' => '0.01', 'facilitator_url' => '', 'facilitator_api_key' => 'k' ),
				'paywall_category_term_id' => 2,
			)
		);

		$this->assertSame( 'test', $result['mode'] );
		$this->assertSame( '0xABC', $result['test']['wallet_address'] );
		$this->assertSame( '0.5', $result['test']['default_price'] );
		$this->assertSame( '0xLIVE', $result['live']['wallet_address'] );
		$this->assertSame( 'k', $result['live']['facilitator_api_key'] );
		$this->assertSame( 'none', $result['paywall_mode'] );
		$this->assertSame( 'bots', $result['paywall_audience'] );
		$this->assertSame( 2, $result['paywall_category_term_id'] );
		// Regression: the callback must be pure (no persistence) so WP's
		// update_option doesn't recurse during register_setting sanitization.
		$this->assertArrayNotHasKey( SettingsRepository::OPTION_NAME, $GLOBALS['__sx402_options'] );
	}

	public function test_sanitize_callback_falls_back_to_default_for_bad_price_per_mode(): void {
		$page = new SettingsPage( new SettingsRepository() );
		$page->register_settings();

		$callback = $GLOBALS['__sx402_registered_settings'][ SettingsPage::GROUP ][ SettingsRepository::OPTION_NAME ]['sanitize_callback'];

		$result = $callback(
			array(
				'mode' => 'test',
				'test' => array( 'wallet_address' => '0xABC', 'default_price' => 'nope' ),
			)
		);

		$this->assertSame( '0.01', $result['test']['default_price'] );
	}

	public function test_render_emits_form_with_mount_point(): void {
		$page = new SettingsPage( new SettingsRepository() );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/<form[^>]*action="options\.php"/', $html );
		$this->assertStringContainsString( 'id="simple-x402-app"', $html );
	}

	public function test_bootstrap_data_includes_categories_and_stored_values(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'mode' => 'live',
			'paywall_mode' => 'category',
			'paywall_category_term_id' => 2,
			'test' => array( 'wallet_address' => '0xT', 'default_price' => '0.0001' ),
			'live' => array( 'wallet_address' => '0xL', 'default_price' => '0.05', 'facilitator_url' => '', 'facilitator_api_key' => 'k' ),
		);

		$boot = ( new SettingsPage( new SettingsRepository() ) )->bootstrap_data();

		$this->assertSame( FacilitatorProfile::MODE_LIVE, $boot['values']['mode'] );
		$this->assertSame( SettingsRepository::PAYWALL_MODE_CATEGORY, $boot['values']['paywall_mode'] );
		$this->assertSame( 2, $boot['values']['paywall_category_term_id'] );
		$this->assertSame( '0xT', $boot['values']['test']['wallet_address'] );
		$this->assertSame( '0xL', $boot['values']['live']['wallet_address'] );
		$this->assertSame( 'k', $boot['values']['live']['facilitator_api_key'] );

		$this->assertSame(
			array(
				array( 'term_id' => 1, 'name' => 'x402paywall' ),
				array( 'term_id' => 2, 'name' => 'News' ),
			),
			$boot['categories']
		);

		$this->assertSame( SettingsRepository::PAYWALL_MODE_NONE, $boot['modes']['paywall']['none'] );
		$this->assertSame( FacilitatorProfile::MODE_TEST, $boot['modes']['facilitator']['test'] );
		$this->assertSame( SettingsRepository::AUDIENCE_BOTS, $boot['modes']['audience']['bots'] );
	}
}
