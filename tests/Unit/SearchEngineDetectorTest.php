<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Services\SearchEngineDetector;

final class SearchEngineDetectorTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_filters'] = array();
	}

	public function test_googlebot_is_search_engine(): void {
		$d = new SearchEngineDetector( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' );
		$this->assertTrue( $d->is_search_engine() );
	}

	public function test_bingbot_is_search_engine(): void {
		$d = new SearchEngineDetector( 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)' );
		$this->assertTrue( $d->is_search_engine() );
	}

	public function test_gptbot_is_not_search_engine(): void {
		// The whole point of the allowlist: AI crawlers must NOT be treated
		// as search engines, otherwise audience=bots can't gate them.
		$d = new SearchEngineDetector( 'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)' );
		$this->assertFalse( $d->is_search_engine() );
	}

	public function test_claudebot_is_not_search_engine(): void {
		$d = new SearchEngineDetector( 'Mozilla/5.0 (compatible; ClaudeBot/1.0; +claudebot@anthropic.com)' );
		$this->assertFalse( $d->is_search_engine() );
	}

	public function test_human_browser_is_not_search_engine(): void {
		$d = new SearchEngineDetector(
			'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
		);
		$this->assertFalse( $d->is_search_engine() );
	}

	public function test_empty_user_agent_is_not_search_engine(): void {
		$d = new SearchEngineDetector( '' );
		$this->assertFalse( $d->is_search_engine() );
	}

	public function test_filter_can_add_custom_search_engine(): void {
		add_filter(
			SearchEngineDetector::FILTER_ALLOWLIST,
			static function ( array $names ): array {
				$names[] = 'Slurp';
				return $names;
			}
		);
		$d = new SearchEngineDetector( 'Mozilla/5.0 (compatible; Yahoo! Slurp; http://help.yahoo.com/help/us/ysearch/slurp)' );
		$this->assertTrue( $d->is_search_engine() );
	}

	public function test_filter_can_remove_default_search_engine(): void {
		add_filter(
			SearchEngineDetector::FILTER_ALLOWLIST,
			static fn ( array $names ): array => array_values( array_diff( $names, array( 'Googlebot' ) ) )
		);
		$d = new SearchEngineDetector( 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' );
		$this->assertFalse( $d->is_search_engine() );
	}
}
