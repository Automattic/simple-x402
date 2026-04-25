<?php
/**
 * Emits hooks (and optional HTTP) after a successful on-chain settle.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Services;

/**
 * Fires {@see FacilitatorHooks::PAYMENT_SETTLED} and optionally POSTs JSON to a
 * filterable ledger URL. De-duplicates by transaction hash so retries do not
 * double-count.
 */
final class PaymentSettlementNotifier {

	private const TXN_DEDUP_TTL = 172800;

	/**
	 * @param array<string,mixed> $context post_id, path, amount, transaction, network, connector_id, resource_url, …
	 */
	public function notify( array $context ): void {
		$txn = (string) ( $context['transaction'] ?? '' );
		if ( '' !== $txn ) {
			$key = 'sx402_ledger_' . md5( $txn );
			if ( get_transient( $key ) ) {
				return;
			}
			set_transient( $key, '1', self::TXN_DEDUP_TTL );
		}

		do_action( FacilitatorHooks::PAYMENT_SETTLED, $context );

		$url = (string) apply_filters( FacilitatorHooks::LEDGER_REPORT_URL, '', $context );
		if ( '' === $url ) {
			return;
		}

		wp_remote_post(
			$url,
			array(
				'timeout'  => 5,
				'blocking' => false,
				'headers'  => array( 'Content-Type' => 'application/json' ),
				'body'     => wp_json_encode( $context ),
			)
		);
	}
}
