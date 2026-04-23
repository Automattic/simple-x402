<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Admin\SettingsPage;
use SimpleX402\Connectors\ConnectorRegistry;
use SimpleX402\Settings\SettingsRepository;

final class SettingsPageTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_options']             = array();
		$GLOBALS['__sx402_registered_settings'] = array();
		$GLOBALS['__sx402_enqueued_scripts']    = array();
		$GLOBALS['__sx402_enqueued_styles']     = array();
		$GLOBALS['__sx402_localized_data']      = array();
		$GLOBALS['__sx402_connectors']          = array();
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

	public function test_sanitize_callback_returns_flat_shape_without_persisting(): void {
		$page = new SettingsPage( new SettingsRepository() );
		$page->register_settings();

		$callback = $GLOBALS['__sx402_registered_settings'][ SettingsPage::GROUP ][ SettingsRepository::OPTION_NAME ]['sanitize_callback'];

		$result = $callback(
			array(
				'wallet_address'           => '0xABC',
				'default_price'            => '0.5',
				'selected_facilitator_id'  => 'simple_x402_test',
				'paywall_category_term_id' => 2,
			)
		);

		$this->assertSame( '0xABC', $result['wallet_address'] );
		$this->assertSame( '0.5', $result['default_price'] );
		$this->assertSame( 'simple_x402_test', $result['selected_facilitator_id'] );
		$this->assertSame( 'none', $result['paywall_mode'] );
		$this->assertSame( 'bots', $result['paywall_audience'] );
		$this->assertSame( 2, $result['paywall_category_term_id'] );
		// Regression: the callback must be pure (no persistence) so WP's
		// update_option doesn't recurse during register_setting sanitization.
		$this->assertArrayNotHasKey( SettingsRepository::OPTION_NAME, $GLOBALS['__sx402_options'] );
	}

	public function test_sanitize_callback_falls_back_to_default_for_bad_price(): void {
		$page = new SettingsPage( new SettingsRepository() );
		$page->register_settings();

		$callback = $GLOBALS['__sx402_registered_settings'][ SettingsPage::GROUP ][ SettingsRepository::OPTION_NAME ]['sanitize_callback'];
		$result   = $callback( array( 'default_price' => 'nope' ) );

		$this->assertSame( '0.01', $result['default_price'] );
	}

	public function test_render_emits_form_with_mount_point(): void {
		$page = new SettingsPage( new SettingsRepository() );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/<form[^>]*action="options\.php"/', $html );
		$this->assertStringContainsString( 'id="simple-x402-app"', $html );
	}

	public function test_bootstrap_data_exposes_values_categories_and_facilitators(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'wallet_address'           => '0xabc',
			'default_price'            => '0.05',
			'selected_facilitator_id'  => 'simple_x402_test',
			'paywall_mode'             => 'category',
			'paywall_category_term_id' => 2,
		);
		$GLOBALS['__sx402_connectors']['simple_x402_test'] = array(
			'type'        => ConnectorRegistry::FACILITATOR_TYPE,
			'name'        => 'Simple x402 (test)',
			'description' => 'Testnet',
		);

		$boot = ( new SettingsPage( new SettingsRepository() ) )->bootstrap_data();

		$this->assertSame( '0xabc', $boot['values']['wallet_address'] );
		$this->assertSame( '0.05', $boot['values']['default_price'] );
		$this->assertSame( 'simple_x402_test', $boot['values']['selected_facilitator_id'] );
		$this->assertSame( 2, $boot['values']['paywall_category_term_id'] );

		$this->assertSame(
			array(
				array( 'term_id' => 1, 'name' => 'x402paywall' ),
				array( 'term_id' => 2, 'name' => 'News' ),
			),
			$boot['categories']
		);

		$this->assertSame(
			array(
				array(
					'id'          => 'simple_x402_test',
					'name'        => 'Simple x402 (test)',
					'description' => 'Testnet',
				),
			),
			$boot['facilitators']
		);

		$this->assertSame( SettingsRepository::PAYWALL_MODE_NONE, $boot['modes']['paywall']['none'] );
		$this->assertSame( SettingsRepository::AUDIENCE_BOTS, $boot['modes']['audience']['bots'] );
	}
}
