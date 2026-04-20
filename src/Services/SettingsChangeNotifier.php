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
 * Emits `add_settings_error` notices for settings save events that benefit
 * from an explanation beyond the default "Settings saved."
 *
 * Each public method maps to one discrete outcome the orchestration layer
 * detects during a settings save (rename succeeded, collision rejected,
 * mode switched to `all-posts`). Callers decide which to invoke; this class
 * just phrases the messages.
 */
final class SettingsChangeNotifier {

	/**
	 * The paywall category was renamed in place. Post assignments are preserved
	 * because WordPress keys post→term associations on term_id.
	 */
	public function notify_rename_succeeded( string $from, string $to ): void {
		$this->emit(
			'simple_x402_category_renamed',
			'info',
			sprintf(
				/* translators: 1: old category name, 2: new category name. */
				__( 'Paywall category renamed from "%1$s" to "%2$s". Existing posts remain paywalled.', 'simple-x402' ),
				$from,
				$to
			)
		);
	}

	/**
	 * Rename rejected because the target name is already taken by another category.
	 */
	public function notify_rename_collision( string $attempted ): void {
		$this->emit(
			'simple_x402_category_collision',
			'error',
			sprintf(
				/* translators: %s: the name the admin tried to use. */
				__( 'Cannot rename paywall category to "%s" because a category with that name already exists. Pick a different name or delete the existing category first.', 'simple-x402' ),
				$attempted
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

	private function emit( string $code, string $type, string $message ): void {
		add_settings_error( SettingsRepository::OPTION_NAME, $code, $message, $type );
	}
}
