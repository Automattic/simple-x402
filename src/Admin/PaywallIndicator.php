<?php
/**
 * Admin Bar indicator for paywalled frontend views.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Admin;

use SimpleX402\Services\DefaultPaywallRule;
use SimpleX402\Services\RuleResolver;
use SimpleX402\Admin\SettingsPage;

/**
 * Adds a top admin-bar node when an admin is viewing a singular post in the
 * configured paywall scope (mode: all published posts, or paywall category).
 *
 * The resolve context sets {@see DefaultPaywallRule::CTX_KEY_ADMIN_BAR_SCOPE}
 * so the admin bar can show in-scope posts even when audience is "only bots"
 * (human guests are not 402 in that case, but the editor still needs to see
 * which posts the plugin targets). {@see \SimpleX402\Http\PaywallController}
 * does not set that key; real 402s are unchanged. The controller still applies
 * the `simple_x402_bypass_paywall` filter for the actual request.
 */
final class PaywallIndicator {

	public const NODE_ID = 'simple-x402-paywalled';

	public function __construct( private readonly RuleResolver $rules ) {}

	/**
	 * Attach hooks.
	 */
	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_node' ), 100 );
	}

	/**
	 * Admin-bar callback. Adds the indicator node when the current frontend
	 * view would trigger a paywall.
	 *
	 * @param \WP_Admin_Bar $bar The admin bar instance.
	 */
	public function add_node( $bar ): void {
		if ( is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$post_id = is_singular() ? (int) get_queried_object_id() : 0;
		if ( $post_id <= 0 ) {
			return;
		}
		$path = (string) ( wp_parse_url(
			home_url( add_query_arg( array() ) ),
			PHP_URL_PATH
		) ?? '/' );

		$rule = $this->rules->resolve(
			array(
				'path'     => $path,
				'method'   => 'GET',
				'post_id'  => $post_id,
				'singular' => true,
				DefaultPaywallRule::CTX_KEY_ADMIN_BAR_SCOPE => true,
			)
		);
		if ( null === $rule ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => self::NODE_ID,
				'title' => esc_html__( 'Paywall active', 'simple-x402' ),
				'href'  => admin_url( 'options-general.php?page=' . SettingsPage::MENU_SLUG ),
				'meta'  => array(
					'title' => esc_attr__(
						'This post is in your paywall scope. Open Simple x402 settings for audience, mode, and price.',
						'simple-x402'
					),
				),
			)
		);
	}
}
