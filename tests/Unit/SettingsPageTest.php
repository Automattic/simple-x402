<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Admin\SettingsPage;
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
		$this->assertSame( SettingsRepository::MODE_CATEGORY, $localized['modeCategory'] );
	}

	public function test_enqueue_assets_skips_other_admin_pages(): void {
		$page = new SettingsPage( new SettingsRepository() );
		$page->enqueue_assets( 'dashboard' );
		$this->assertSame( array(), $GLOBALS['__sx402_enqueued_scripts'] );
	}

	public function test_sanitize_callback_returns_clean_array_without_persisting(): void {
		$page = new SettingsPage( new SettingsRepository() );
		$page->register_settings();

		$args     = $GLOBALS['__sx402_registered_settings'][ SettingsPage::GROUP ][ SettingsRepository::OPTION_NAME ];
		$callback = $args['sanitize_callback'];

		$result = $callback(
			array(
				'wallet_address'           => '0xABC',
				'default_price'            => '0.5',
				'paywall_category_term_id' => 2,
			)
		);

		$this->assertSame(
			array(
				'wallet_address'           => '0xABC',
				'default_price'            => '0.5',
				'paywall_mode'             => 'category',
				'paywall_audience'         => 'none',
				'paywall_category_term_id' => 2,
			),
			$result
		);
		// Regression: the callback must be pure. WP persists the returned value;
		// calling update_option from inside the callback recurses infinitely.
		$this->assertArrayNotHasKey( SettingsRepository::OPTION_NAME, $GLOBALS['__sx402_options'] );
	}

	public function test_sanitize_callback_falls_back_to_default_for_bad_price(): void {
		$page = new SettingsPage( new SettingsRepository() );
		$page->register_settings();

		$callback = $GLOBALS['__sx402_registered_settings'][ SettingsPage::GROUP ][ SettingsRepository::OPTION_NAME ]['sanitize_callback'];

		$result = $callback(
			array(
				'wallet_address' => '0xABC',
				'default_price'  => 'nope',
			)
		);

		$this->assertSame( '0.01', $result['default_price'] );
	}

	public function test_render_shows_both_mode_options_with_stored_selected(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'wallet_address'           => '0xabc',
			'default_price'            => '0.01',
			'paywall_mode'             => 'all-posts',
			'paywall_category_term_id' => 1,
		);
		$page = new SettingsPage( new SettingsRepository() );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="category"', $html );
		$this->assertStringContainsString( 'value="all-posts"', $html );
		$this->assertMatchesRegularExpression(
			'/value="all-posts"[^>]*checked/',
			$html
		);
		$this->assertDoesNotMatchRegularExpression(
			'/value="category"[^>]*checked/',
			$html
		);
	}

	public function test_render_shows_category_dropdown_with_stored_term_selected(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'wallet_address'           => '0xabc',
			'default_price'            => '0.01',
			'paywall_mode'             => 'category',
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

	public function test_render_groups_fields_under_three_h2_sections(): void {
		$page = new SettingsPage( new SettingsRepository() );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/<h2[^>]*>\s*What to paywall\s*<\/h2>/', $html );
		$this->assertMatchesRegularExpression( '/<h2[^>]*>\s*Who to paywall\s*<\/h2>/', $html );
		$this->assertMatchesRegularExpression( '/<h2[^>]*>\s*Where to send the funds\s*<\/h2>/', $html );
	}

	public function test_render_shows_three_audience_options_with_default_checked(): void {
		$page = new SettingsPage( new SettingsRepository() );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'value="everyone"', $html );
		$this->assertStringContainsString( 'value="bots"', $html );
		$this->assertStringContainsString( 'value="none"', $html );
		$this->assertMatchesRegularExpression(
			'/value="none"[^>]*checked/',
			$html
		);
	}

	public function test_render_checks_stored_audience(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'wallet_address'           => '0xabc',
			'default_price'            => '0.01',
			'paywall_mode'             => 'category',
			'paywall_audience'         => 'bots',
			'paywall_category_term_id' => 1,
		);
		$page = new SettingsPage( new SettingsRepository() );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/value="bots"[^>]*checked/',
			$html
		);
		$this->assertDoesNotMatchRegularExpression(
			'/value="none"[^>]*checked/',
			$html
		);
	}

	public function test_render_sections_ordered_what_who_where(): void {
		$page = new SettingsPage( new SettingsRepository() );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$what_pos  = strpos( $html, 'What to paywall' );
		$who_pos   = strpos( $html, 'Who to paywall' );
		$where_pos = strpos( $html, 'Where to send the funds' );

		$this->assertNotFalse( $what_pos );
		$this->assertNotFalse( $who_pos );
		$this->assertNotFalse( $where_pos );
		$this->assertLessThan( $who_pos, $what_pos );
		$this->assertLessThan( $where_pos, $who_pos );
	}

	public function test_render_disables_category_dropdown_when_mode_is_all_posts(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'wallet_address'           => '0xabc',
			'default_price'            => '0.01',
			'paywall_mode'             => 'all-posts',
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

	public function test_render_does_not_disable_category_dropdown_in_category_mode(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'wallet_address'           => '0xabc',
			'default_price'            => '0.01',
			'paywall_mode'             => 'category',
			'paywall_category_term_id' => 1,
		);
		$page = new SettingsPage( new SettingsRepository() );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertDoesNotMatchRegularExpression(
			'/<fieldset\b[^>]*\bid="sx402-category-wrap"[^>]*\bdisabled\b/',
			$html
		);
	}

	public function test_render_nests_category_dropdown_inside_mode_fieldset(): void {
		$page = new SettingsPage( new SettingsRepository() );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression(
			'/<fieldset[^>]*>[\s\S]*name="[^"]*\[paywall_category_term_id\]"[\s\S]*<\/fieldset>/',
			$html
		);
	}

	public function test_render_places_all_posts_radio_before_category_radio(): void {
		$page = new SettingsPage( new SettingsRepository() );

		ob_start();
		$page->render();
		$html = (string) ob_get_clean();

		$all_posts_pos = strpos( $html, 'value="all-posts"' );
		$category_pos  = strpos( $html, 'value="category"' );

		$this->assertNotFalse( $all_posts_pos );
		$this->assertNotFalse( $category_pos );
		$this->assertLessThan( $category_pos, $all_posts_pos );
	}
}
