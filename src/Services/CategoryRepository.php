<?php
/**
 * Taxonomy side-effects for the paywall category.
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Services;

/**
 * Manages the WordPress `category` term used by the paywall.
 *
 * Two idempotent operations:
 *  - `ensure()`: create the term if it does not already exist.
 *  - `rename()`: change the name on an existing term, preserving `term_id` so
 *                post→term associations carry over.
 */
final class CategoryRepository {

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

	/**
	 * Rename a category term. Returns true if the rename happened, false if
	 * `from` doesn't exist or either side is empty.
	 */
	public function rename( string $from, string $to ): bool {
		$from = trim( $from );
		$to   = trim( $to );
		if ( '' === $from || '' === $to ) {
			return false;
		}
		$existing = term_exists( $from, 'category' );
		if ( ! is_array( $existing ) || ! isset( $existing['term_id'] ) ) {
			return false;
		}
		$result = wp_update_term( (int) $existing['term_id'], 'category', array( 'name' => $to ) );
		return ! is_wp_error( $result );
	}
}
