<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Services\FacilitatorProfile;
use SimpleX402\Services\SettingsChangeNotifier;
use SimpleX402\Settings\SettingsRepository;

final class SettingsRepositoryTest extends TestCase {

	private const VALID_LIVE_WALLET = '0x1111111111111111111111111111111111111111';

	protected function setUp(): void {
		$GLOBALS['__sx402_options']         = array();
		$GLOBALS['__sx402_existing_terms']  = array();
		$GLOBALS['__sx402_filters']         = array();
		$GLOBALS['__sx402_settings_errors'] = array();
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
		$live = array(
			'wallet_address'      => '0x1111111111111111111111111111111111111111',
			'default_price'       => '0.01',
			'facilitator_api_key' => 'k1',
		);
		$repo->save(
			array(
				'mode' => 'test',
				'test' => array( 'wallet_address' => '0xTEST', 'default_price' => '0.0001' ),
				'live' => $live,
			)
		);
		$this->assertSame( '0xTEST', $repo->wallet_address() );
		$this->assertSame( '0.0001', $repo->default_price() );

		$repo->save(
			array(
				'mode' => 'live',
				'test' => array( 'wallet_address' => '0xTEST', 'default_price' => '0.0001' ),
				'live' => $live,
			)
		);
		$this->assertSame( $live['wallet_address'], $repo->wallet_address() );
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

	public function test_paywall_mode_defaults_to_none(): void {
		$repo = new SettingsRepository();
		$this->assertSame( 'none', $repo->paywall_mode() );
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

	public function test_paywall_audience_defaults_to_bots(): void {
		$repo = new SettingsRepository();
		$this->assertSame( 'bots', $repo->paywall_audience() );
	}

	public function test_paywall_audience_reads_each_valid_value(): void {
		$repo = new SettingsRepository();
		foreach ( array( 'everyone', 'bots' ) as $value ) {
			$repo->save( array( 'mode' => 'test', 'paywall_audience' => $value ) );
			$this->assertSame( $value, $repo->paywall_audience() );
		}
	}

	public function test_paywall_audience_falls_back_on_invalid_value(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'mode' => 'test', 'paywall_audience' => 'nonsense' ) );
		$this->assertSame( 'bots', $repo->paywall_audience() );
	}

	public function test_paywall_mode_falls_back_on_invalid_value(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'mode' => 'test', 'paywall_mode' => 'nonsense' ) );
		$this->assertSame( 'none', $repo->paywall_mode() );
	}

	public function test_paywall_mode_reads_stored_none(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'mode' => 'test', 'paywall_mode' => 'none' ) );
		$this->assertSame( 'none', $repo->paywall_mode() );
	}

	public function test_selected_facilitator_id_defaults_to_empty(): void {
		$this->assertSame( '', ( new SettingsRepository() )->selected_facilitator_id() );
	}

	public function test_selected_facilitator_id_persists_through_sanitize(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'mode' => 'test', 'selected_facilitator_id' => 'simple_x402_test' ) );
		$this->assertSame( 'simple_x402_test', $repo->selected_facilitator_id() );
	}

	public function test_selected_facilitator_id_strips_invalid_characters(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'mode'                    => 'test',
				'selected_facilitator_id' => 'Simple/X402 Test!',
			)
		);
		// Uppercase → lowercase; slash/space/exclamation stripped entirely.
		$this->assertSame( 'simplex402test', $repo->selected_facilitator_id() );
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

	public function test_facilitator_profile_reflects_live_overrides(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'mode' => 'live',
				'live' => array(
					'wallet_address'      => '0x1111111111111111111111111111111111111111',
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

	public function test_sanitize_keeps_live_mode_when_live_block_is_complete(): void {
		$repo = new SettingsRepository( new SettingsChangeNotifier() );
		$repo->save(
			array(
				'mode' => 'live',
				'live' => array(
					'wallet_address'      => self::VALID_LIVE_WALLET,
					'default_price'       => '0.01',
					'facilitator_api_key' => 'k1',
				),
			)
		);
		$this->assertSame( 'live', $repo->mode() );
		$this->assertSame( array(), $GLOBALS['__sx402_settings_errors'] );
	}

	public function test_sanitize_reverts_live_mode_when_wallet_missing(): void {
		$repo = new SettingsRepository( new SettingsChangeNotifier() );
		$repo->save(
			array(
				'mode' => 'live',
				'live' => array(
					'wallet_address'      => '',
					'facilitator_api_key' => 'k1',
				),
			)
		);
		$this->assertSame( 'test', $repo->mode() );
		$this->assertNotEmpty( $GLOBALS['__sx402_settings_errors'] );
		$this->assertStringContainsString( 'receiving wallet address', $GLOBALS['__sx402_settings_errors'][0]['message'] );
	}

	public function test_sanitize_reverts_live_mode_when_wallet_malformed(): void {
		$repo = new SettingsRepository( new SettingsChangeNotifier() );
		$repo->save(
			array(
				'mode' => 'live',
				'live' => array(
					'wallet_address'      => '0xabc',
					'facilitator_api_key' => 'k1',
				),
			)
		);
		$this->assertSame( 'test', $repo->mode() );
		$this->assertNotEmpty( $GLOBALS['__sx402_settings_errors'] );
		$this->assertStringContainsString( '40 hex characters', $GLOBALS['__sx402_settings_errors'][0]['message'] );
	}

	public function test_sanitize_reverts_live_mode_when_api_key_missing(): void {
		$repo = new SettingsRepository( new SettingsChangeNotifier() );
		$repo->save(
			array(
				'mode' => 'live',
				'live' => array(
					'wallet_address'      => self::VALID_LIVE_WALLET,
					'facilitator_api_key' => '',
				),
			)
		);
		$this->assertSame( 'test', $repo->mode() );
		$this->assertNotEmpty( $GLOBALS['__sx402_settings_errors'] );
		$this->assertStringContainsString( 'facilitator API key', $GLOBALS['__sx402_settings_errors'][0]['message'] );
	}

	public function test_sanitize_emits_single_notice_listing_every_missing_requirement(): void {
		$repo = new SettingsRepository( new SettingsChangeNotifier() );
		$repo->save( array( 'mode' => 'live' ) );
		$this->assertCount( 1, $GLOBALS['__sx402_settings_errors'] );
		$message = $GLOBALS['__sx402_settings_errors'][0]['message'];
		$this->assertStringContainsString( 'receiving wallet address', $message );
		$this->assertStringContainsString( 'facilitator API key', $message );
	}

	public function test_sanitize_preserves_live_block_fields_even_when_mode_reverts(): void {
		$repo = new SettingsRepository( new SettingsChangeNotifier() );
		$repo->save(
			array(
				'mode' => 'live',
				'live' => array(
					'wallet_address'  => self::VALID_LIVE_WALLET,
					'facilitator_url' => 'https://facil.example/',
				),
			)
		);
		$this->assertSame( 'test', $repo->mode() );
		// Admin can fill in the missing api key later without re-typing the rest.
		$this->assertSame( 'https://facil.example/', $repo->live_facilitator_url() );
	}
}
