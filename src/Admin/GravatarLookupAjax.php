<?php
/**
 * admin-ajax handler for "Look up from Gravatar" on the settings page.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Admin;

/**
 * Calls the configured Gravatar Wallet service (a future Automattic feature
 * — currently mocked by the gravatar-wallet-prototype repo) to fetch the
 * wallet address associated with a given Gravatar email, so the publisher
 * can fill the Receiving wallet field without copy-pasting an address.
 *
 * Endpoint defaults to http://localhost:8787 (the local prototype) and can
 * be overridden via the `simple_x402_gravatar_endpoint` filter.
 */
final class GravatarLookupAjax {

	public const ACTION = 'simple_x402_gravatar_lookup';
	public const NONCE  = 'simple_x402_gravatar_lookup_nonce';

	public const DEFAULT_ENDPOINT = 'http://localhost:8787';

	/** Filter the Gravatar Wallet base URL. Empty string disables lookup. */
	public const ENDPOINT_FILTER = 'simple_x402_gravatar_endpoint';

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'error' => 'forbidden' ), 403 );
			return;
		}
		check_ajax_referer( self::NONCE, 'nonce' );

		$email = isset( $_POST['email'] )
			? sanitize_email( wp_unslash( (string) $_POST['email'] ) )
			: '';
		if ( '' === $email || ! is_email( $email ) ) {
			wp_send_json_error( array( 'error' => 'invalid_email' ), 400 );
			return;
		}

		$endpoint = self::endpoint();
		if ( '' === $endpoint ) {
			wp_send_json_error( array( 'error' => 'lookup_disabled' ), 400 );
			return;
		}

		$url      = rtrim( $endpoint, '/' ) . '/profile/by-email/' . rawurlencode( $email );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 5,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			wp_send_json_error(
				array(
					'error'   => 'lookup_failed',
					'message' => $response->get_error_message(),
				),
				502
			);
			return;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		$json   = json_decode( $body, true );

		if ( 404 === $status ) {
			wp_send_json_error( array( 'error' => 'no_gravatar_for_email' ), 404 );
			return;
		}
		if ( $status < 200 || $status >= 300 || ! is_array( $json ) ) {
			wp_send_json_error(
				array(
					'error'  => 'lookup_failed',
					'status' => $status,
				),
				502
			);
			return;
		}

		wp_send_json_success(
			array(
				'walletAddress' => (string) ( $json['walletAddress'] ?? '' ),
				'displayName'   => (string) ( $json['displayName'] ?? '' ),
				'email'         => (string) ( $json['email'] ?? $email ),
				'avatarUrl'     => (string) ( $json['avatarUrl'] ?? '' ),
			)
		);
	}

	/**
	 * Resolve the Gravatar Wallet base URL — filterable; '' disables the feature.
	 */
	public static function endpoint(): string {
		$raw = (string) apply_filters( self::ENDPOINT_FILTER, self::DEFAULT_ENDPOINT );
		return trim( $raw );
	}
}
