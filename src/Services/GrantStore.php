<?php
/**
 * Short-lived access grants backed by WordPress transients.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Services;

/**
 * Remembers "wallet X has paid for path Y" for a rule-specified TTL.
 *
 * Keyed by sha256(lowercase_wallet | path). Stateless for clients: any
 * request arriving with the same wallet in `X-Wallet-Address` or in a
 * PAYMENT-SIGNATURE authorisation within the TTL is served for free.
 */
final class GrantStore {

	private const PREFIX = 'sx402_grant_';

	/**
	 * Is there a live grant for this wallet+path pair?
	 */
	public function has_grant( string $wallet, string $path ): bool {
		$key = $this->key( $wallet, $path );
		if ( null === $key ) {
			return false;
		}
		return false !== get_transient( $key );
	}

	/**
	 * Issue a new grant.
	 *
	 * @param string $wallet Wallet address (case-insensitive).
	 * @param string $path   Request path the grant applies to.
	 * @param int    $ttl    Lifetime in seconds; non-positive is a no-op.
	 * @param array  $meta   Free-form metadata to persist (e.g. tx hash).
	 */
	public function issue( string $wallet, string $path, int $ttl, array $meta ): void {
		$key = $this->key( $wallet, $path );
		if ( null === $key || $ttl <= 0 ) {
			return;
		}
		set_transient( $key, $meta + array( 'issued_at' => time() ), $ttl );
	}

	/**
	 * Compute the transient key, or null if the wallet is empty.
	 */
	private function key( string $wallet, string $path ): ?string {
		$wallet = strtolower( trim( $wallet ) );
		if ( '' === $wallet ) {
			return null;
		}
		return self::PREFIX . hash( 'sha256', $wallet . '|' . $path );
	}
}
