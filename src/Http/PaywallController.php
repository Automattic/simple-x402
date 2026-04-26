<?php
/**
 * Orchestrates the paywall flow on template_redirect.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Http;

use SimpleX402\Facilitator\Facilitator;
use SimpleX402\Facilitator\FacilitatorResolver;
use SimpleX402\Services\GrantStore;
use SimpleX402\Services\PaywallClientProfile;
use SimpleX402\Services\PaymentRequirementsBuilder;
use SimpleX402\Services\PaymentSettlementNotifier;
use SimpleX402\Services\RuleResolver;
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

	public const BYPASS_HOOK = 'simple_x402_bypass_paywall';

	/**
	 * Fires after the paywall builds a {@see PaywallClientProfile} for this request
	 * (non-bypassed path with a resolved facilitator). Filter must return a
	 * PaywallClientProfile instance; other return types are ignored.
	 */
	public const CLIENT_PROFILE_FILTER = 'simple_x402_paywall_client_profile';

	/** Nonce action for {@see self::PROBE_HEADER} — settings probe drops admin bypass when valid. */
	public const PROBE_NONCE_ACTION = 'simple_x402_paywall_probe';

	/** Request header carrying {@see self::PROBE_NONCE_ACTION} from the settings screen self-check. */
	public const PROBE_HEADER = 'X-Simple-X402-Probe';

	/**
	 * Lazily-resolved facilitator client + the builder that wraps its profile.
	 * Deferred so requests that never reach the paywall path don't pay for
	 * filter firing or option reads.
	 */
	private ?Facilitator $facilitator_svc        = null;
	private ?PaymentRequirementsBuilder $builder = null;

	private ?PaymentSettlementNotifier $settlement_notifier;

	/** Set on the paywall enforcement path for Phase B; unused in Phase A beyond {@see self::CLIENT_PROFILE_FILTER}. */
	private ?PaywallClientProfile $client_profile = null;

	public function __construct(
		private readonly RuleResolver $rules,
		private readonly GrantStore $grants,
		private readonly SettingsRepository $settings,
		private readonly FacilitatorResolver $resolver,
		?PaymentSettlementNotifier $settlement_notifier = null,
	) {
		$this->settlement_notifier = $settlement_notifier;
	}

	private function settlement_notifier(): PaymentSettlementNotifier {
		return $this->settlement_notifier ??= new PaymentSettlementNotifier();
	}

	/**
	 * Resolve the active Facilitator from the selected connector, or null if
	 * none selected / resolution failed. The paywall is inert when there is
	 * no facilitator — pick one in Settings → Simple x402 to activate it.
	 */
	private function facilitator(): ?Facilitator {
		if ( null !== $this->facilitator_svc ) {
			return $this->facilitator_svc;
		}
		$id = $this->settings->selected_facilitator_id();
		if ( '' === $id ) {
			return null;
		}
		$resolved = $this->resolver->resolve( $id );
		if ( null === $resolved ) {
			return null;
		}
		$this->facilitator_svc = $resolved;
		return $this->facilitator_svc;
	}

	private function builder( Facilitator $facilitator ): PaymentRequirementsBuilder {
		return $this->builder ??= new PaymentRequirementsBuilder( $facilitator->describe() );
	}

	/**
	 * @param array{
	 *   path:string,
	 *   method:string,
	 *   post_id:int,
	 *   singular?:bool,
	 *   headers:array<string,string>
	 * } $request Request details. `headers` always includes `Accept`, `Sec-Fetch-Mode`, and
	 *              `Sec-Fetch-Dest` when built by {@see \SimpleX402\Plugin::boot()} (empty string if absent).
	 */
	public function handle( array $request ): void {
		$this->client_profile = null;
		$paywall_probe        = $this->valid_paywall_probe_header( $request );
		$rule                 = $this->rules->resolve(
			array(
				'path'          => $request['path'],
				'method'        => $request['method'],
				'post_id'       => $request['post_id'],
				'singular'      => ! empty( $request['singular'] ),
				'paywall_probe' => $paywall_probe,
			)
		);
		if ( null === $rule ) {
			return;
		}

		// Administrators bypass by default so they can preview and manage
		// paywalled content. Extenders can widen or narrow this via the
		// `simple_x402_bypass_paywall` filter (e.g. let post editors through,
		// or force admins to pay for audit reasons). A valid probe header
		// forces the default to "do not bypass" so admins can self-test 402.
		$default_bypass = current_user_can( 'manage_options' );
		if ( $paywall_probe ) {
			$default_bypass = false;
		}
		if ( (bool) apply_filters( self::BYPASS_HOOK, $default_bypass, $request, $rule ) ) {
			return;
		}

		$facilitator = $this->facilitator();
		if ( null === $facilitator ) {
			// No facilitator selected or resolved — paywall is inert.
			return;
		}

		$this->client_profile = $this->filtered_client_profile( $request );

		$wallet_hint = (string) ( $request['headers']['X-Wallet-Address'] ?? '' );
		if ( '' !== $wallet_hint && $this->grants->has_grant( $wallet_hint, $request['path'] ) ) {
			return;
		}

		$requirements = $this->builder( $facilitator )->build(
			$this->settings->resolved_pay_to_address(),
			$rule['price'],
			home_url( $request['path'] ),
			$rule['description']
		);

		$signature_header = (string) ( $request['headers']['Payment-Signature'] ?? '' );
		if ( '' === $signature_header ) {
			$this->respond_402( $requirements, $rule['price'], array( 'error' => 'payment_required' ) );
			return;
		}

		$payload = X402HeaderCodec::decode( $signature_header );
		if ( null === $payload ) {
			$this->respond_402( $requirements, $rule['price'], array( 'error' => 'invalid_signature_header' ) );
			return;
		}

		$verify = $facilitator->verify( $requirements, $payload );
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

		$settle = $facilitator->settle( $requirements, $payload );
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

		$this->settlement_notifier()->notify(
			array(
				'connector_id' => $this->settings->selected_facilitator_id(),
				'post_id'      => $request['post_id'],
				'path'         => $request['path'],
				'transaction'  => (string) ( $settle['transaction'] ?? '' ),
				'network'      => (string) ( $settle['network'] ?? '' ),
				'amount'       => $rule['price'],
				'resource_url' => home_url( $request['path'] ),
				'pay_to'       => (string) ( $requirements['payTo'] ?? '' ),
				'payer_wallet' => $wallet,
			)
		);
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

	/**
	 * @param array{headers:array<string,string>} $request
	 */
	private function valid_paywall_probe_header( array $request ): bool {
		$token = (string) ( $request['headers'][ self::PROBE_HEADER ] ?? '' );
		if ( '' === $token ) {
			return false;
		}
		return (bool) wp_verify_nonce( $token, self::PROBE_NONCE_ACTION );
	}

	/**
	 * @param array{headers:array<string,string>} $request
	 */
	private function filtered_client_profile( array $request ): PaywallClientProfile {
		$h        = $request['headers'];
		$base     = PaywallClientProfile::classify(
			(string) ( $h['User-Agent'] ?? '' ),
			(string) ( $h['Accept'] ?? '' ),
			(string) ( $h['Sec-Fetch-Mode'] ?? '' ),
			(string) ( $h['Sec-Fetch-Dest'] ?? '' ),
			array_key_exists( 'X-Requested-With', $h ) ? (string) $h['X-Requested-With'] : null,
		);
		$filtered = apply_filters( self::CLIENT_PROFILE_FILTER, $base, $request );
		return $filtered instanceof PaywallClientProfile ? $filtered : $base;
	}
}
