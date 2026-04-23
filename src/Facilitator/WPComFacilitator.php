<?php
/**
 * Facilitator client that calls WordPress.com's x402 service.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Facilitator;

use Automattic\Connectors\OAuth2\Client as OAuth2Client;
use SimpleX402\Services\FacilitatorProfile;

/**
 * Proxies verify/settle/health through public-api.wordpress.com, authenticated
 * with the OAuth2 bearer token obtained via `automattic/connectors-oauth2`.
 *
 * The `/wpcom/v2/x402/*` endpoints don't exist on WordPress.com yet — this
 * class's HTTP calls will 404 until the service ships. What's being proven
 * here is the client-side plumbing: Connectors-API registration → OAuth2
 * token storage → bearer-authenticated calls from our Facilitator pipeline.
 */
final class WPComFacilitator implements Facilitator {

	private const BASE_URL      = 'https://public-api.wordpress.com/wpcom/v2/x402/';
	private const TIMEOUT       = 25;
	private const PROBE_TIMEOUT = 10;

	public function __construct( private readonly OAuth2Client $oauth ) {}

	public function describe(): FacilitatorProfile {
		// Until we know what networks WordPress.com's service actually accepts,
		// advertise the same shape as x402.org — Base Sepolia / USDC. Good
		// enough to keep PaymentRequirementsBuilder happy during the prototype.
		return FacilitatorProfile::for_test();
	}

	public function verify( array $requirements, array $payload ): array {
		$response = $this->post(
			'verify',
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
		$response = $this->post(
			'settle',
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
		$started = microtime( true );
		$raw     = wp_remote_head(
			self::BASE_URL,
			array(
				'timeout' => self::PROBE_TIMEOUT,
				'headers' => $this->headers(),
			)
		);
		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $raw ) ) {
			return new TestResult(
				ok: false,
				error: $raw->get_error_message(),
				duration_ms: $elapsed,
			);
		}
		$code = wp_remote_retrieve_response_code( $raw );
		if ( 0 === $code || $code >= 500 ) {
			return new TestResult(
				ok: false,
				error: 0 === $code ? 'No response' : "HTTP {$code}",
				http_code: $code,
				duration_ms: $elapsed,
			);
		}
		return new TestResult(
			ok: true,
			http_code: $code,
			duration_ms: $elapsed,
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function headers(): array {
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);
		$token = $this->oauth->bearer_token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		return $headers;
	}

	/**
	 * @param string              $endpoint Endpoint path ("verify", "settle", …).
	 * @param array<string,mixed> $body     JSON body.
	 *
	 * @return array{body:array<string,mixed>,error:?string}
	 */
	private function post( string $endpoint, array $body ): array {
		$raw = wp_remote_post(
			self::BASE_URL . ltrim( $endpoint, '/' ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $this->headers(),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $raw ) ) {
			return array(
				'body'  => array(),
				'error' => $raw->get_error_message(),
			);
		}
		$code   = wp_remote_retrieve_response_code( $raw );
		$parsed = json_decode( (string) wp_remote_retrieve_body( $raw ), true );
		if ( ! is_array( $parsed ) ) {
			$parsed = array();
		}
		if ( $code < 200 || $code >= 300 ) {
			return array(
				'body'  => $parsed,
				'error' => $parsed['error'] ?? "HTTP {$code}",
			);
		}
		return array(
			'body'  => $parsed,
			'error' => null,
		);
	}
}
