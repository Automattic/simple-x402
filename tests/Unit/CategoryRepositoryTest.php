<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Services\CategoryRepository;
use SimpleX402\Settings\SettingsRepository;

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

	// ensure_default_term_id()

	public function test_ensure_default_term_id_returns_existing_id(): void {
		$GLOBALS['__sx402_existing_terms'] = array(
			array( 'term_id' => 9, 'name' => SettingsRepository::DEFAULT_CATEGORY, 'taxonomy' => 'category' ),
		);
		$id = ( new CategoryRepository() )->ensure_default_term_id();
		$this->assertSame( 9, $id );
		$this->assertSame( array(), $GLOBALS['__sx402_inserted_terms'] );
	}

	public function test_ensure_default_term_id_creates_when_missing(): void {
		$id = ( new CategoryRepository() )->ensure_default_term_id();
		$this->assertGreaterThan( 0, $id );
		$this->assertCount( 1, $GLOBALS['__sx402_inserted_terms'] );
		$this->assertSame(
			SettingsRepository::DEFAULT_CATEGORY,
			$GLOBALS['__sx402_inserted_terms'][0]['name']
		);
	}
}
