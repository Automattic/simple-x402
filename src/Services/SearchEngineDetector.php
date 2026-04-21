<?php
/**
 * UA-substring allowlist for search-engine crawlers.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Services;

/**
 * Complements BotDetector by answering "is this bot a search-engine indexer
 * we want to let through?" — so organic search traffic still works when the
 * audience setting is `bots`.
 *
 * The check is case-insensitive UA-substring only. A scraper that sets
 * `User-Agent: Googlebot` will pass. Verifiable identification (e.g. reverse
 * DNS against *.googlebot.com) is out of scope: it's a policy convenience,
 * not a security boundary. Bad actors who spoof UAs already bypass most of
 * the web's bot defenses.
 *
 * Site owners can tailor the list via the `simple_x402_search_engine_allowlist`
 * filter.
 */
final class SearchEngineDetector {

	public const FILTER_ALLOWLIST = 'simple_x402_search_engine_allowlist';

	/**
	 * Default UA substrings for major search-engine indexers.
	 *
	 * Deliberate exclusions: `Google-Extended`, `Applebot-Extended`, `GPTBot`,
	 * `ClaudeBot`, `PerplexityBot`, `CCBot`, `Bytespider`, etc. — these are
	 * AI-training / agent crawlers that the audience=bots setting is designed
	 * to paywall.
	 *
	 * `Applebot` is also omitted because substring-matching it would
	 * collide with `Applebot-Extended`. Sites that want Apple's indexer can
	 * add it back via the filter; AI-training traffic can be denied separately.
	 */
	public const DEFAULTS = array(
		'Googlebot',
		'Bingbot',
		'DuckDuckBot',
		'YandexBot',
		'Baiduspider',
	);

	/**
	 * @var list<string>|null Lazily-resolved allowlist (filter applied once).
	 */
	private ?array $allowlist = null;

	public function __construct( private readonly string $user_agent ) {}

	/**
	 * Whether the injected User-Agent matches a known search-engine crawler.
	 */
	public function is_search_engine(): bool {
		if ( '' === $this->user_agent ) {
			return false;
		}
		foreach ( $this->allowlist() as $needle ) {
			if ( '' !== $needle && false !== stripos( $this->user_agent, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return list<string>
	 */
	private function allowlist(): array {
		if ( null !== $this->allowlist ) {
			return $this->allowlist;
		}
		$filtered        = function_exists( 'apply_filters' )
			? apply_filters( self::FILTER_ALLOWLIST, self::DEFAULTS )
			: self::DEFAULTS;
		$this->allowlist = is_array( $filtered )
			? array_values( array_filter( $filtered, 'is_string' ) )
			: self::DEFAULTS;
		return $this->allowlist;
	}
}
