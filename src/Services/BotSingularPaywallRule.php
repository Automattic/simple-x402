<?php
/**
 * Paywall singular content for detected bots/crawlers.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Services;

use SimpleX402\Settings\SettingsRepository;

/**
 * Filter callback at priority 5 on `simple_x402_rule_for_request`.
 *
 * If the request is for a singular template (`is_singular()` in production,
 * passed as `singular` in context) and the User-Agent matches a known bot,
 * require the default x402 payment. Human visitors still use the tag/category
 * rule at priority 10.
 */
final class BotSingularPaywallRule {

	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly BotDetector $bots,
	) {}

	/**
	 * @param array|null $rule Prior filter result.
	 * @param array      $ctx  Request context; expects `singular` (bool).
	 */
	public function __invoke( $rule, array $ctx ): ?array {
		if ( is_array( $rule ) ) {
			return $rule;
		}
		if ( empty( $ctx['singular'] ) ) {
			return null;
		}
		if ( ! $this->bots->is_bot() ) {
			return null;
		}
		return array(
			'price' => $this->settings->default_price(),
			'ttl'   => RuleResolver::DEFAULT_TTL,
		);
	}
}
