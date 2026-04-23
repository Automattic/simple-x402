<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Settings\SettingsRepository;

final class SettingsRepositoryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_options']         = array();
		$GLOBALS['__sx402_existing_terms']  = array();
		$GLOBALS['__sx402_filters']         = array();
		$GLOBALS['__sx402_settings_errors'] = array();
	}

	public function test_defaults_when_nothing_stored(): void {
		$repo = new SettingsRepository();
		$this->assertSame( '', $repo->wallet_address() );
		$this->assertSame( '0.01', $repo->default_price() );
		$this->assertSame( '', $repo->selected_facilitator_id() );
		$this->assertSame( 0, $repo->paywall_category_term_id() );
		$this->assertSame( SettingsRepository::DEFAULT_PAYWALL_MODE, $repo->paywall_mode() );
		$this->assertSame( SettingsRepository::DEFAULT_AUDIENCE, $repo->paywall_audience() );
	}

	public function test_save_then_read_round_trip(): void {
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 7, 'name' => 'Premium', 'taxonomy' => 'category' ),
		);
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'wallet_address'           => '0xabc',
				'default_price'            => '0.25',
				'selected_facilitator_id'  => 'simple_x402_test',
				'paywall_mode'             => SettingsRepository::PAYWALL_MODE_CATEGORY,
				'paywall_audience'         => SettingsRepository::AUDIENCE_EVERYONE,
				'paywall_category_term_id' => 7,
			)
		);
		$this->assertSame( '0xabc', $repo->wallet_address() );
		$this->assertSame( '0.25', $repo->default_price() );
		$this->assertSame( 'simple_x402_test', $repo->selected_facilitator_id() );
		$this->assertSame( 7, $repo->paywall_category_term_id() );
		$this->assertSame( SettingsRepository::PAYWALL_MODE_CATEGORY, $repo->paywall_mode() );
		$this->assertSame( SettingsRepository::AUDIENCE_EVERYONE, $repo->paywall_audience() );
	}

	public function test_sanitize_reverts_negative_or_non_numeric_price_to_default(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'default_price' => '-1' ) );
		$this->assertSame( '0.01', $repo->default_price() );

		$repo->save( array( 'default_price' => 'free' ) );
		$this->assertSame( '0.01', $repo->default_price() );
	}

	public function test_sanitize_strips_invalid_chars_from_facilitator_id(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'selected_facilitator_id' => 'Simple/X402 Test!' ) );
		$this->assertSame( 'simplex402test', $repo->selected_facilitator_id() );
	}

	public function test_sanitize_rejects_invalid_audience_to_default(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'paywall_audience' => 'nobody' ) );
		$this->assertSame( SettingsRepository::DEFAULT_AUDIENCE, $repo->paywall_audience() );
	}

	public function test_sanitize_rejects_invalid_paywall_mode_to_default(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'paywall_mode' => 'weird' ) );
		$this->assertSame( SettingsRepository::DEFAULT_PAYWALL_MODE, $repo->paywall_mode() );
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

	public function test_set_paywall_category_term_id_preserves_other_fields(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'wallet_address'          => '0xabc',
			'default_price'           => '0.50',
			'selected_facilitator_id' => 'simple_x402_test',
			'paywall_category_term_id' => 3,
		);
		$repo = new SettingsRepository();
		$repo->set_paywall_category_term_id( 99 );
		$this->assertSame( 99, $repo->paywall_category_term_id() );
		$this->assertSame( '0xabc', $repo->wallet_address() );
		$this->assertSame( '0.50', $repo->default_price() );
		$this->assertSame( 'simple_x402_test', $repo->selected_facilitator_id() );
	}
}
