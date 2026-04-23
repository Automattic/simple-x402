<?php
/**
 * Registers the WordPress.com x402 facilitator connector.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Connectors;

use Automattic\Connectors\OAuth2\Client as OAuth2Client;
use SimpleX402\Facilitator\Facilitator;
use SimpleX402\Facilitator\WPComFacilitator;

/**
 * Registers `wpcom_x402` — the WordPress.com facilitator.
 *
 * The connector is registered with `authentication.method = 'api_key'` (the
 * only bearer-credential method WP 7.0's Connectors API accepts). OAuth2 +
 * PKCE params (authorize_url, token_url, client_id, scope) are declared
 * separately via `automattic/connectors-oauth2`'s extension registry — that's
 * what drives the Connect-to-WP.com flow.
 *
 * Until the WordPress.com service actually ships the `/wpcom/v2/x402/*`
 * endpoints, this is a prototype: the Connect flow, token storage, and
 * bearer-authed calls are real, but verify/settle will 404.
 */
final class WPComConnectorRegistrar {

	public const ID = 'wpcom_x402';

	public const TOKEN_SETTING = 'connectors_wpcom_x402_token';

	/** Public OAuth2 client identifier. Placeholder until a real WP.com app is registered. */
	private const CLIENT_ID = 'simple-x402-wpcom-placeholder';

	public function __construct() {
		// Register the OAuth2 supplement as early as possible so
		// OAuth2Client::for_connector( self::ID ) works anywhere in the
		// request path — including at admin_init, before wp_connectors_init.
		OAuth2Client::register(
			self::ID,
			array(
				'authorize_url' => 'https://public-api.wordpress.com/oauth2/authorize',
				'token_url'     => 'https://public-api.wordpress.com/oauth2/token',
				'client_id'     => self::CLIENT_ID,
				'scope'         => 'global',
			)
		);
	}

	/**
	 * Hooked to `wp_connectors_init`.
	 */
	public function __invoke( \WP_Connector_Registry $registry ): void {
		$registry->register( self::ID, self::payload() );
	}

	/**
	 * `simple_x402_facilitator_for_connector` filter callback. Returns a
	 * WPComFacilitator for our connector ID; forwards the existing value
	 * otherwise.
	 */
	public function provide_facilitator( ?Facilitator $existing, string $id ): ?Facilitator {
		if ( self::ID !== $id || null !== $existing ) {
			return $existing;
		}
		$oauth = OAuth2Client::for_connector( self::ID );
		if ( null === $oauth ) {
			return $existing;
		}
		return new WPComFacilitator( $oauth );
	}

	/**
	 * Connectors API registration payload.
	 *
	 * method is `api_key` (the only bearer-credential method core accepts);
	 * the setting_name is shared with our OAuth2 library so the token
	 * populated by the OAuth flow lives in the same place Core's credential
	 * chain reads from.
	 *
	 * @return array<string,mixed>
	 */
	public static function payload(): array {
		return array(
			'name'           => 'WordPress.com',
			'description'    => 'Settle x402 payments through WordPress.com. Connect with your WP.com account.',
			'type'           => ConnectorRegistry::FACILITATOR_TYPE,
			'authentication' => array(
				'method'          => 'api_key',
				'setting_name'    => self::TOKEN_SETTING,
				'credentials_url' => 'https://wordpress.com/me/security/connected-apps',
			),
			'plugin'         => array( 'file' => 'simple-x402/simple-x402.php' ),
		);
	}
}
