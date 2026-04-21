<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Settings\SettingsRepository;

final class SettingsRepositoryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_options']        = array();
		$GLOBALS['__sx402_existing_terms'] = array();
	}

	public function test_defaults_when_nothing_stored(): void {
		$repo = new SettingsRepository();
		$this->assertSame( '', $repo->wallet_address() );
		$this->assertSame( '0.01', $repo->default_price() );
		$this->assertSame( 0, $repo->paywall_category_term_id() );
	}

	public function test_save_then_read(): void {
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 7, 'name' => 'Premium', 'taxonomy' => 'category' ),
		);
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'wallet_address'           => '0xabc',
				'default_price'            => '0.25',
				'paywall_category_term_id' => 7,
			)
		);
		$this->assertSame( '0xabc', $repo->wallet_address() );
		$this->assertSame( '0.25', $repo->default_price() );
		$this->assertSame( 7, $repo->paywall_category_term_id() );
	}

	public function test_save_rejects_negative_price(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'wallet_address' => '0xabc', 'default_price' => '-1' ) );
		$this->assertSame( '0.01', $repo->default_price() );
	}

	public function test_paywall_mode_defaults_to_category(): void {
		$repo = new SettingsRepository();
		$this->assertSame( 'category', $repo->paywall_mode() );
	}

	public function test_paywall_mode_reads_stored_all_posts(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'wallet_address' => '0xabc',
				'default_price'  => '0.01',
				'paywall_mode'   => 'all-posts',
			)
		);
		$this->assertSame( 'all-posts', $repo->paywall_mode() );
	}

	public function test_paywall_mode_falls_back_on_invalid_value(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'wallet_address' => '0xabc',
				'default_price'  => '0.01',
				'paywall_mode'   => 'nonsense',
			)
		);
		$this->assertSame( 'category', $repo->paywall_mode() );
	}

	public function test_sanitize_keeps_valid_term_id(): void {
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 42, 'name' => 'Premium', 'taxonomy' => 'category' ),
		);
		$repo = new SettingsRepository();
		$repo->save( array( 'paywall_category_term_id' => 42 ) );
		$this->assertSame( 42, $repo->paywall_category_term_id() );
	}

	public function test_sanitize_falls_back_when_term_id_points_at_nothing(): void {
		// Admin had term_id=7 stored. Input arrives referencing id 9999 (stale
		// dropdown, tampered POST). Sanitize must preserve the stored id, not
		// drop to 0.
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 7, 'name' => 'Premium', 'taxonomy' => 'category' ),
		);
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_category_term_id' => 7,
		);
		$repo = new SettingsRepository();
		$repo->save( array( 'paywall_category_term_id' => 9999 ) );
		$this->assertSame( 7, $repo->paywall_category_term_id() );
	}

	public function test_sanitize_preserves_stored_term_id_when_key_absent(): void {
		// The JS disables the dropdown when mode=all-posts; disabled controls
		// aren't submitted. Sanitize must treat the absent key as "leave alone".
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 7, 'name' => 'Premium', 'taxonomy' => 'category' ),
		);
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_category_term_id' => 7,
		);
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'wallet_address' => '0xabc',
				'default_price'  => '0.01',
				'paywall_mode'   => 'all-posts',
			)
		);
		$this->assertSame( 7, $repo->paywall_category_term_id() );
	}

	public function test_set_paywall_category_term_id_preserves_other_fields(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'wallet_address'           => '0xabc',
			'default_price'            => '0.25',
			'paywall_mode'             => 'all-posts',
			'paywall_category_term_id' => 11,
		);
		$repo = new SettingsRepository();
		$repo->set_paywall_category_term_id( 22 );
		$this->assertSame(
			array(
				'wallet_address'           => '0xabc',
				'default_price'            => '0.25',
				'paywall_mode'             => 'all-posts',
				'paywall_category_term_id' => 22,
			),
			$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ]
		);
	}
}
