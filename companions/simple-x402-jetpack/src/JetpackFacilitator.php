<?php
/**
 * Facilitator client that speaks to WordPress.com via Jetpack Connection.
 *
 * @package SimpleX402\Jetpack
 */

declare(strict_types=1);

namespace SimpleX402\Jetpack;

use SimpleX402\Facilitator\Facilitator;
use SimpleX402\Facilitator\TestResult;
use SimpleX402\Services\FacilitatorProfile;

/**
 * Signs every outbound call with Jetpack's blog token by delegating to
 * `Automattic\Jetpack\Connection\Client::wpcom_json_api_request_as_blog()`.
 * The blog token is long-lived (no refresh dance required) and Jetpack's
 * central relay has already solved the redirect-URI problem for the connect
 * flow — all of that lives in Jetpack itself, not here.
 *
 * The `/wpcom/v2/x402/*` endpoints don't exist on WordPress.com yet — calls
 * will 404 until the service ships. This class proves the plumbing.
 */
final class JetpackFacilitator implements Facilitator {

	private const BASE_PATH   = '/x402';
	private const API_VERSION = '2';
	private const API_BASE    = 'wpcom';

	public function describe(): FacilitatorProfile {
		// Until the wpcom service advertises its supported networks, mirror
		// the test profile (Base Sepolia / USDC). Will flip to a live profile
		// once the service ships and we know what it actually supports.
		return FacilitatorProfile::for_test();
	}

	public function verify( array $requirements, array $payload ): array {
		$response = $this->call(
			'/verify',
			array(
				'paymentRequirements' => $requirements,
				'paymentPayload'      => $payload,
			)
		);
		return array(
			'isValid' => (bool) ( $response['body']['isValid'] ?? false ),
			'error'   => $response['error'] ?? ( $response['body']['invalidReason'] ?? null ),
			'raw'     => $response['body'],
		);
	}

	public function settle( array $requirements, array $payload ): array {
		$response = $this->call(
			'/settle',
			array(
				'paymentRequirements' => $requirements,
				'paymentPayload'      => $payload,
			)
		);
		return array(
			'success'     => (bool) ( $response['body']['success'] ?? false ),
			'transaction' => $response['body']['transaction'] ?? null,
			'network'     => $response['body']['network'] ?? null,
			'error'       => $response['error'] ?? ( $response['body']['errorReason'] ?? null ),
			'raw'         => $response['body'],
		);
	}

	public function test_connection(): TestResult {
		$started  = microtime( true );
		$response = $this->call( '/health', null, 'GET' );
		$elapsed  = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( null !== $response['error'] ) {
			return new TestResult(
				ok: false,
				error: $response['error'],
				http_code: $response['http_code'],
				duration_ms: $elapsed,
			);
		}
		return new TestResult(
			ok: true,
			http_code: $response['http_code'] ?? 200,
			duration_ms: $elapsed,
		);
	}

	/**
	 * Dispatch an authenticated call through Jetpack Connection.
	 *
	 * @param string                   $sub_path Path relative to BASE_PATH (e.g. "/verify").
	 * @param array<string,mixed>|null $body     JSON body (null for GET).
	 * @param string                   $method   HTTP method.
	 *
	 * @return array{body:array<string,mixed>,error:?string,http_code:?int}
	 */
	private function call( string $sub_path, ?array $body, string $method = 'POST' ): array {
		$full_path = self::BASE_PATH . '/' . ltrim( $sub_path, '/' );
		$args      = array(
			'method'  => $method,
			'headers' => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
		);
		$encoded   = null !== $body ? wp_json_encode( $body ) : null;

		$dev_url = (string) getenv( 'SIMPLE_X402_JETPACK_DEV_URL' );

		if ( '' !== $dev_url ) {
			// Dev override: skip Jetpack signing; hit a local stub directly.
			// Set via env var when booting the PHP server (e.g. wp-now). See
			// LOCAL_DEV.md. Never set this in production — it bypasses WP.com
			// auth for the entire facilitator surface.
			$dev_args = $args;
			if ( null !== $encoded ) {
				$dev_args['body'] = $encoded;
			}
			$raw = wp_remote_request(
				rtrim( $dev_url, '/' ) . '/' . self::API_BASE . '/v' . self::API_VERSION . $full_path,
				$dev_args
			);
		} else {
			$raw = \Automattic\Jetpack\Connection\Client::wpcom_json_api_request_as_blog(
				$full_path,
				self::API_VERSION,
				$args,
				$encoded,
				self::API_BASE
			);
		}
		if ( is_wp_error( $raw ) ) {
			return array(
				'body'      => array(),
				'error'     => $raw->get_error_message(),
				'http_code' => null,
			);
		}
		$code   = wp_remote_retrieve_response_code( $raw );
		$parsed = json_decode( (string) wp_remote_retrieve_body( $raw ), true );
		if ( ! is_array( $parsed ) ) {
			$parsed = array();
		}
		if ( $code < 200 || $code >= 300 ) {
			return array(
				'body'      => $parsed,
				'error'     => self::format_error( $parsed, $code ),
				'http_code' => $code,
			);
		}
		return array(
			'body'      => $parsed,
			'error'     => null,
			'http_code' => $code,
		);
	}

	/**
	 * WordPress.com serves two distinct error shapes, and callers have to
	 * handle both:
	 *   - OAuth-compliant token errors: `{error, error_description}` (HTTP 401)
	 *   - wpcom-flavoured scope errors: `{code, message}` (HTTP 403)
	 * Either may surface on protected routes; anything else falls back to
	 * "HTTP $n" so we still report something actionable.
	 *
	 * @param array<string,mixed> $parsed
	 */
	private static function format_error( array $parsed, int $http_code ): string {
		if ( isset( $parsed['error'] ) ) {
			$code    = (string) $parsed['error'];
			$message = isset( $parsed['error_description'] ) ? (string) $parsed['error_description'] : '';
			return '' !== $message ? "{$code}: {$message}" : $code;
		}
		if ( isset( $parsed['message'] ) ) {
			return (string) $parsed['message'];
		}
		return "HTTP {$http_code}";
	}
}
