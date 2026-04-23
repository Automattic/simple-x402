<?php
/**
 * OAuth2 + PKCE helper for WordPress Connectors API registrations.
 *
 * @package Automattic\Connectors\OAuth2
 */

declare(strict_types=1);

namespace Automattic\Connectors\OAuth2;

/**
 * Drive the OAuth2 Authorization Code + PKCE flow for a connector whose
 * credential is stored via Core's `api_key` credential chain.
 *
 * Why not a first-class `authentication.method: oauth2`? Because WP 7.0's
 * Connectors API silently rejects any method other than `api_key` or `none`.
 * So we register the connector as `method: 'api_key'` + `setting_name: …`,
 * and declare the OAuth2-specific params (authorize_url, token_url,
 * client_id, …) separately through this library's own registry.
 *
 * Usage:
 *   1. Plugin registers the connector via `wp_connectors_init` with
 *      `authentication.method = 'api_key'` and a `setting_name`.
 *   2. Plugin registers the OAuth supplement at load time:
 *        \Automattic\Connectors\OAuth2\Client::register( 'my_id', [
 *            'authorize_url' => '…',
 *            'token_url'     => '…',
 *            'client_id'     => '…',
 *            'scope'         => 'optional',
 *        ] );
 *   3. Plugin calls `Client::for_connector( 'my_id' )` to drive the flow.
 *
 * The `setting_name` on the connector is the join key — both core's
 * credential chain and this library read/write through it.
 */
final class Client {

	/** @var array<string,array<string,mixed>> Connector ID → OAuth2 supplement. */
	private static array $extensions = array();

	/** Transient prefix for per-flow PKCE verifier lookups. */
	private const FLOW_PREFIX = 'conn_oauth2_flow_';

	/** Transient TTL in seconds. Flows that take longer than this fail closed. */
	private const FLOW_TTL = 600;

	private function __construct(
		private readonly string $connector_id,
		private readonly Config $config,
	) {}

	/**
	 * Register the OAuth2-specific params for a connector ID. Required before
	 * `for_connector()` can return a Client for that ID. Expected keys:
	 *   - authorize_url (required)
	 *   - token_url     (required)
	 *   - client_id     (required)
	 *   - scope         (optional)
	 *
	 * @param array<string,mixed> $extension
	 */
	public static function register( string $connector_id, array $extension ): void {
		self::$extensions[ $connector_id ] = $extension;
	}

	/** Discard registrations — used by tests. */
	public static function reset_registry(): void {
		self::$extensions = array();
	}

	/**
	 * Load a configured Client for the given connector ID.
	 *
	 * Returns null if:
	 *   - The connector isn't registered, or
	 *   - The connector's authentication isn't `api_key` with a `setting_name`, or
	 *   - No OAuth2 extension has been registered for this ID.
	 */
	public static function for_connector( string $connector_id ): ?self {
		if ( ! function_exists( 'wp_get_connector' ) ) {
			return null;
		}
		$connector = wp_get_connector( $connector_id );
		if ( ! is_array( $connector ) ) {
			return null;
		}
		$extension = self::$extensions[ $connector_id ] ?? null;
		if ( null === $extension ) {
			return null;
		}
		$config = Config::build( $connector, $extension );
		if ( null === $config ) {
			return null;
		}
		return new self( $connector_id, $config );
	}

	/**
	 * Build the provider's authorize URL for a new flow. Generates a fresh
	 * PKCE verifier + random state; stashes them in a transient keyed by
	 * state so `handle_callback()` can pair them back up on redirect.
	 *
	 * @param string $redirect_uri Where the provider should redirect back.
	 */
	public function authorize_url( string $redirect_uri ): string {
		$state    = $this->random_token( 16 );
		$verifier = $this->random_token( 32 );
		$challenge = $this->pkce_challenge( $verifier );

		set_transient(
			self::FLOW_PREFIX . $state,
			array(
				'verifier'     => $verifier,
				'redirect_uri' => $redirect_uri,
			),
			self::FLOW_TTL
		);

		$params = array(
			'response_type'         => 'code',
			'client_id'             => $this->config->client_id,
			'redirect_uri'          => $redirect_uri,
			'state'                 => $state,
			'code_challenge'        => $challenge,
			'code_challenge_method' => 'S256',
		);
		if ( '' !== $this->config->scope ) {
			$params['scope'] = $this->config->scope;
		}

		return $this->config->authorize_url . ( str_contains( $this->config->authorize_url, '?' ) ? '&' : '?' )
			. http_build_query( $params );
	}

	/**
	 * Handle the `?code=…&state=…` callback from the provider. Validates
	 * state, exchanges the code at the token endpoint, and persists the
	 * access token under the connector's `setting_name`.
	 */
	public function handle_callback( string $code, string $state ): string {
		if ( '' === $code || '' === $state ) {
			throw new \RuntimeException( 'missing_code_or_state' );
		}
		$flow_key = self::FLOW_PREFIX . $state;
		$flow     = get_transient( $flow_key );
		if ( ! is_array( $flow ) || ! isset( $flow['verifier'], $flow['redirect_uri'] ) ) {
			throw new \RuntimeException( 'invalid_or_expired_state' );
		}
		delete_transient( $flow_key );

		$response = wp_remote_post(
			$this->config->token_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				),
				'body'    => http_build_query(
					array(
						'grant_type'    => 'authorization_code',
						'code'          => $code,
						'redirect_uri'  => $flow['redirect_uri'],
						'client_id'     => $this->config->client_id,
						'code_verifier' => $flow['verifier'],
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'token_http_error: ' . $response->get_error_message() );
		}
		$code_http = wp_remote_retrieve_response_code( $response );
		$body      = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code_http < 200 || $code_http >= 300 || ! is_array( $body ) ) {
			$reason = is_array( $body ) && isset( $body['error'] ) ? (string) $body['error'] : "http_{$code_http}";
			throw new \RuntimeException( 'token_endpoint_rejected: ' . $reason );
		}
		$access = isset( $body['access_token'] ) ? (string) $body['access_token'] : '';
		if ( '' === $access ) {
			throw new \RuntimeException( 'token_missing_access_token' );
		}

		update_option( $this->config->setting_name, $access );
		return $access;
	}

	/**
	 * Current access token, or '' if none. env → constant → DB option.
	 */
	public function bearer_token(): string {
		$env = getenv( strtoupper( $this->config->setting_name ) );
		if ( false !== $env && '' !== $env ) {
			return (string) $env;
		}
		$const = strtoupper( $this->config->setting_name );
		if ( defined( $const ) ) {
			return (string) constant( $const );
		}
		return (string) get_option( $this->config->setting_name, '' );
	}

	public function is_connected(): bool {
		return '' !== $this->bearer_token();
	}

	public function revoke(): void {
		delete_option( $this->config->setting_name );
	}

	private function random_token( int $byte_length ): string {
		return rtrim( strtr( base64_encode( random_bytes( $byte_length ) ), '+/', '-_' ), '=' );
	}

	private function pkce_challenge( string $verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}
}
