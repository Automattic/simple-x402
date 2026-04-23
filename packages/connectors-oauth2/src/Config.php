<?php
/**
 * Merged connector + OAuth2 supplement config.
 *
 * @package Automattic\Connectors\OAuth2
 */

declare(strict_types=1);

namespace Automattic\Connectors\OAuth2;

/**
 * Combines the two sources a Client needs:
 *   - Connector registration (api_key method + setting_name + credentials_url).
 *   - OAuth2 supplement (authorize_url, token_url, client_id, scope).
 *
 * Returns null from the factory if either half is missing or malformed.
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
	 * @param array<string,mixed> $connector Raw connector from wp_get_connector().
	 * @param array<string,mixed> $extension OAuth supplement registered via Client::register().
	 */
	public static function build( array $connector, array $extension ): ?self {
		$auth = $connector['authentication'] ?? null;
		if ( ! is_array( $auth ) ) {
			return null;
		}
		// Core only accepts api_key + none. OAuth tokens live behind api_key.
		if ( 'api_key' !== ( $auth['method'] ?? '' ) ) {
			return null;
		}
		$setting = (string) ( $auth['setting_name'] ?? '' );
		if ( '' === $setting ) {
			return null;
		}

		$authorize = (string) ( $extension['authorize_url'] ?? '' );
		$token     = (string) ( $extension['token_url'] ?? '' );
		$client    = (string) ( $extension['client_id'] ?? '' );
		if ( '' === $authorize || '' === $token || '' === $client ) {
			return null;
		}
		if ( ! preg_match( '#^https?://#i', $authorize ) || ! preg_match( '#^https?://#i', $token ) ) {
			return null;
		}

		return new self(
			authorize_url: $authorize,
			token_url: $token,
			client_id: $client,
			scope: (string) ( $extension['scope'] ?? '' ),
			setting_name: $setting,
			credentials_url: (string) ( $auth['credentials_url'] ?? '' ),
		);
	}
}
