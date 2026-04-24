<?php
/**
 * Result of Facilitator::test_connection().
 *
 * @package SimpleX402
 */

declare(strict_types=1);

namespace SimpleX402\Facilitator;

/**
 * Outcome of a facilitator health probe — "can we reach it at all?" — plus a
 * best-effort diagnostic for the admin UI when the answer is no.
 */
final class TestResult {

	public function __construct(
		public readonly bool $ok,
		public readonly ?string $error = null,
		public readonly ?int $http_code = null,
		public readonly ?int $duration_ms = null,
	) {}
}
