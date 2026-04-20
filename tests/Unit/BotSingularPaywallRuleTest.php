<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Services\BotDetector;
use SimpleX402\Services\BotSingularPaywallRule;
use SimpleX402\Settings\SettingsRepository;

final class BotSingularPaywallRuleTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_options'] = array();
	}

	public function test_returns_rule_when_singular_and_bot(): void {
		$rule = new BotSingularPaywallRule(
			new SettingsRepository(),
			new BotDetector( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' )
		);
		$this->assertSame(
			array( 'price' => '0.01', 'ttl' => 86400 ),
			$rule( null, array( 'singular' => true ) )
		);
	}

	public function test_returns_null_when_not_singular(): void {
		$rule = new BotSingularPaywallRule(
			new SettingsRepository(),
			new BotDetector( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' )
		);
		$this->assertNull( $rule( null, array( 'singular' => false ) ) );
	}

	public function test_returns_null_when_human_browser(): void {
		$rule = new BotSingularPaywallRule(
			new SettingsRepository(),
			new BotDetector(
				'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
			)
		);
		$this->assertNull( $rule( null, array( 'singular' => true ) ) );
	}

	public function test_preserves_prior_rule(): void {
		$rule = new BotSingularPaywallRule(
			new SettingsRepository(),
			new BotDetector( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' )
		);
		$prior = array( 'price' => '9.99', 'ttl' => 10 );
		$this->assertSame( $prior, $rule( $prior, array( 'singular' => true ) ) );
	}
}
