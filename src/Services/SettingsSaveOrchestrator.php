<?php
/**
 * Coordinates side effects during a settings option save.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Services;

use SimpleX402\Settings\SettingsRepository;

/**
 * Orchestrates the two save-lifecycle hooks the plugin reacts to:
 *
 *  - `on_pre_update()` runs inside `pre_update_option_*`: rejects renames that
 *    would collide with an existing category by reverting the incoming value
 *    to the old one and queueing an error notice.
 *  - `on_update()` runs inside `update_option_*`: either renames the existing
 *    category term in place (preserving post→term associations) or ensures
 *    the new term exists, and emits info notices for mode switches.
 *
 * Keeping the decision tree here — rather than in anonymous closures inside
 * Plugin::boot — lets us unit-test each branch with plain PHPUnit instead of a
 * full WordPress integration.
 */
final class SettingsSaveOrchestrator {

	public function __construct(
		private readonly CategoryRepository $categories,
		private readonly SettingsChangeNotifier $notifier,
	) {}

	/**
	 * Filter callback for `pre_update_option_<OPTION>`.
	 *
	 * @param mixed $value     Incoming sanitised value.
	 * @param mixed $old_value Previously stored value.
	 *
	 * @return mixed The value to persist — may be mutated to revert a collision.
	 */
	public function on_pre_update( $old_value, $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$old_cat = is_array( $old_value ) ? (string) ( $old_value['paywall_category'] ?? '' ) : '';
		$new_cat = (string) ( $value['paywall_category'] ?? '' );
		if ( '' === $old_cat || '' === $new_cat || $old_cat === $new_cat ) {
			return $value;
		}
		if ( term_exists( $new_cat, 'category' ) ) {
			$value['paywall_category'] = $old_cat;
			$this->notifier->notify_rename_collision( $new_cat );
		}
		return $value;
	}

	/**
	 * Action callback for `update_option_<OPTION>`.
	 *
	 * @param mixed $old_value Previously stored value.
	 * @param mixed $new_value Newly stored value (after pre_update filters).
	 */
	public function on_update( $old_value, $new_value ): void {
		if ( ! is_array( $new_value ) ) {
			return;
		}
		$old_arr = is_array( $old_value ) ? $old_value : array();
		$old_cat = (string) ( $old_arr['paywall_category'] ?? '' );
		$new_cat = (string) ( $new_value['paywall_category'] ?? '' );

		if ( '' !== $old_cat && $old_cat !== $new_cat ) {
			if ( $this->categories->rename( $old_cat, $new_cat ) ) {
				$this->notifier->notify_rename_succeeded( $old_cat, $new_cat );
			} else {
				$this->categories->ensure( $new_cat );
			}
		} else {
			$this->categories->ensure( $new_cat );
		}

		if ( SettingsRepository::MODE_ALL_POSTS === (string) ( $new_value['paywall_mode'] ?? '' )
			&& SettingsRepository::MODE_ALL_POSTS !== (string) ( $old_arr['paywall_mode'] ?? '' )
		) {
			$this->notifier->notify_mode_switched_to_all_posts();
		}
	}
}
