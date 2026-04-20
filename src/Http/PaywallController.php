<?php
/**
 * Orchestrates the paywall flow on template_redirect.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Http;

use SimpleX402\Services\GrantStore;
use SimpleX402\Services\PaymentRequirementsBuilder;
use SimpleX402\Services\RuleResolver;
use SimpleX402\Services\X402FacilitatorClient;
use SimpleX402\Services\X402HeaderCodec;
use SimpleX402\Settings\SettingsRepository;

/**
 * Decides whether to serve, verify-then-serve, or reject with 402.
 *
 * The controller does not `echo` or `exit`; it only mutates a response
 * structure on $GLOBALS['__sx402_response']. The Plugin bootstrap is
 * responsible for echoing the body and exiting when `exited` is true.
 * This split keeps the controller unit-testable.
 */
final class PaywallController {

	public function __construct(
		private readonly RuleResolver $rules,
		private readonly PaymentRequirementsBuilder $builder,
		private readonly X402FacilitatorClient $facilitator,
		private readonly GrantStore $grants,
		private readonly SettingsRepository $settings
	) {}

	/**
	 * @param array{path:string,method:string,post_id:int,singular?:bool,headers:array<string,string>} $request Request details.
	 */
	public function handle( array $request ): void {
		$rule = $this->rules->resolve(
			array(
				'path'     => $request['path'],
				'method'   => $request['method'],
				'post_id'  => $request['post_id'],
				'singular' => ! empty( $request['singular'] ),
			)
		);
		if ( null === $rule ) {
			return;
		}

		$wallet_hint = (string) ( $request['headers']['X-Wallet-Address'] ?? '' );
		if ( '' !== $wallet_hint && $this->grants->has_grant( $wallet_hint, $request['path'] ) ) {
			return;
		}

		$requirements = $this->builder->build(
			$this->settings->wallet_address(),
			$rule['price'],
			home_url( $request['path'] ),
			$rule['description']
		);

		$signature_header = (string) ( $request['headers']['PAYMENT-SIGNATURE'] ?? '' );
		if ( '' === $signature_header ) {
			$this->respond_402( $requirements, $rule['price'], array( 'error' => 'payment_required' ) );
			return;
		}

		$payload = X402HeaderCodec::decode( $signature_header );
		if ( null === $payload ) {
			$this->respond_402( $requirements, $rule['price'], array( 'error' => 'invalid_signature_header' ) );
			return;
		}

		$verify = $this->facilitator->verify( $requirements, $payload );
		if ( ! $verify['isValid'] ) {
			$this->respond_402(
				$requirements,
				$rule['price'],
				array(
					'error'  => 'verify_failed',
					'reason' => $verify['error'],
				)
			);
			return;
		}

		$settle = $this->facilitator->settle( $requirements, $payload );
		if ( ! $settle['success'] ) {
			$this->respond_402(
				$requirements,
				$rule['price'],
				array(
					'error'  => 'settle_failed',
					'reason' => $settle['error'],
				)
			);
			return;
		}

		$wallet = $this->extract_wallet( $payload );
		if ( '' === $wallet ) {
			$wallet = $wallet_hint;
		}
		if ( '' !== $wallet ) {
			$this->grants->issue(
				$wallet,
				$request['path'],
				$rule['ttl'],
				array( 'transaction' => $settle['transaction'] )
			);
		}
	}

	/**
	 * Emit a 402 JSON response via the response buffer.
	 *
	 * @param string $price Decimal USDC amount (e.g. "0.01") for clients that expect a human-readable price alongside `requirements.maxAmountRequired`.
	 */
	private function respond_402( array $requirements, string $price, array $body ): void {
		nocache_headers();
		status_header( 402 );
		$GLOBALS['__sx402_response']['headers']['Content-Type']     = 'application/json';
		$GLOBALS['__sx402_response']['headers']['PAYMENT-REQUIRED'] = X402HeaderCodec::encode( $requirements );
		// Use array union (+), not array_merge: keys in $body must not overwrite requirements/price.
		$GLOBALS['__sx402_response']['body']   = wp_json_encode(
			array(
				'requirements' => $requirements,
				'price'        => $price,
			) + $body
		);
		$GLOBALS['__sx402_response']['exited'] = true;
	}

	/**
	 * Pull the paying wallet from a decoded PAYMENT-SIGNATURE payload.
	 */
	private function extract_wallet( array $payload ): string {
		return (string) (
			$payload['payload']['authorization']['from']
			?? $payload['payload']['from']
			?? ''
		);
	}
}
