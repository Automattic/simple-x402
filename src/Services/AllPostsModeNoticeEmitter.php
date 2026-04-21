<?php
/**
 * Emits an admin notice when the paywall mode flips to `all-posts`.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Services;

use SimpleX402\Settings\SettingsRepository;

/**
 * Callback for `update_option_simple_x402_settings`. Fires the
 * "every published post is now paywalled" notice exactly when the admin flips
 * paywall_mode from something else to `all-posts` in a single save.
 */
final class AllPostsModeNoticeEmitter {

	public function __construct( private readonly SettingsChangeNotifier $notifier ) {}

	/**
	 * @param mixed $old_value Previously stored option value.
	 * @param mixed $new_value Newly stored option value.
	 */
	public function __invoke( $old_value, $new_value ): void {
		if ( ! is_array( $new_value ) ) {
			return;
		}
		$old_mode = is_array( $old_value ) ? (string) ( $old_value['paywall_mode'] ?? '' ) : '';
		$new_mode = (string) ( $new_value['paywall_mode'] ?? '' );
		if ( SettingsRepository::MODE_ALL_POSTS === $new_mode && SettingsRepository::MODE_ALL_POSTS !== $old_mode ) {
			$this->notifier->notify_mode_switched_to_all_posts();
		}
	}
}
