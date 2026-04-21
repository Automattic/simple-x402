<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Services\BotDetector;
use SimpleX402\Services\DefaultPaywallRule;
use SimpleX402\Services\SearchEngineDetector;
use SimpleX402\Settings\SettingsRepository;

final class DefaultPaywallRuleTest extends TestCase {

	private const DEFAULT_TERM_ID = 1;

	// Deliberately a non-search-engine crawler so audience=bots tests that
	// don't involve the allowlist are not accidentally passed through.
	private const BOT_UA           = 'Mozilla/5.0 (compatible; GPTBot/1.0; +https://openai.com/gptbot)';
	private const SEARCH_ENGINE_UA = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
	private const HUMAN_UA         = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

	protected function setUp(): void {
		$GLOBALS['__sx402_terms']   = array();
		$GLOBALS['__sx402_options'] = array();
		$GLOBALS['__sx402_posts']   = array();
		$GLOBALS['__sx402_filters'] = array();
	}

	private function set_options( array $overrides ): void {
		// Default the audience to EVERYONE so the mode-centric cases don't
		// accidentally get short-circuited by the audience gate. Cases that
		// care about audience set it explicitly via $overrides.
		$GLOBALS['__sx402_options'][ SettingsRepository::OPTION_NAME ] = array_merge(
			array(
				'wallet_address'           => '',
				'default_price'            => '0.01',
				'paywall_mode'             => SettingsRepository::DEFAULT_MODE,
				'paywall_audience'         => SettingsRepository::AUDIENCE_EVERYONE,
				'paywall_category_term_id' => self::DEFAULT_TERM_ID,
			),
			$overrides
		);
	}

	private function make_rule( string $user_agent = self::HUMAN_UA ): DefaultPaywallRule {
		return new DefaultPaywallRule(
			new SettingsRepository(),
			new BotDetector( $user_agent ),
			new SearchEngineDetector( $user_agent )
		);
	}

	public function test_returns_null_when_no_post_id(): void {
		$this->set_options( array() );
		$rule = $this->make_rule();
		$this->assertNull( $rule( null, array( 'post_id' => 0 ) ) );
	}

	public function test_category_mode_gates_post_in_bound_category(): void {
		$this->set_options( array() );
		$GLOBALS['__sx402_terms'] = array( array( self::DEFAULT_TERM_ID, 'category', 7 ) );
		$rule                     = $this->make_rule();
		$this->assertSame(
			array(
				'price' => '0.01',
				'ttl'   => 86400,
			),
			$rule( null, array( 'post_id' => 7 ) )
		);
	}

	public function test_category_mode_uses_configured_term_id(): void {
		$this->set_options( array( 'paywall_category_term_id' => 42 ) );
		$GLOBALS['__sx402_terms'] = array( array( 42, 'category', 7 ) );
		$rule                     = $this->make_rule();
		$this->assertNotNull( $rule( null, array( 'post_id' => 7 ) ) );
	}

	public function test_category_mode_ignores_post_tag(): void {
		// A post *tagged* with the default term_id (not categorised) must not be gated.
		$this->set_options( array() );
		$GLOBALS['__sx402_terms'] = array( array( self::DEFAULT_TERM_ID, 'post_tag', 7 ) );
		$rule                     = $this->make_rule();
		$this->assertNull( $rule( null, array( 'post_id' => 7 ) ) );
	}

	public function test_category_mode_ignores_non_matching_category(): void {
		$this->set_options( array( 'paywall_category_term_id' => 42 ) );
		$GLOBALS['__sx402_terms'] = array( array( self::DEFAULT_TERM_ID, 'category', 7 ) );
		$rule                     = $this->make_rule();
		$this->assertNull( $rule( null, array( 'post_id' => 7 ) ) );
	}

	public function test_preserves_rule_from_higher_priority_filter(): void {
		$this->set_options( array() );
		$GLOBALS['__sx402_terms'] = array( array( self::DEFAULT_TERM_ID, 'category', 42 ) );
		$rule                     = $this->make_rule();
		$preset                   = array(
			'price' => '9.99',
			'ttl'   => 10,
		);
		$this->assertSame( $preset, $rule( $preset, array( 'post_id' => 42 ) ) );
	}

	public function test_all_posts_mode_gates_published_post(): void {
		$this->set_options( array( 'paywall_mode' => SettingsRepository::MODE_ALL_POSTS ) );
		$GLOBALS['__sx402_posts'][99] = array(
			'post_type'   => 'post',
			'post_status' => 'publish',
		);
		$rule                         = $this->make_rule();
		$this->assertSame(
			array(
				'price' => '0.01',
				'ttl'   => 86400,
			),
			$rule( null, array( 'post_id' => 99 ) )
		);
	}

	public function test_all_posts_mode_ignores_pages(): void {
		$this->set_options( array( 'paywall_mode' => SettingsRepository::MODE_ALL_POSTS ) );
		$GLOBALS['__sx402_posts'][99] = array(
			'post_type'   => 'page',
			'post_status' => 'publish',
		);
		$rule                         = $this->make_rule();
		$this->assertNull( $rule( null, array( 'post_id' => 99 ) ) );
	}

	public function test_all_posts_mode_ignores_drafts(): void {
		$this->set_options( array( 'paywall_mode' => SettingsRepository::MODE_ALL_POSTS ) );
		$GLOBALS['__sx402_posts'][99] = array(
			'post_type'   => 'post',
			'post_status' => 'draft',
		);
		$rule                         = $this->make_rule();
		$this->assertNull( $rule( null, array( 'post_id' => 99 ) ) );
	}

	public function test_all_posts_mode_preserves_higher_priority_filter(): void {
		$this->set_options( array( 'paywall_mode' => SettingsRepository::MODE_ALL_POSTS ) );
		$rule   = $this->make_rule();
		$preset = array(
			'price' => '9.99',
			'ttl'   => 10,
		);
		$this->assertSame( $preset, $rule( $preset, array( 'post_id' => 99 ) ) );
	}

	public function test_audience_none_disables_paywall_even_on_matching_post(): void {
		$this->set_options( array( 'paywall_audience' => SettingsRepository::AUDIENCE_NONE ) );
		$GLOBALS['__sx402_terms'] = array( array( self::DEFAULT_TERM_ID, 'category', 7 ) );
		$rule                     = $this->make_rule( self::BOT_UA );
		$this->assertNull( $rule( null, array( 'post_id' => 7 ) ) );
	}

	public function test_audience_bots_gates_bot_on_matching_post(): void {
		$this->set_options( array( 'paywall_audience' => SettingsRepository::AUDIENCE_BOTS ) );
		$GLOBALS['__sx402_terms'] = array( array( self::DEFAULT_TERM_ID, 'category', 7 ) );
		$rule                     = $this->make_rule( self::BOT_UA );
		$this->assertSame(
			array(
				'price' => '0.01',
				'ttl'   => 86400,
			),
			$rule( null, array( 'post_id' => 7 ) )
		);
	}

	public function test_audience_bots_skips_human_visitor(): void {
		$this->set_options( array( 'paywall_audience' => SettingsRepository::AUDIENCE_BOTS ) );
		$GLOBALS['__sx402_terms'] = array( array( self::DEFAULT_TERM_ID, 'category', 7 ) );
		$rule                     = $this->make_rule( self::HUMAN_UA );
		$this->assertNull( $rule( null, array( 'post_id' => 7 ) ) );
	}

	public function test_audience_bots_respects_mode_and_skips_non_matching_post(): void {
		// Bot visiting a singular page that does NOT match the paywall category
		// must NOT be gated — audience decides who, mode decides which posts.
		$this->set_options( array( 'paywall_audience' => SettingsRepository::AUDIENCE_BOTS ) );
		$GLOBALS['__sx402_terms'] = array();
		$rule                     = $this->make_rule( self::BOT_UA );
		$this->assertNull( $rule( null, array( 'post_id' => 7 ) ) );
	}

	public function test_audience_everyone_gates_human_on_matching_post(): void {
		$this->set_options( array( 'paywall_audience' => SettingsRepository::AUDIENCE_EVERYONE ) );
		$GLOBALS['__sx402_terms'] = array( array( self::DEFAULT_TERM_ID, 'category', 7 ) );
		$rule                     = $this->make_rule( self::HUMAN_UA );
		$this->assertNotNull( $rule( null, array( 'post_id' => 7 ) ) );
	}

	public function test_audience_bots_lets_search_engine_indexer_through(): void {
		// Googlebot visits a post in the paywall category — audience=bots
		// must let indexers pass so organic search still works.
		$this->set_options( array( 'paywall_audience' => SettingsRepository::AUDIENCE_BOTS ) );
		$GLOBALS['__sx402_terms'] = array( array( self::DEFAULT_TERM_ID, 'category', 7 ) );
		$rule                     = $this->make_rule( self::SEARCH_ENGINE_UA );
		$this->assertNull( $rule( null, array( 'post_id' => 7 ) ) );
	}

	public function test_audience_everyone_still_gates_search_engine(): void {
		// Allowlist is scoped to audience=bots. "Everyone" means "everyone"
		// — if the admin chose it, Googlebot is gated like any other visitor.
		$this->set_options( array( 'paywall_audience' => SettingsRepository::AUDIENCE_EVERYONE ) );
		$GLOBALS['__sx402_terms'] = array( array( self::DEFAULT_TERM_ID, 'category', 7 ) );
		$rule                     = $this->make_rule( self::SEARCH_ENGINE_UA );
		$this->assertNotNull( $rule( null, array( 'post_id' => 7 ) ) );
	}

	public function test_audience_bots_gates_search_engine_when_allowlist_disabled(): void {
		// Admin unchecked "Known search engines" — Googlebot pays like any
		// other bot.
		$this->set_options(
			array(
				'paywall_audience'     => SettingsRepository::AUDIENCE_BOTS,
				'allow_search_engines' => false,
			)
		);
		$GLOBALS['__sx402_terms'] = array( array( self::DEFAULT_TERM_ID, 'category', 7 ) );
		$rule                     = $this->make_rule( self::SEARCH_ENGINE_UA );
		$this->assertNotNull( $rule( null, array( 'post_id' => 7 ) ) );
	}
}
