<?php
declare(strict_types=1);

namespace Automattic\Connectors\OAuth2\Tests;

use Automattic\Connectors\OAuth2\Client;
use Automattic\Connectors\OAuth2\Config;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase {

	private const CONNECTOR_ID = 'example_oauth2';
	private const SETTING      = 'connectors_oauth2_example_token';

	protected function setUp(): void {
		$GLOBALS['__sx402_connectors']  = array(
			self::CONNECTOR_ID => self::valid_connector(),
		);
		$GLOBALS['__sx402_options']     = array();
		$GLOBALS['__sx402_transients']  = array();
		$GLOBALS['__sx402_http_next']   = null;
		$GLOBALS['__sx402_http_queue']  = array();
		$GLOBALS['__sx402_http']        = null;
	}

	/** @return array<string,mixed> */
	private static function valid_connector(): array {
		return array(
			'name'           => 'Example',
			'type'           => 'example_type',
			'authentication' => array(
				'method'          => 'oauth2',
				'authorize_url'   => 'https://provider.example/oauth2/authorize',
				'token_url'       => 'https://provider.example/oauth2/token',
				'client_id'       => 'my-app',
				'scope'           => 'read write',
				'setting_name'    => self::SETTING,
				'credentials_url' => 'https://provider.example/account/apps',
			),
			'plugin'         => array( 'file' => 'test/plugin.php' ),
		);
	}

	public function test_for_connector_returns_null_when_connector_missing(): void {
		$GLOBALS['__sx402_connectors'] = array();
		$this->assertNull( Client::for_connector( self::CONNECTOR_ID ) );
	}

	public function test_for_connector_returns_null_for_non_oauth2_method(): void {
		$GLOBALS['__sx402_connectors'][ self::CONNECTOR_ID ]['authentication']['method'] = 'api_key';
		$this->assertNull( Client::for_connector( self::CONNECTOR_ID ) );
	}

	public function test_for_connector_returns_null_when_required_field_missing(): void {
		unset( $GLOBALS['__sx402_connectors'][ self::CONNECTOR_ID ]['authentication']['client_id'] );
		$this->assertNull( Client::for_connector( self::CONNECTOR_ID ) );
	}

	public function test_authorize_url_contains_pkce_state_and_redirect(): void {
		$client = Client::for_connector( self::CONNECTOR_ID );
		$url    = $client->authorize_url( 'https://site.example/wp-admin/admin.php?page=x&callback=1' );

		$this->assertStringStartsWith( 'https://provider.example/oauth2/authorize?', $url );
		$query = array();
		parse_str( parse_url( $url, PHP_URL_QUERY ), $query );

		$this->assertSame( 'code', $query['response_type'] );
		$this->assertSame( 'my-app', $query['client_id'] );
		$this->assertSame( 'https://site.example/wp-admin/admin.php?page=x&callback=1', $query['redirect_uri'] );
		$this->assertSame( 'read write', $query['scope'] );
		$this->assertSame( 'S256', $query['code_challenge_method'] );
		$this->assertNotEmpty( $query['state'] );
		$this->assertNotEmpty( $query['code_challenge'] );
	}

	public function test_authorize_url_stashes_verifier_keyed_by_state(): void {
		$client = Client::for_connector( self::CONNECTOR_ID );
		$url    = $client->authorize_url( 'https://site.example/cb' );
		$query  = array();
		parse_str( parse_url( $url, PHP_URL_QUERY ), $query );

		$flow = get_transient( 'conn_oauth2_flow_' . $query['state'] );
		$this->assertIsArray( $flow );
		$this->assertArrayHasKey( 'verifier', $flow );
		$this->assertSame( 'https://site.example/cb', $flow['redirect_uri'] );
	}

	public function test_handle_callback_stores_access_token_on_success(): void {
		$client = Client::for_connector( self::CONNECTOR_ID );
		$url    = $client->authorize_url( 'https://site.example/cb' );
		$query  = array();
		parse_str( parse_url( $url, PHP_URL_QUERY ), $query );

		$GLOBALS['__sx402_http_next'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"access_token":"abc123","token_type":"Bearer"}',
		);

		$token = $client->handle_callback( 'auth-code-xyz', $query['state'] );

		$this->assertSame( 'abc123', $token );
		$this->assertSame( 'abc123', get_option( self::SETTING, '' ) );
		$this->assertSame( 'https://provider.example/oauth2/token', $GLOBALS['__sx402_http']['url'] );

		// Flow transient cleared so a replay fails.
		$this->assertFalse( get_transient( 'conn_oauth2_flow_' . $query['state'] ) );
	}

	public function test_handle_callback_includes_verifier_and_redirect_in_token_request(): void {
		$client = Client::for_connector( self::CONNECTOR_ID );
		$url    = $client->authorize_url( 'https://site.example/cb' );
		$query  = array();
		parse_str( parse_url( $url, PHP_URL_QUERY ), $query );
		$stashed_verifier = get_transient( 'conn_oauth2_flow_' . $query['state'] )['verifier'];

		$GLOBALS['__sx402_http_next'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"access_token":"abc"}',
		);
		$client->handle_callback( 'code', $query['state'] );

		parse_str( $GLOBALS['__sx402_http']['args']['body'], $body );
		$this->assertSame( 'authorization_code', $body['grant_type'] );
		$this->assertSame( 'code', $body['code'] );
		$this->assertSame( 'https://site.example/cb', $body['redirect_uri'] );
		$this->assertSame( 'my-app', $body['client_id'] );
		$this->assertSame( $stashed_verifier, $body['code_verifier'] );
	}

	public function test_handle_callback_rejects_unknown_state(): void {
		$client = Client::for_connector( self::CONNECTOR_ID );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'invalid_or_expired_state' );
		$client->handle_callback( 'code', 'never-issued' );
	}

	public function test_handle_callback_rejects_token_endpoint_error(): void {
		$client = Client::for_connector( self::CONNECTOR_ID );
		$url    = $client->authorize_url( 'https://site.example/cb' );
		$query  = array();
		parse_str( parse_url( $url, PHP_URL_QUERY ), $query );

		$GLOBALS['__sx402_http_next'] = array(
			'response' => array( 'code' => 400 ),
			'body'     => '{"error":"invalid_grant"}',
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'token_endpoint_rejected' );
		$client->handle_callback( 'code', $query['state'] );
	}

	public function test_handle_callback_rejects_missing_access_token(): void {
		$client = Client::for_connector( self::CONNECTOR_ID );
		$url    = $client->authorize_url( 'https://site.example/cb' );
		$query  = array();
		parse_str( parse_url( $url, PHP_URL_QUERY ), $query );

		$GLOBALS['__sx402_http_next'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"token_type":"Bearer"}',
		);

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'token_missing_access_token' );
		$client->handle_callback( 'code', $query['state'] );
	}

	public function test_bearer_token_reads_from_option(): void {
		update_option( self::SETTING, 'stored-token' );
		$client = Client::for_connector( self::CONNECTOR_ID );
		$this->assertSame( 'stored-token', $client->bearer_token() );
		$this->assertTrue( $client->is_connected() );
	}

	public function test_bearer_token_env_beats_option(): void {
		update_option( self::SETTING, 'stored-token' );
		putenv( strtoupper( self::SETTING ) . '=env-token' );
		try {
			$client = Client::for_connector( self::CONNECTOR_ID );
			$this->assertSame( 'env-token', $client->bearer_token() );
		} finally {
			putenv( strtoupper( self::SETTING ) );
		}
	}

	public function test_is_connected_false_when_nothing_stored(): void {
		$client = Client::for_connector( self::CONNECTOR_ID );
		$this->assertFalse( $client->is_connected() );
		$this->assertSame( '', $client->bearer_token() );
	}

	public function test_revoke_clears_stored_token(): void {
		update_option( self::SETTING, 'stored-token' );
		$client = Client::for_connector( self::CONNECTOR_ID );
		$this->assertTrue( $client->is_connected() );
		$client->revoke();
		$this->assertFalse( $client->is_connected() );
	}
}
