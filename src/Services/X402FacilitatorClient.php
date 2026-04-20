<?php
/**
 * HTTP client for the public x402.org facilitator.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Services;

/**
 * Posts PaymentRequirements + PaymentPayload bodies to the x402.org
 * facilitator's /verify and /settle endpoints using wp_remote_post.
 *
 * No auth, no transport customisation — x402.org is a public, unauthenticated
 * facilitator on Base Sepolia.
 */
final class X402FacilitatorClient {

	private const BASE_URL = 'https://x402.org/facilitator/';
	private const TIMEOUT  = 25;

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
		$raw = wp_remote_post(
			self::BASE_URL . ltrim( $endpoint, '/' ),
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
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
