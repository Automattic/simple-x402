<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Services\FacilitatorProfile;
use SimpleX402\Settings\SettingsRepository;

final class SettingsRepositoryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_options']        = array();
		$GLOBALS['__sx402_existing_terms'] = array();
		$GLOBALS['__sx402_filters']        = array();
	}

	public function test_defaults_when_nothing_stored(): void {
		$repo = new SettingsRepository();
		$this->assertSame( 'test', $repo->mode() );
		$this->assertSame( '', $repo->wallet_address() );
		$this->assertSame( '0.01', $repo->default_price() );
		$this->assertSame( 0, $repo->paywall_category_term_id() );
	}

	public function test_save_then_read_test_block(): void {
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 7, 'name' => 'Premium', 'taxonomy' => 'category' ),
		);
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'mode' => 'test',
				'test' => array(
					'wallet_address' => '0xabc',
					'default_price'  => '0.25',
				),
				'paywall_category_term_id' => 7,
			)
		);
		$this->assertSame( 'test', $repo->mode() );
		$this->assertSame( '0xabc', $repo->wallet_address() );
		$this->assertSame( '0.25', $repo->default_price() );
		$this->assertSame( 7, $repo->paywall_category_term_id() );
	}

	public function test_switching_mode_surfaces_that_modes_wallet(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'mode' => 'test',
				'test' => array( 'wallet_address' => '0xTEST', 'default_price' => '0.0001' ),
				'live' => array( 'wallet_address' => '0xLIVE', 'default_price' => '0.01' ),
			)
		);
		$this->assertSame( '0xTEST', $repo->wallet_address() );
		$this->assertSame( '0.0001', $repo->default_price() );

		$repo->save(
			array(
				'mode' => 'live',
				'test' => array( 'wallet_address' => '0xTEST', 'default_price' => '0.0001' ),
				'live' => array( 'wallet_address' => '0xLIVE', 'default_price' => '0.01' ),
			)
		);
		$this->assertSame( '0xLIVE', $repo->wallet_address() );
		$this->assertSame( '0.01', $repo->default_price() );
	}

	public function test_save_rejects_negative_price_per_mode(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'mode' => 'test',
				'test' => array( 'wallet_address' => '0xabc', 'default_price' => '-1' ),
			)
		);
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
				'mode' => 'test',
				'paywall_mode' => 'all-posts',
			)
		);
		$this->assertSame( 'all-posts', $repo->paywall_mode() );
	}

	public function test_paywall_audience_defaults_to_none(): void {
		$repo = new SettingsRepository();
		$this->assertSame( 'none', $repo->paywall_audience() );
	}

	public function test_paywall_audience_reads_each_valid_value(): void {
		$repo = new SettingsRepository();
		foreach ( array( 'everyone', 'bots', 'none' ) as $value ) {
			$repo->save( array( 'mode' => 'test', 'paywall_audience' => $value ) );
			$this->assertSame( $value, $repo->paywall_audience() );
		}
	}

	public function test_paywall_audience_falls_back_on_invalid_value(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'mode' => 'test', 'paywall_audience' => 'nonsense' ) );
		$this->assertSame( 'none', $repo->paywall_audience() );
	}

	public function test_paywall_mode_falls_back_on_invalid_value(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'mode' => 'test', 'paywall_mode' => 'nonsense' ) );
		$this->assertSame( 'category', $repo->paywall_mode() );
	}

	public function test_sanitize_keeps_valid_term_id(): void {
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 42, 'name' => 'Premium', 'taxonomy' => 'category' ),
		);
		$repo = new SettingsRepository();
		$repo->save( array( 'mode' => 'test', 'paywall_category_term_id' => 42 ) );
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
		$repo->save( array( 'mode' => 'test', 'paywall_category_term_id' => 9999 ) );
		$this->assertSame( 7, $repo->paywall_category_term_id() );
	}

	public function test_sanitize_preserves_stored_term_id_when_key_absent(): void {
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 7, 'name' => 'Premium', 'taxonomy' => 'category' ),
		);
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'paywall_category_term_id' => 7,
		);
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'mode'         => 'test',
				'paywall_mode' => 'all-posts',
			)
		);
		$this->assertSame( 7, $repo->paywall_category_term_id() );
	}

	public function test_set_paywall_category_term_id_preserves_other_fields(): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'mode' => 'test',
			'test' => array( 'wallet_address' => '0xabc', 'default_price' => '0.25' ),
			'paywall_mode' => 'all-posts',
			'paywall_category_term_id' => 11,
		);
		$repo = new SettingsRepository();
		$repo->set_paywall_category_term_id( 22 );
		$this->assertSame( 22, $GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ]['paywall_category_term_id'] );
		$this->assertSame( '0xabc', $GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ]['test']['wallet_address'] );
	}

	public function test_mode_filter_overrides_stored_mode(): void {
		// Filter is typically registered at plugin load, before the first read.
		// Register before constructing the repo so the initial mode() resolution
		// sees it (the resolved mode is memoized per instance).
		add_filter( SettingsRepository::MODE_OVERRIDE_HOOK, fn() => 'test' );

		$repo = new SettingsRepository();
		$repo->save(
			array(
				'mode' => 'live',
				'live' => array( 'wallet_address' => '0xLIVE', 'default_price' => '0.01' ),
				'test' => array( 'wallet_address' => '0xTEST', 'default_price' => '0.0001' ),
			)
		);
		$this->assertSame( 'test', $repo->mode() );
		$this->assertSame( '0xTEST', $repo->wallet_address() );
	}

	public function test_mode_filter_ignored_when_returning_invalid_value(): void {
		add_filter( SettingsRepository::MODE_OVERRIDE_HOOK, fn() => 'garbage' );

		$repo = new SettingsRepository();
		$repo->save( array( 'mode' => 'live' ) );

		$this->assertSame( 'live', $repo->mode() );
	}

	public function test_facilitator_profile_reflects_live_overrides(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'mode' => 'live',
				'live' => array(
					'wallet_address'      => '0xabc',
					'default_price'       => '0.01',
					'facilitator_url'     => 'https://facil.example/',
					'facilitator_api_key' => 'k1',
				),
			)
		);
		$profile = $repo->facilitator_profile();
		$this->assertSame( FacilitatorProfile::MODE_LIVE, $profile->mode );
		$this->assertSame( 'https://facil.example/', $profile->facilitator_url );
		$this->assertSame( 'k1', $profile->api_key );
	}

	public function test_facilitator_profile_in_test_mode_has_no_api_key(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'mode' => 'test',
				'live' => array( 'facilitator_api_key' => 'should-not-leak' ),
			)
		);
		$profile = $repo->facilitator_profile();
		$this->assertSame( FacilitatorProfile::MODE_TEST, $profile->mode );
		$this->assertSame( '', $profile->api_key );
	}

	public function test_sanitize_strips_non_http_facilitator_url(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'mode' => 'live',
				'live' => array(
					'facilitator_url' => 'javascript:alert(1)',
				),
			)
		);
		$this->assertSame( '', $repo->live_facilitator_url() );
	}
}
