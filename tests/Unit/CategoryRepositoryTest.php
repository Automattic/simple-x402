<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Services\CategoryRepository;

final class CategoryRepositoryTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_existing_terms'] = array();
		$GLOBALS['__sx402_inserted_terms'] = array();
	}

	// ensure()

	public function test_ensure_creates_category_when_missing(): void {
		( new CategoryRepository() )->ensure( 'Premium' );
		$this->assertCount( 1, $GLOBALS['__sx402_inserted_terms'] );
		$this->assertSame( 'Premium', $GLOBALS['__sx402_inserted_terms'][0]['name'] );
		$this->assertSame( 'category', $GLOBALS['__sx402_inserted_terms'][0]['taxonomy'] );
	}

	public function test_ensure_is_noop_when_term_exists(): void {
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 1, 'name' => 'Premium', 'taxonomy' => 'category' ),
		);
		( new CategoryRepository() )->ensure( 'Premium' );
		$this->assertSame( array(), $GLOBALS['__sx402_inserted_terms'] );
	}

	public function test_ensure_is_noop_on_empty_string(): void {
		( new CategoryRepository() )->ensure( '' );
		$this->assertSame( array(), $GLOBALS['__sx402_inserted_terms'] );
	}

	public function test_ensure_is_noop_on_whitespace(): void {
		( new CategoryRepository() )->ensure( '   ' );
		$this->assertSame( array(), $GLOBALS['__sx402_inserted_terms'] );
	}

	// rename()

	public function test_rename_updates_existing_term_and_returns_true(): void {
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 1, 'name' => 'paywall', 'taxonomy' => 'category' ),
		);
		$result = ( new CategoryRepository() )->rename( 'paywall', 'Premium' );
		$this->assertTrue( $result );
		// Same term_id, renamed — posts keyed on term_id stay attached.
		$this->assertSame(
			array(
				array( 'term_id' => 1, 'name' => 'Premium', 'taxonomy' => 'category' ),
			),
			$GLOBALS['__sx402_existing_terms']
		);
	}

	public function test_rename_returns_false_when_from_term_does_not_exist(): void {
		$result = ( new CategoryRepository() )->rename( 'paywall', 'Premium' );
		$this->assertFalse( $result );
	}

	public function test_rename_returns_false_when_from_is_empty(): void {
		$result = ( new CategoryRepository() )->rename( '', 'Premium' );
		$this->assertFalse( $result );
	}

	public function test_rename_returns_false_when_to_is_empty(): void {
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 1, 'name' => 'paywall', 'taxonomy' => 'category' ),
		);
		$result = ( new CategoryRepository() )->rename( 'paywall', '' );
		$this->assertFalse( $result );
	}
}
