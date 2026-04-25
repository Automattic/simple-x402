<?php
/**
 * Jetpack Connection signals for Simple x402 autopick and managed pay-to.
 *
 * @package SimpleX402\Jetpack
 */

declare(strict_types=1);

namespace SimpleX402\Jetpack;

/**
 * Registers filters consumed by the main Simple x402 plugin (no hard dependency
 * on its PHP classes — hook names are mirrored as string literals).
 */
final class JetpackSiteState {

	private const MANAGED_POOL_PAY_TO       = 'simple_x402_managed_pool_pay_to';
	private const IS_JETPACK_SITE_CONNECTED = 'simple_x402_is_jetpack_site_connected';

	private const JETPACK_MANAGER = '\\Automattic\\Jetpack\\Connection\\Manager';

	public static function register(): void {
		add_filter( self::MANAGED_POOL_PAY_TO, array( self::class, 'filter_managed_pool_pay_to' ), 10, 2 );
		add_filter( self::IS_JETPACK_SITE_CONNECTED, array( self::class, 'filter_is_jetpack_connected' ), 10, 1 );
	}

	/**
	 * @param string $existing Previous filter return.
	 * @param string $connector_id Active connector slug.
	 */
	public static function filter_managed_pool_pay_to( string $existing, string $connector_id ): string {
		if ( '' !== $existing || ConnectorRegistrar::ID !== $connector_id ) {
			return $existing;
		}
		$addr = (string) getenv( 'SIMPLE_X402_WPCOM_POOL_ADDRESS' );
		return $addr;
	}

	public static function filter_is_jetpack_connected( bool $previous ): bool {
		if ( $previous ) {
			return true;
		}
		$class = self::JETPACK_MANAGER;
		if ( ! class_exists( $class ) ) {
			return false;
		}
		$manager = new $class();
		return method_exists( $manager, 'is_connected' ) && $manager->is_connected();
	}
}
