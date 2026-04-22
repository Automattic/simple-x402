<?php
/**
 * HTTP client for an x402 facilitator.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Services;

/**
 * Posts PaymentRequirements + PaymentPayload bodies to a facilitator's
 * /verify and /settle endpoints using wp_remote_post.
 *
 * Endpoint URL and optional bearer authorization come from the injected
 * FacilitatorProfile. The public x402.org facilitator is used in test mode
 * (no auth); live mode typically targets a commercial facilitator (e.g.
 * Coinbase CDP) that requires an API key.
 */
final class X402FacilitatorClient {

	private const TIMEOUT = 25;

	public function __construct( private readonly FacilitatorProfile $profile ) {}

	/**
	 * Verify a payment payload against requirements.
	 *
	 * @param array $requirements PaymentRequirements.
	 * @param array $payload      PaymentPayload extracted from PAYMENT-SIGNATURE.
	 *
	 * @return array{isValid:bool,error:?string,raw:array}
	 */
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

	/**
	 * Settle a verified payment on-chain via the facilitator.
	 *
	 * @param array $requirements PaymentRequirements.
	 * @param array $payload      PaymentPayload.
	 *
	 * @return array{success:bool,transaction:?string,network:?string,error:?string,raw:array}
	 */
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

	/**
	 * POST JSON to a facilitator endpoint.
	 *
	 * @param string $endpoint Endpoint path (e.g. "verify", "settle").
	 * @param array  $body     Request body.
	 *
	 * @return array{body:array,error:?string}
	 */
	private function post( string $endpoint, array $body ): array {
		$headers = array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		);
		if ( '' !== $this->profile->api_key ) {
			$headers['Authorization'] = 'Bearer ' . $this->profile->api_key;
		}

		$base = rtrim( $this->profile->facilitator_url, '/' ) . '/';
		$raw  = wp_remote_post(
			$base . ltrim( $endpoint, '/' ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => $headers,
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $raw ) ) {
			return array(
				'body'  => array(),
				'error' => $raw->message,
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
