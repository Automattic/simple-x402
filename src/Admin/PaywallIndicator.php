<?php
/**
 * Admin Bar indicator for paywalled frontend views.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Admin;

use SimpleX402\Services\RuleResolver;
use SimpleX402\Admin\SettingsPage;

/**
 * Adds a top admin-bar node when an admin is viewing a frontend post that
 * would be paywalled for non-admin visitors.
 *
 * Shares {@see RuleResolver} with {@see \SimpleX402\Http\PaywallController},
 * so the indicator stays in lockstep with the real paywall decision. The
 * controller layers the `simple_x402_bypass_paywall` filter on top of the
 * resolved rule; the indicator does not — it specifically wants to know what
 * a non-admin would see.
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
						'Non-admin visitors see an HTTP 402 here. Click to open Simple x402 settings.',
						'simple-x402'
					),
				),
			)
		);
	}
}
