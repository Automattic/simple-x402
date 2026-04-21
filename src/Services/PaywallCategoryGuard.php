<?php
/**
 * Keeps the stored paywall category pointing at a live term.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Services;

use SimpleX402\Settings\SettingsRepository;

/**
 * Reacts to WordPress's `delete_term` action: if the term that was just deleted
 * matches the stored `paywall_category`, reset the setting to `DEFAULT_CATEGORY`
 * and re-`ensure()` that default term exists.
 *
 * Hooking the *post*-deletion action rather than `pre_delete_term` is deliberate:
 * if the admin deletes the default paywall term itself, we need the old row to
 * already be gone so `ensure()` can create a fresh replacement without
 * colliding on slug.
 */
final class PaywallCategoryGuard {

	public function __construct(
		private readonly SettingsRepository $settings,
		private readonly CategoryRepository $categories,
		private readonly SettingsChangeNotifier $notifier,
	) {}

	/**
	 * Callback for the `delete_term` action.
	 *
	 * @param int    $term_id      Deleted term's ID (unused — the object carries everything we need).
	 * @param int    $tt_id        Deleted term taxonomy ID (unused).
	 * @param string $taxonomy     Taxonomy slug.
	 * @param mixed  $deleted_term The deleted term object (WP_Term in production).
	 */
	public function __invoke( int $term_id, int $tt_id, string $taxonomy, $deleted_term ): void {
		if ( 'category' !== $taxonomy ) {
			return;
		}
		if ( ! is_object( $deleted_term ) ) {
			return;
		}
		$name = isset( $deleted_term->name ) ? (string) $deleted_term->name : '';
		if ( '' === $name || $name !== $this->settings->paywall_category() ) {
			return;
		}

		$this->settings->set_paywall_category( SettingsRepository::DEFAULT_CATEGORY );
		$this->categories->ensure( SettingsRepository::DEFAULT_CATEGORY );
		$this->notifier->notify_paywall_category_deleted( $name );
	}
}
