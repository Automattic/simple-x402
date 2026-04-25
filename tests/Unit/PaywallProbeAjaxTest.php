<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Admin\PaywallProbeAjax;
use SimpleX402\Http\PaywallController;
use SimpleX402\Settings\SettingsRepository;

final class PaywallProbeAjaxTest extends TestCase {

	protected function tearDown(): void {
		unset( $_POST['action'], $_POST['nonce'] );
		parent::tearDown();
	}

	protected function setUp(): void {
		$GLOBALS['__sx402_options']           = array();
		$GLOBALS['__sx402_json_success']      = null;
		$GLOBALS['__sx402_get_posts_return']  = array( 11 );
		$GLOBALS['__sx402_current_user_id']   = 1;
		$GLOBALS['__sx402_current_user_caps'] = array( 'manage_options' );
	}

	public function test_returns_probe_descriptor_from_stored_option(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_mode'             => SettingsRepository::PAYWALL_MODE_CATEGORY,
			'paywall_category_term_id' => 2,
			'default_price'            => '0.01',
		);

		$_POST['action'] = PaywallProbeAjax::ACTION;
		$_POST['nonce']  = 'x';

		( new PaywallProbeAjax( new SettingsRepository() ) )->handle();

		$data = $GLOBALS['__sx402_json_success'];
		$this->assertSame( 'https://example.test/p/11/', $data['probe']['url'] );
		$this->assertSame(
			wp_create_nonce( PaywallController::PROBE_NONCE_ACTION ),
			$data['probe']['nonce']
		);
	}
}
