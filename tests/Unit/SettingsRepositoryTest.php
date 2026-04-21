<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Settings\SettingsRepository;

final class SettingsRepositoryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_options'] = array();
	}

	public function test_defaults_when_nothing_stored(): void {
		$repo = new SettingsRepository();
		$this->assertSame( '', $repo->wallet_address() );
		$this->assertSame( '0.01', $repo->default_price() );
	}

	public function test_save_then_read(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'wallet_address' => '0xabc', 'default_price' => '0.25' ) );
		$this->assertSame( '0xabc', $repo->wallet_address() );
		$this->assertSame( '0.25', $repo->default_price() );
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

	public function test_paywall_category_defaults_to_default_constant(): void {
		$repo = new SettingsRepository();
		$this->assertSame( SettingsRepository::DEFAULT_CATEGORY, $repo->paywall_category() );
	}

	public function test_paywall_category_read_back(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'wallet_address'   => '0xabc',
				'default_price'    => '0.01',
				'paywall_category' => 'Premium',
			)
		);
		$this->assertSame( 'Premium', $repo->paywall_category() );
	}

	public function test_paywall_category_falls_back_on_empty(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'wallet_address'   => '0xabc',
				'default_price'    => '0.01',
				'paywall_category' => '   ',
			)
		);
		$this->assertSame( SettingsRepository::DEFAULT_CATEGORY, $repo->paywall_category() );
	}

	public function test_sanitize_preserves_stored_category_when_key_absent(): void {
		// Absent-key = "preserve stored". Present-but-empty = "apply default".
		// Keeping these paths distinct lets the UI disable the input without
		// silently resetting the stored category on every save.
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'wallet_address'   => '0xabc',
				'default_price'    => '0.01',
				'paywall_mode'     => 'category',
				'paywall_category' => 'Premium',
			)
		);
		$this->assertSame( 'Premium', $repo->paywall_category() );

		// Second save omits paywall_category (disabled input doesn't post).
		$repo->save(
			array(
				'wallet_address' => '0xabc',
				'default_price'  => '0.01',
				'paywall_mode'   => 'all-posts',
			)
		);
		$this->assertSame( 'Premium', $repo->paywall_category() );
	}

	public function test_set_paywall_category_preserves_other_fields(): void {
		// Narrow mutator must not go through sanitize() — it would clobber
		// wallet_address, default_price, and paywall_mode on any partial write.
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array(
			'wallet_address'   => '0xabc',
			'default_price'    => '0.25',
			'paywall_mode'     => 'all-posts',
			'paywall_category' => 'News',
		);
		$repo = new SettingsRepository();
		$repo->set_paywall_category( 'Other' );
		$this->assertSame(
			array(
				'wallet_address'   => '0xabc',
				'default_price'    => '0.25',
				'paywall_mode'     => 'all-posts',
				'paywall_category' => 'Other',
			),
			$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ]
		);
	}

	public function test_set_paywall_category_normalises_empty_to_default(): void {
		$repo = new SettingsRepository();
		$repo->set_paywall_category( '   ' );
		$this->assertSame(
			SettingsRepository::DEFAULT_CATEGORY,
			$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ]['paywall_category']
		);
	}

	public function test_sanitize_applies_default_when_key_present_but_empty(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'wallet_address'   => '0xabc',
				'default_price'    => '0.01',
				'paywall_mode'     => 'category',
				'paywall_category' => 'Premium',
			)
		);

		$repo->save(
			array(
				'wallet_address'   => '0xabc',
				'default_price'    => '0.01',
				'paywall_mode'     => 'category',
				'paywall_category' => '',
			)
		);
		$this->assertSame( SettingsRepository::DEFAULT_CATEGORY, $repo->paywall_category() );
	}
}
