<?php
/**
 * EIP-6963 EVM wallet provider registration.
 *
 * @package X402Pay
 */

declare(strict_types=1);

namespace X402Pay\Payment\Providers\EvmWallet;

defined( 'ABSPATH' ) || exit;

use X402Pay\Payment\PaymentProviderRegistry;

/**
 * Renders one row per browser-extension wallet that announces itself via the
 * EIP-6963 "Multi Injected Provider Discovery" protocol (MetaMask, Rainbow,
 * Coinbase Wallet extension, Trust, etc.). The PHP side just registers a
 * single slot — all the discovery + per-wallet button rendering happens in
 * `script.js` on the client. The slot expands into 0..N wallet buttons
 * depending on what the visitor has installed.
 *
 * Co-located with `script.js` (browser side) so the provider's PHP +
 * client-side runtime live in one folder.
 */
final class Provider {

	public const PROVIDER_ID = 'evm-wallet';

	/**
	 * Networks `script.js` can actually build an EIP-3009 payload for (its
	 * `NETWORKS` map). On anything else the provider should not render at
	 * all — connectors for non-EVM chains register their own providers.
	 */
	private const SUPPORTED_NETWORKS = array( 'base', 'base-sepolia' );

	public static function register(): void {
		add_filter(
			PaymentProviderRegistry::FILTER,
			array( self::class, 'register_provider' ),
			10,
			2
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $providers Existing provider list.
	 * @param array<string,mixed>            $context   Filter context (requirements, resource_url, request).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function register_provider( array $providers, array $context ): array {
		$network = isset( $context['requirements']['network'] ) && is_string( $context['requirements']['network'] )
			? $context['requirements']['network']
			: '';

		$providers[] = array(
			'id'          => self::PROVIDER_ID,
			'label'       => __( 'Pay with a browser wallet', 'x402-pay' ),
			'script_url'  => plugins_url( 'src/Payment/Providers/EvmWallet/script.js', X402_PAY_FILE ),
			'is_eligible' => in_array( $network, self::SUPPORTED_NETWORKS, true ),
		);
		return $providers;
	}
}
