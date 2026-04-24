<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Admin\SettingsAjax;
use SimpleX402\Http\PaywallController;
use SimpleX402\Settings\SettingsRepository;

final class SettingsAjaxTest extends TestCase {

	protected function tearDown(): void {
		unset( $_POST['action'], $_POST['nonce'], $_POST['fields'] );
		parent::tearDown();
	}

	protected function setUp(): void {
		$GLOBALS['__sx402_options']           = array();
		$GLOBALS['__sx402_json_success']      = null;
		$GLOBALS['__sx402_get_posts_return'] = null;
		$GLOBALS['__sx402_current_user_id']  = 1;
		$GLOBALS['__sx402_current_user_caps'] = array( 'manage_options' );
		$GLOBALS['__sx402_existing_terms']   = array(
			array( 'term_id' => 3, 'name' => 'Wall', 'taxonomy' => 'category' ),
		);
	}

	public function test_paywall_scope_save_includes_probe_when_sample_post_exists(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_mode'             => SettingsRepository::PAYWALL_MODE_NONE,
			'paywall_category_term_id' => 3,
			'default_price'            => '0.01',
		);
		$GLOBALS['__sx402_get_posts_return'] = array( 9 );

		$_POST['action']  = SettingsAjax::ACTION;
		$_POST['nonce']  = 'x';
		$_POST['fields'] = wp_json_encode(
			array(
				'paywall_mode'             => SettingsRepository::PAYWALL_MODE_CATEGORY,
				'paywall_category_term_id' => 3,
			)
		);

		( new SettingsAjax( new SettingsRepository() ) )->handle();

		$data = $GLOBALS['__sx402_json_success'];
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'values', $data );
		$this->assertArrayHasKey( 'probe', $data );
		$this->assertIsArray( $data['probe'] );
		$this->assertSame( 'https://example.test/p/9/', $data['probe']['url'] );
		$this->assertSame(
			wp_create_nonce( PaywallController::PROBE_NONCE_ACTION ),
			$data['probe']['nonce']
		);
	}

	public function test_scope_save_without_matching_post_returns_probe_reason(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_mode'             => SettingsRepository::PAYWALL_MODE_NONE,
			'paywall_category_term_id' => 3,
			'default_price'            => '0.01',
		);
		$GLOBALS['__sx402_get_posts_return'] = array();

		$_POST['action']  = SettingsAjax::ACTION;
		$_POST['nonce']  = 'x';
		$_POST['fields'] = wp_json_encode(
			array(
				'paywall_mode'             => SettingsRepository::PAYWALL_MODE_CATEGORY,
				'paywall_category_term_id' => 3,
			)
		);

		( new SettingsAjax( new SettingsRepository() ) )->handle();

		$data = $GLOBALS['__sx402_json_success'];
		$this->assertSame( 'no_matching_post', $data['probe']['reason'] ?? '' );
	}

	public function test_non_scope_save_omits_probe_key(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_mode'             => SettingsRepository::PAYWALL_MODE_CATEGORY,
			'paywall_category_term_id' => 3,
			'default_price'            => '0.01',
		);

		$_POST['action']  = SettingsAjax::ACTION;
		$_POST['nonce']  = 'x';
		$_POST['fields'] = wp_json_encode( array( 'default_price' => '0.02' ) );

		( new SettingsAjax( new SettingsRepository() ) )->handle();

		$data = $GLOBALS['__sx402_json_success'];
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'values', $data );
		$this->assertArrayNotHasKey( 'probe', $data );
	}
}
