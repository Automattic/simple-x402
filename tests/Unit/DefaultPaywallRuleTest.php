<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Services\DefaultPaywallRule;
use SimpleX402\Settings\SettingsRepository;

final class DefaultPaywallRuleTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_terms']   = array();
		$GLOBALS['__sx402_options'] = array();
		$GLOBALS['__sx402_posts']   = array();
	}

	private function set_options( array $overrides ): void {
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array_merge(
			array(
				'wallet_address'   => '',
				'default_price'    => '0.01',
				'paywall_mode'     => SettingsRepository::DEFAULT_MODE,
				'paywall_category' => SettingsRepository::DEFAULT_CATEGORY,
			),
			$overrides
		);
	}

	public function test_returns_null_when_no_post_id(): void {
		$rule = new DefaultPaywallRule( new SettingsRepository() );
		$this->assertNull( $rule( null, array( 'post_id' => 0 ) ) );
	}

	public function test_category_mode_gates_post_in_default_category(): void {
		$GLOBALS['__sx402_terms'] = array( array( 'paywall', 'category', 7 ) );
		$rule                     = new DefaultPaywallRule( new SettingsRepository() );
		$this->assertSame(
			array( 'price' => '0.01', 'ttl' => 86400 ),
			$rule( null, array( 'post_id' => 7 ) )
		);
	}

	public function test_category_mode_uses_configured_category_name(): void {
		$this->set_options( array( 'paywall_category' => 'Premium' ) );
		$GLOBALS['__sx402_terms'] = array( array( 'Premium', 'category', 7 ) );
		$rule                     = new DefaultPaywallRule( new SettingsRepository() );
		$this->assertNotNull( $rule( null, array( 'post_id' => 7 ) ) );
	}

	public function test_category_mode_ignores_post_tag(): void {
		// A post *tagged* paywall (not categorised) must not be gated anymore.
		$GLOBALS['__sx402_terms'] = array( array( 'paywall', 'post_tag', 7 ) );
		$rule                     = new DefaultPaywallRule( new SettingsRepository() );
		$this->assertNull( $rule( null, array( 'post_id' => 7 ) ) );
	}

	public function test_category_mode_ignores_non_matching_category(): void {
		$this->set_options( array( 'paywall_category' => 'Premium' ) );
		$GLOBALS['__sx402_terms'] = array( array( 'paywall', 'category', 7 ) );
		$rule                     = new DefaultPaywallRule( new SettingsRepository() );
		$this->assertNull( $rule( null, array( 'post_id' => 7 ) ) );
	}

	public function test_preserves_rule_from_higher_priority_filter(): void {
		$GLOBALS['__sx402_terms'] = array( array( 'paywall', 'category', 42 ) );
		$rule                     = new DefaultPaywallRule( new SettingsRepository() );
		$preset                   = array( 'price' => '9.99', 'ttl' => 10 );
		$this->assertSame( $preset, $rule( $preset, array( 'post_id' => 42 ) ) );
	}

	public function test_all_posts_mode_gates_published_post(): void {
		$this->set_options( array( 'paywall_mode' => SettingsRepository::MODE_ALL_POSTS ) );
		$GLOBALS['__sx402_posts'][ 99 ] = array(
			'post_type'   => 'post',
			'post_status' => 'publish',
		);
		$rule = new DefaultPaywallRule( new SettingsRepository() );
		$this->assertSame(
			array( 'price' => '0.01', 'ttl' => 86400 ),
			$rule( null, array( 'post_id' => 99 ) )
		);
	}

	public function test_all_posts_mode_ignores_pages(): void {
		$this->set_options( array( 'paywall_mode' => SettingsRepository::MODE_ALL_POSTS ) );
		$GLOBALS['__sx402_posts'][ 99 ] = array(
			'post_type'   => 'page',
			'post_status' => 'publish',
		);
		$rule = new DefaultPaywallRule( new SettingsRepository() );
		$this->assertNull( $rule( null, array( 'post_id' => 99 ) ) );
	}

	public function test_all_posts_mode_ignores_drafts(): void {
		$this->set_options( array( 'paywall_mode' => SettingsRepository::MODE_ALL_POSTS ) );
		$GLOBALS['__sx402_posts'][ 99 ] = array(
			'post_type'   => 'post',
			'post_status' => 'draft',
		);
		$rule = new DefaultPaywallRule( new SettingsRepository() );
		$this->assertNull( $rule( null, array( 'post_id' => 99 ) ) );
	}

	public function test_all_posts_mode_preserves_higher_priority_filter(): void {
		$this->set_options( array( 'paywall_mode' => SettingsRepository::MODE_ALL_POSTS ) );
		$rule   = new DefaultPaywallRule( new SettingsRepository() );
		$preset = array( 'price' => '9.99', 'ttl' => 10 );
		$this->assertSame( $preset, $rule( $preset, array( 'post_id' => 99 ) ) );
	}
}
