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
	}

	public function test_returns_null_when_no_post_id(): void {
		$rule = new DefaultPaywallRule( new SettingsRepository() );
		$this->assertNull( $rule( null, array( 'post_id' => 0 ) ) );
	}

	public function test_returns_rule_when_post_has_paywall_tag(): void {
		$GLOBALS['__sx402_terms'] = array( array( 'paywall', 'post_tag', 42 ) );
		$rule                     = new DefaultPaywallRule( new SettingsRepository() );
		$this->assertSame(
			array( 'price' => '0.01', 'ttl' => 86400 ),
			$rule( null, array( 'post_id' => 42 ) )
		);
	}

	public function test_returns_rule_when_post_has_paywall_category(): void {
		$GLOBALS['__sx402_terms'] = array( array( 'paywall', 'category', 7 ) );
		$rule                     = new DefaultPaywallRule( new SettingsRepository() );
		$this->assertNotNull( $rule( null, array( 'post_id' => 7 ) ) );
	}

	public function test_preserves_rule_from_higher_priority_filter(): void {
		$GLOBALS['__sx402_terms'] = array( array( 'paywall', 'post_tag', 42 ) );
		$rule                     = new DefaultPaywallRule( new SettingsRepository() );
		$preset                   = array( 'price' => '9.99', 'ttl' => 10 );
		$this->assertSame( $preset, $rule( $preset, array( 'post_id' => 42 ) ) );
	}
}
