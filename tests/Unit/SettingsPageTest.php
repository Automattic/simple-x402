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
		$GLOBALS['__sx402_localized_data']      = array();
		$GLOBALS['__sx402_existing_terms']      = array(
			array( 'term_id' => 1, 'name' => 'x402paywall', 'taxonomy' => 'category' ),
			array( 'term_id' => 2, 'name' => 'News', 'taxonomy' => 'category' ),
		);
	}

	public function test_enqueue_assets_registers_script_on_plugin_page(): void {
		$page = new SettingsPage( new SettingsRepository() );
		$page->enqueue_assets( 'settings_page_' . SettingsPage::MENU_SLUG );

		$this->assertArrayHasKey( SettingsPage::SCRIPT_HANDLE, $GLOBALS['__sx402_enqueued_scripts'] );
		$localized = $GLOBALS['__sx402_localized_data'][ SettingsPage::SCRIPT_HANDLE ]['simpleX402Settings'] ?? null;
		$this->assertIsArray( $localized );
		$this->assertSame( SettingsRepository::OPTION_NAME, $localized['option'] );
		$this->assertSame( SettingsRepository::PAYWALL_MODE_CATEGORY, $localized['modeCategory'] );
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

	public function test_render_shows_three_paywall_mode_options_with_stored_selected(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'mode' => 'test',
			'paywall_mode' => 'all-posts',
			'paywall_category_term_id' => 1,
		);
		$page = new SettingsPage( new SettingsRepository() );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/name="[^"]*\[paywall_mode\]"[^>]*value="none"/', $html );
		$this->assertMatchesRegularExpression( '/name="[^"]*\[paywall_mode\]"[^>]*value="category"/', $html );
		$this->assertMatchesRegularExpression( '/name="[^"]*\[paywall_mode\]"[^>]*value="all-posts"/', $html );
		$this->assertMatchesRegularExpression( '/value="all-posts"[^>]*checked/', $html );
	}

	public function test_render_shows_category_dropdown_with_stored_term_selected(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'mode' => 'test',
			'paywall_mode' => 'category',
			'paywall_category_term_id' => 2,
		);
		$page = new SettingsPage( new SettingsRepository() );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/<select[^>]*name="[^"]*\[paywall_category_term_id\]"/',
			$html
		);
		$this->assertMatchesRegularExpression(
			'/<option value="2" selected="selected">News<\/option>/',
			$html
		);
	}

	public function test_render_has_payments_heading(): void {
		$page = new SettingsPage( new SettingsRepository() );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/<h2[^>]*>\s*Payment details\s*<\/h2>/', $html );
	}

	public function test_render_shows_two_audience_options_with_default_checked(): void {
		$page = new SettingsPage( new SettingsRepository() );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/name="[^"]*\[paywall_audience\]"[^>]*value="everyone"/', $html );
		$this->assertMatchesRegularExpression( '/name="[^"]*\[paywall_audience\]"[^>]*value="bots"/', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="[^"]*\[paywall_audience\]"[^>]*value="none"/', $html );
		$this->assertMatchesRegularExpression( '/value="bots"[^>]*checked/', $html );
	}

	public function test_render_orders_paywall_audience_payments(): void {
		$page = new SettingsPage( new SettingsRepository() );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$paywall_mode_pos = strpos( $html, '[paywall_mode]' );
		$audience_pos     = strpos( $html, '[paywall_audience]' );
		$where_pos        = strpos( $html, 'Payment details' );
		$mode_row         = strpos( $html, '[mode]' );

		$this->assertNotFalse( $paywall_mode_pos );
		$this->assertNotFalse( $mode_row );
		$this->assertLessThan( $audience_pos, $paywall_mode_pos );
		$this->assertLessThan( $where_pos, $audience_pos );
		// Mode selector is nested inside the Payments container.
		$this->assertLessThan( $mode_row, $where_pos );
	}

	public function test_render_disables_category_dropdown_when_paywall_mode_is_all_posts(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'mode' => 'test',
			'paywall_mode' => 'all-posts',
			'paywall_category_term_id' => 1,
		);
		$page = new SettingsPage( new SettingsRepository() );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/<fieldset\b[^>]*\bid="sx402-category-wrap"[^>]*\bdisabled\b/',
			$html
		);
	}

	public function test_render_has_both_mode_radios_with_stored_mode_checked(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'mode' => 'live',
			'live' => array( 'wallet_address' => '0xLIVE', 'default_price' => '0.01' ),
		);
		$page = new SettingsPage( new SettingsRepository() );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/name="[^"]*\[mode\]"[^>]*value="test"/', $html );
		$this->assertMatchesRegularExpression( '/name="[^"]*\[mode\]"[^>]*value="live"[^>]*checked/', $html );
	}

public function test_render_emits_per_mode_payment_input_names(): void {
		$page = new SettingsPage( new SettingsRepository() );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		foreach ( array(
			'[test][wallet_address]',
			'[test][default_price]',
			'[live][wallet_address]',
			'[live][default_price]',
			'[live][facilitator_url]',
			'[live][facilitator_api_key]',
		) as $needle ) {
			$this->assertStringContainsString( $needle, $html, "Form should have input named $needle" );
		}
	}

	public function test_render_renders_stored_per_mode_wallet_values(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'mode' => 'test',
			'test' => array( 'wallet_address' => '0xTESTWALLET', 'default_price' => '0.0001' ),
			'live' => array( 'wallet_address' => '0xLIVEWALLET', 'default_price' => '0.05' ),
		);
		$page = new SettingsPage( new SettingsRepository() );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="0xTESTWALLET"', $html );
		$this->assertStringContainsString( 'value="0xLIVEWALLET"', $html );
		$this->assertStringContainsString( 'value="0.0001"', $html );
		$this->assertStringContainsString( 'value="0.05"', $html );
	}
}
