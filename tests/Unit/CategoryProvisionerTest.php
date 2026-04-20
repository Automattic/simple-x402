<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Services\CategoryProvisioner;

final class CategoryProvisionerTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_existing_terms'] = array();
		$GLOBALS['__sx402_inserted_terms'] = array();
	}

	public function test_creates_category_when_missing(): void {
		( new CategoryProvisioner() )->ensure( 'Premium' );
		$this->assertSame(
			array( array( 'Premium', 'category' ) ),
			$GLOBALS['__sx402_inserted_terms']
		);
	}

	public function test_no_op_when_term_already_exists(): void {
		$GLOBALS['__sx402_existing_terms'] = array( array( 'Premium', 'category' ) );
		( new CategoryProvisioner() )->ensure( 'Premium' );
		$this->assertSame( array(), $GLOBALS['__sx402_inserted_terms'] );
	}

	public function test_no_op_on_empty_string(): void {
		( new CategoryProvisioner() )->ensure( '' );
		$this->assertSame( array(), $GLOBALS['__sx402_inserted_terms'] );
	}

	public function test_no_op_on_whitespace(): void {
		( new CategoryProvisioner() )->ensure( '   ' );
		$this->assertSame( array(), $GLOBALS['__sx402_inserted_terms'] );
	}
}
