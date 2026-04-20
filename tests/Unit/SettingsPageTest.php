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
	}

	public function test_sanitize_callback_returns_clean_array_without_persisting(): void {
		$page = new SettingsPage( new SettingsRepository() );
		$page->register_settings();

		$args     = $GLOBALS['__sx402_registered_settings'][ SettingsPage::GROUP ][ SettingsRepository::OPTION_NAME ];
		$callback = $args['sanitize_callback'];

		$result = $callback(
			array(
				'wallet_address' => '0xABC',
				'default_price'  => '0.5',
			)
		);

		$this->assertSame(
			array(
				'wallet_address' => '0xABC',
				'default_price'  => '0.5',
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
}
