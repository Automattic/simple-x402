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
		$this->assertSame( array(), $repo->facilitator_slots() );
	}

	public function test_wallet_address_resolves_to_the_active_facilitators_slot(): void {
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 7, 'name' => 'Premium', 'taxonomy' => 'category' ),
		);
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'selected_facilitator_id'  => 'simple_x402_test',
				'facilitators'             => array(
					'simple_x402_test' => array(
						'wallet_address' => '0xTest',
						'default_price'  => '0.25',
					),
					'coinbase_cdp'     => array(
						'wallet_address' => '0xLive',
						'default_price'  => '0.10',
					),
				),
				'paywall_category_term_id' => 7,
			)
		);
		$this->assertSame( '0xTest', $repo->wallet_address() );
		$this->assertSame( '0.25', $repo->default_price() );
	}

	public function test_switching_selected_facilitator_recalls_its_stored_slot(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'selected_facilitator_id' => 'simple_x402_test',
				'facilitators'            => array(
					'simple_x402_test' => array( 'wallet_address' => '0xTest', 'default_price' => '0.0001' ),
					'coinbase_cdp'     => array( 'wallet_address' => '0xLive', 'default_price' => '0.10' ),
				),
			)
		);
		$this->assertSame( '0xTest', $repo->wallet_address() );
		$this->assertSame( '0.0001', $repo->default_price() );

		$repo->save(
			array(
				'selected_facilitator_id' => 'coinbase_cdp',
				'facilitators'            => array(
					'simple_x402_test' => array( 'wallet_address' => '0xTest', 'default_price' => '0.0001' ),
					'coinbase_cdp'     => array( 'wallet_address' => '0xLive', 'default_price' => '0.10' ),
				),
			)
		);
		$this->assertSame( '0xLive', $repo->wallet_address() );
		$this->assertSame( '0.10', $repo->default_price() );
	}

	public function test_wallet_address_for_reads_arbitrary_slot_regardless_of_selection(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'selected_facilitator_id' => 'simple_x402_test',
				'facilitators'            => array(
					'simple_x402_test' => array( 'wallet_address' => '0xTest', 'default_price' => '0.01' ),
					'coinbase_cdp'     => array( 'wallet_address' => '0xLive', 'default_price' => '0.10' ),
				),
			)
		);
		$this->assertSame( '0xLive', $repo->wallet_address_for( 'coinbase_cdp' ) );
		$this->assertSame( '0.10', $repo->default_price_for( 'coinbase_cdp' ) );
	}

	public function test_sanitize_reverts_negative_or_non_numeric_price_to_default(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'selected_facilitator_id' => 'simple_x402_test',
				'facilitators'            => array(
					'simple_x402_test' => array( 'wallet_address' => '0x', 'default_price' => '-1' ),
				),
			)
		);
		$this->assertSame( '0.01', $repo->default_price() );

		$repo->save(
			array(
				'selected_facilitator_id' => 'simple_x402_test',
				'facilitators'            => array(
					'simple_x402_test' => array( 'wallet_address' => '0x', 'default_price' => 'free' ),
				),
			)
		);
		$this->assertSame( '0.01', $repo->default_price() );
	}

	public function test_sanitize_strips_invalid_chars_from_facilitator_id(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'selected_facilitator_id' => 'Simple/X402 Test!' ) );
		$this->assertSame( 'simplex402test', $repo->selected_facilitator_id() );
	}

	public function test_sanitize_drops_invalid_facilitator_keys_in_slots(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'facilitators' => array(
					'valid_id'        => array( 'wallet_address' => '0xA', 'default_price' => '0.01' ),
					'Bad ID!'         => array( 'wallet_address' => '0xB', 'default_price' => '0.01' ),
					'also/invalid'    => array( 'wallet_address' => '0xC', 'default_price' => '0.01' ),
				),
			)
		);
		$slots = $repo->facilitator_slots();
		$this->assertArrayHasKey( 'valid_id', $slots );
		$this->assertArrayHasKey( 'badid', $slots );       // "Bad ID!" → "badid"
		$this->assertArrayHasKey( 'alsoinvalid', $slots ); // "also/invalid" → "alsoinvalid"
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
			'selected_facilitator_id'  => 'simple_x402_test',
			'facilitators'             => array(
				'simple_x402_test' => array( 'wallet_address' => '0xabc', 'default_price' => '0.50' ),
			),
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
