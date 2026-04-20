<?php
/**
 * Thin wrapper around jaybizzle/crawler-detect.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Services;

use Jaybizzle\CrawlerDetect\CrawlerDetect;

/**
 * Detects common crawlers/bots from the User-Agent (or an injected string for tests).
 */
final class BotDetector {

	private ?CrawlerDetect $engine = null;

	public function __construct( private readonly ?string $user_agent = null ) {}

	/**
	 * Whether the current (or injected) User-Agent looks like a bot.
	 */
	public function is_bot(): bool {
		$ua = $this->user_agent;
		if ( null === $ua ) {
			$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
				? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] )
				: '';
		}
		if ( '' === $ua ) {
			return false;
		}
		if ( null === $this->engine ) {
			$this->engine = new CrawlerDetect();
		}
		return $this->engine->isCrawler( $ua );
	}
}
