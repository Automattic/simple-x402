<?php
/**
 * Admin-side OAuth2 connect / callback / disconnect router.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Admin;

use Automattic\Connectors\OAuth2\Client as OAuth2Client;

/**
 * Intercepts admin requests to Settings → Simple x402 when a `?action=` query
 * arg is present and routes them through the OAuth2 helper.
 *
 * Connect + disconnect actions require a WP nonce (CSRF for the *initiation*
 * from our admin). The callback doesn't — OAuth's own `state` param is the
 * CSRF guard there.
 */
final class OAuthRouter {

	public const ACTION_CONNECT    = 'connect_oauth';
	public const ACTION_CALLBACK   = 'oauth_callback';
	public const ACTION_DISCONNECT = 'disconnect_oauth';

	public const NONCE_CONNECT    = 'simple_x402_connect_oauth';
	public const NONCE_DISCONNECT = 'simple_x402_disconnect_oauth';

	public function register(): void {
		add_action( 'admin_init', array( $this, 'maybe_handle' ) );
	}

	public function maybe_handle(): void {
		$page = isset( $_GET['page'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['page'] ) ) : '';
		if ( SettingsPage::MENU_SLUG !== $page ) {
			return;
		}
		$action = isset( $_GET['action'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['action'] ) ) : '';
		if ( '' === $action ) {
			return;
		}

		$connector_id = isset( $_GET['connector'] )
			? (string) preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) wp_unslash( $_GET['connector'] ) ) )
			: '';
		if ( '' === $connector_id ) {
			$this->redirect_with_notice( 'error', 'missing_connector' );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'simple-x402' ), '', 403 );
		}

		match ( $action ) {
			self::ACTION_CONNECT    => $this->handle_connect( $connector_id ),
			self::ACTION_CALLBACK   => $this->handle_callback( $connector_id ),
			self::ACTION_DISCONNECT => $this->handle_disconnect( $connector_id ),
			default                 => null,
		};
	}

	private function handle_connect( string $connector_id ): void {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_CONNECT ) ) {
			$this->redirect_with_notice( 'error', 'bad_nonce' );
		}

		$client = OAuth2Client::for_connector( $connector_id );
		if ( null === $client ) {
			$this->redirect_with_notice( 'error', 'connector_not_oauth2' );
		}

		wp_safe_redirect( $client->authorize_url( self::callback_url( $connector_id ) ) );
		exit;
	}

	private function handle_callback( string $connector_id ): void {
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['code'] ) ) : '';
		$state = isset( $_GET['state'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['state'] ) ) : '';

		$client = OAuth2Client::for_connector( $connector_id );
		if ( null === $client ) {
			$this->redirect_with_notice( 'error', 'connector_not_oauth2' );
		}

		try {
			$client->handle_callback( $code, $state );
			$this->redirect_with_notice( 'success', 'connected' );
		} catch ( \Throwable $e ) {
			$this->redirect_with_notice( 'error', $e->getMessage() );
		}
	}

	private function handle_disconnect( string $connector_id ): void {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_DISCONNECT ) ) {
			$this->redirect_with_notice( 'error', 'bad_nonce' );
		}

		$client = OAuth2Client::for_connector( $connector_id );
		if ( null !== $client ) {
			$client->revoke();
		}
		$this->redirect_with_notice( 'success', 'disconnected' );
	}

	/**
	 * The callback URL we include in the authorize request — where the
	 * provider sends the user back with `?code=…&state=…`.
	 */
	public static function callback_url( string $connector_id ): string {
		return add_query_arg(
			array(
				'page'      => SettingsPage::MENU_SLUG,
				'action'    => self::ACTION_CALLBACK,
				'connector' => $connector_id,
			),
			admin_url( 'options-general.php' )
		);
	}

	public static function connect_url( string $connector_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'      => SettingsPage::MENU_SLUG,
					'action'    => self::ACTION_CONNECT,
					'connector' => $connector_id,
				),
				admin_url( 'options-general.php' )
			),
			self::NONCE_CONNECT
		);
	}

	public static function disconnect_url( string $connector_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'      => SettingsPage::MENU_SLUG,
					'action'    => self::ACTION_DISCONNECT,
					'connector' => $connector_id,
				),
				admin_url( 'options-general.php' )
			),
			self::NONCE_DISCONNECT
		);
	}

	private function redirect_with_notice( string $kind, string $message ): void {
		$target = add_query_arg(
			array(
				'page'              => SettingsPage::MENU_SLUG,
				'simple_x402_oauth' => $kind,
				'reason'            => rawurlencode( $message ),
			),
			admin_url( 'options-general.php' )
		);
		wp_safe_redirect( $target );
		exit;
	}
}
