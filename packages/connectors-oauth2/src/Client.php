<?php
/**
 * OAuth2 + PKCE helper for WordPress Connectors API registrations.
 *
 * @package Automattic\Connectors\OAuth2
 */

declare(strict_types=1);

namespace Automattic\Connectors\OAuth2;

/**
 * Drive the OAuth2 Authorization Code + PKCE flow for a connector registered
 * with `authentication.method: oauth2`.
 *
 * Expected connector `authentication` block:
 *   - method:          'oauth2' (constant)
 *   - authorize_url:   provider's authorize endpoint
 *   - token_url:       provider's token endpoint
 *   - client_id:       public client identifier
 *   - scope:           space-delimited requested scopes (optional)
 *   - pkce:            bool (default true; only PKCE flows are supported today)
 *   - setting_name:    WP option / env var name where the access token lives
 *   - credentials_url: optional, shown to users for revocation
 *
 * Tokens are stored in the same env → PHP constant → DB option chain that
 * Core's Connectors API uses for api_key credentials. env/constant are read
 * only; writes only ever hit the DB option (they're inherently read-only).
 */
final class Client {

	public const METHOD = 'oauth2';

	/** Transient prefix for per-flow PKCE verifier lookups. */
	private const FLOW_PREFIX = 'conn_oauth2_flow_';

	/** Transient TTL in seconds. Flows that take longer than this fail closed. */
	private const FLOW_TTL = 600;

	private function __construct(
		private readonly string $connector_id,
		private readonly Config $config,
	) {}

	/**
	 * Load a configured Client for the given connector ID.
	 *
	 * Reads the connector via `wp_get_connector()`, parses and validates its
	 * oauth2 authentication block. Returns null if the connector doesn't
	 * exist, isn't oauth2-flavoured, or its config is malformed.
	 */
	public static function for_connector( string $connector_id ): ?self {
		if ( ! function_exists( 'wp_get_connector' ) ) {
			return null;
		}
		$connector = wp_get_connector( $connector_id );
		if ( ! is_array( $connector ) ) {
			return null;
		}
		$config = Config::from_connector( $connector );
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
	 *
	 * Returns the access token on success; throws on any error so callers
	 * can surface a clear failure to the user.
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
	 * Current access token if one is stored, or '' otherwise. Reads through
	 * env → constant → DB option, matching Core's api_key resolution chain.
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

	/**
	 * Clear the stored access token so the site is "disconnected." Doesn't
	 * attempt to revoke on the provider side — the user does that via the
	 * provider's own UI (we can surface the link via Config::credentials_url).
	 */
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
