<?php
/**
 * Hook names shared across paywall, settings, and settlement reporting.
 *
 * @package X402Pay
 */

declare(strict_types=1);

namespace X402Pay\Services;

/**
 * Centralises filter/action slugs so the main plugin and companions stay aligned.
 */
final class FacilitatorHooks {

	public const MANAGED_POOL_PAY_TO = 'x402_pay_managed_pool_pay_to';

	public const PAYMENT_SETTLED = 'x402_pay_payment_settled';

	public const LEDGER_REPORT_URL = 'x402_pay_ledger_report_url';

	/**
	 * Per-connector admin UI strings + validation rules. Filter signature:
	 * `apply_filters( CONNECTOR_ADMIN_META, array $meta, string $connector_id )`.
	 * Connectors that need API key inputs hook this to provide intro copy,
	 * docs links, placeholders, regex patterns, and error messages — keeping
	 * connector-specific text out of the generic admin React app.
	 */
	public const CONNECTOR_ADMIN_META = 'x402_pay_connector_admin_meta';

	/**
	 * Per-connector pay-to address validation. Filter signature:
	 * `apply_filters( VALID_PAY_TO_ADDRESS, bool $valid, string $address, string $connector_id )`.
	 * Runs after the built-in check (connector `walletPattern` admin meta,
	 * falling back to the EVM regex) so connectors for non-EVM chains can
	 * accept their own address formats — or veto one the pattern allowed.
	 */
	public const VALID_PAY_TO_ADDRESS = 'x402_pay_valid_pay_to_address';
}
