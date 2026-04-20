<?php
/**
 * Default rule: paywall posts based on the configured paywall mode.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Services;

use SimpleX402\Settings\SettingsRepository;

/**
 * Callback for the `simple_x402_rule_for_request` filter at priority 10.
 *
 * Runs after {@see BotSingularPaywallRule} (priority 5). Respects an earlier
 * filter's answer if one is already set; otherwise returns a paywall rule based
 * on the configured `paywall_mode`:
 *  - `category`  — post is in the configured paywall category.
 *  - `all-posts` — post is a published `post` post type.
 */
final class DefaultPaywallRule {

	/**
	 * @param SettingsRepository $settings Provides mode, category term, and default price.
	 */
	public function __construct( private readonly SettingsRepository $settings ) {}

	/**
	 * @param array|null $rule Rule returned by a higher-priority filter, if any.
	 * @param array      $ctx  Request context including `post_id`.
	 */
	public function __invoke( $rule, array $ctx ): ?array {
		if ( is_array( $rule ) ) {
			return $rule;
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
			default => has_term( $this->settings->paywall_category(), 'category', $post_id ),
		};
	}
}
