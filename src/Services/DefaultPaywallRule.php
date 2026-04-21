<?php
/**
 * Default rule: paywall posts based on the configured audience and mode.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Services;

use SimpleX402\Settings\SettingsRepository;

/**
 * Callback for the `simple_x402_rule_for_request` filter at priority 10.
 *
 * Respects an earlier filter's answer if one is already set; otherwise returns
 * a paywall rule based on two settings:
 *
 *  - `paywall_audience` — who the paywall targets (`everyone`, `bots`, `none`).
 *  - `paywall_mode`     — which posts qualify (`category`, `all-posts`).
 *
 * Audience is checked first: `none` disables gating entirely, and `bots`
 * requires the request's User-Agent to match a known crawler. Audience-matched
 * requests then go through the same mode check, so bots and humans see the
 * same set of gated posts.
 */
final class DefaultPaywallRule {

	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly BotDetector $bots,
	) {}

	/**
	 * @param array|null $rule Rule returned by a higher-priority filter, if any.
	 * @param array      $ctx  Request context including `post_id`.
	 */
	public function __invoke( $rule, array $ctx ): ?array {
		if ( is_array( $rule ) ) {
			return $rule;
		}
		$audience = $this->settings->paywall_audience();
		if ( SettingsRepository::AUDIENCE_NONE === $audience ) {
			return null;
		}
		if ( SettingsRepository::AUDIENCE_BOTS === $audience && ! $this->bots->is_bot() ) {
			return null;
		}
		$post_id = (int) ( $ctx['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			return null;
		}
		if ( ! $this->matches( $post_id ) ) {
			return null;
		}
		return array(
			'price' => $this->settings->default_price(),
			'ttl'   => RuleResolver::DEFAULT_TTL,
		);
	}

	/**
	 * Does the selected mode say this post should be gated?
	 */
	private function matches( int $post_id ): bool {
		return match ( $this->settings->paywall_mode() ) {
			SettingsRepository::MODE_ALL_POSTS => 'post' === get_post_type( $post_id )
				&& 'publish' === get_post_status( $post_id ),
			default => has_term( $this->settings->paywall_category_term_id(), 'category', $post_id ),
		};
	}
}
