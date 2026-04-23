<?php
/**
 * Typed view of a connector's oauth2 authentication block.
 *
 * @package Automattic\Connectors\OAuth2
 */

declare(strict_types=1);

namespace Automattic\Connectors\OAuth2;

/**
 * Parses `$connector['authentication']` into a strongly-typed struct. Returns
 * null from the factory if any required field is missing or malformed — the
 * Client treats that as "this connector isn't usable," caller surfaces the
 * failure however it sees fit.
 */
final class Config {

	public function __construct(
		public readonly string $authorize_url,
		public readonly string $token_url,
		public readonly string $client_id,
		public readonly string $scope,
		public readonly string $setting_name,
		public readonly string $credentials_url,
	) {}

	/**
	 * Build a Config from a raw connector array. Returns null if the
	 * authentication block is missing, not oauth2, or missing required
	 * fields.
	 *
	 * @param array<string,mixed> $connector Raw connector data from wp_get_connector().
	 */
	public static function from_connector( array $connector ): ?self {
		$auth = $connector['authentication'] ?? null;
		if ( ! is_array( $auth ) || Client::METHOD !== ( $auth['method'] ?? '' ) ) {
			return null;
		}

		$authorize = (string) ( $auth['authorize_url'] ?? '' );
		$token     = (string) ( $auth['token_url'] ?? '' );
		$client    = (string) ( $auth['client_id'] ?? '' );
		$setting   = (string) ( $auth['setting_name'] ?? '' );

		if ( '' === $authorize || '' === $token || '' === $client || '' === $setting ) {
			return null;
		}
		if ( ! preg_match( '#^https?://#i', $authorize ) || ! preg_match( '#^https?://#i', $token ) ) {
			return null;
		}

		return new self(
			authorize_url: $authorize,
			token_url: $token,
			client_id: $client,
			scope: (string) ( $auth['scope'] ?? '' ),
			setting_name: $setting,
			credentials_url: (string) ( $auth['credentials_url'] ?? '' ),
		);
	}
}
