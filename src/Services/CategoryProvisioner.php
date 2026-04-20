<?php
/**
 * Ensures a WordPress `category` term exists.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Services;

/**
 * Idempotently creates a category term.
 *
 * Used on plugin activation to seed the default term, and on settings save
 * to auto-create a renamed paywall category so the admin does not have to
 * visit the taxonomy admin screen separately.
 */
final class CategoryProvisioner {

	/**
	 * Ensure a category term with the given name exists. No-op on empty input
	 * or when the term already exists.
	 */
	public function ensure( string $term ): void {
		$term = trim( $term );
		if ( '' === $term ) {
			return;
		}
		if ( ! term_exists( $term, 'category' ) ) {
			wp_insert_term( $term, 'category' );
		}
	}
}
