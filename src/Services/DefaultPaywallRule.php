<?php
/**
 * Default rule: paywall posts with the "paywall" tag or category.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Services;

use SimpleX402\Settings\SettingsRepository;

/**
 * Callback for the `simple_x402_rule_for_request` filter at priority 10.
 *
 * Respects a higher-priority filter's answer if one is already set; otherwise
 * returns a paywall rule when the current post has the `paywall` tag or
 * category.
 */
final class DefaultPaywallRule {

	public const TERM = 'paywall';

	/**
	 * @param SettingsRepository $settings Provides the default price.
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
		if ( ! has_term( self::TERM, 'post_tag', $post_id )
			&& ! has_term( self::TERM, 'category', $post_id ) ) {
			return null;
		}
		return array(
			'price' => $this->settings->default_price(),
			'ttl'   => RuleResolver::DEFAULT_TTL,
		);
	}
}
