<?php
/**
 * Admin-facing notices for plugin settings changes.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Services;

use SimpleX402\Settings\SettingsRepository;

/**
 * Emits `add_settings_error` notices for settings events that benefit from an
 * explanation beyond the default "Settings saved."
 */
final class SettingsChangeNotifier {

	/**
	 * The stored paywall category was deleted outside the settings page. The
	 * guard has already reset the setting to the default so gating keeps
	 * working — this notice explains why.
	 */
	public function notify_paywall_category_deleted( string $name ): void {
		$this->emit(
			'simple_x402_category_deleted',
			'warning',
			sprintf(
				/* translators: %s: deleted paywall category name. */
				__( 'The paywall category "%s" was deleted. Simple x402 has switched to the default paywall category so gating keeps working; update your paywall category in Settings → Simple x402 if you want a different one.', 'simple-x402' ),
				$name
			)
		);
	}

	/**
	 * Mode was switched to `all-posts` — every published post is now gated.
	 */
	public function notify_mode_switched_to_all_posts(): void {
		$this->emit(
			'simple_x402_all_posts_mode',
			'info',
			__( 'Every published post is now paywalled.', 'simple-x402' )
		);
	}

	/**
	 * The admin tried to switch to live mode while the live block is missing
	 * one or more required fields. The mode has been reverted to test.
	 *
	 * @param string[] $missing Human-readable phrases naming each missing requirement.
	 */
	public function notify_live_mode_incomplete( array $missing ): void {
		$this->emit(
			'simple_x402_live_mode_incomplete',
			'error',
			sprintf(
				/* translators: %s: comma-separated list of missing requirements. */
				__( 'Live mode needs %s before it can be enabled. The plugin will keep using test mode.', 'simple-x402' ),
				implode( ', ', $missing )
			)
		);
	}

	private function emit( string $code, string $type, string $message ): void {
		add_settings_error( SettingsRepository::OPTION_NAME, $code, $message, $type );
	}
}
