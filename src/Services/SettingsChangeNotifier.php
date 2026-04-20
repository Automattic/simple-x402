<?php
/**
 * Turns meaningful settings changes into admin-facing notices.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Services;

use SimpleX402\Settings\SettingsRepository;

/**
 * Compares old and new plugin settings and queues `add_settings_error`
 * messages for changes that have non-obvious side effects:
 *
 *  - Renaming the paywall category leaves posts orphaned from the paywall
 *    until they are reassigned to the new category.
 *  - Switching the paywall mode to `all-posts` gates every published post,
 *    which is a large blast radius worth confirming.
 *
 * Other changes (price, wallet address, mode → category) fall back to the
 * default "Settings saved." notice from the Settings API.
 */
final class SettingsChangeNotifier {

	/**
	 * @param array $old Previous option value (may be partial or empty on first save).
	 * @param array $new New option value as sanitised by SettingsRepository.
	 */
	public function notify( array $old, array $new_values ): void {
		$this->notify_category_rename( $old, $new_values );
		$this->notify_all_posts_switch( $old, $new_values );
	}

	private function notify_category_rename( array $old, array $new_values ): void {
		$old_cat = isset( $old['paywall_category'] ) ? (string) $old['paywall_category'] : '';
		$new_cat = isset( $new_values['paywall_category'] ) ? (string) $new_values['paywall_category'] : '';
		if ( '' === $old_cat || '' === $new_cat || $old_cat === $new_cat ) {
			return;
		}
		add_settings_error(
			SettingsRepository::OPTION_NAME,
			'simple_x402_category_renamed',
			sprintf(
				/* translators: 1: old category name, 2: new category name. */
				__( 'Paywall category changed from "%1$s" to "%2$s". The "%2$s" category has been created. Posts still assigned to "%1$s" are no longer paywalled — reassign them to "%2$s" if you want them gated.', 'simple-x402' ),
				$old_cat,
				$new_cat
			),
			'warning'
		);
	}

	private function notify_all_posts_switch( array $old, array $new_values ): void {
		$new_mode = isset( $new_values['paywall_mode'] ) ? (string) $new_values['paywall_mode'] : '';
		if ( SettingsRepository::MODE_ALL_POSTS !== $new_mode ) {
			return;
		}
		$old_mode = isset( $old['paywall_mode'] ) ? (string) $old['paywall_mode'] : '';
		if ( SettingsRepository::MODE_ALL_POSTS === $old_mode ) {
			return;
		}
		add_settings_error(
			SettingsRepository::OPTION_NAME,
			'simple_x402_all_posts_mode',
			__( 'Every published post is now paywalled.', 'simple-x402' ),
			'info'
		);
	}
}
