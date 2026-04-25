<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Services\FacilitatorHooks;
use SimpleX402\Services\PaymentSettlementNotifier;

final class PaymentSettlementNotifierTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_actions']    = array();
		$GLOBALS['__sx402_filters']    = array();
		$GLOBALS['__sx402_transients'] = array();
		$GLOBALS['__sx402_http']       = null;
	}

	public function test_fires_settled_action_once_per_transaction(): void {
		$hits = 0;
		add_action(
			FacilitatorHooks::PAYMENT_SETTLED,
			static function () use ( &$hits ): void {
				++$hits;
			}
		);
		$ctx = array(
			'transaction' => '0xabc',
			'post_id'     => 1,
			'amount'      => '0.01',
		);
		( new PaymentSettlementNotifier() )->notify( $ctx );
		( new PaymentSettlementNotifier() )->notify( $ctx );
		$this->assertSame( 1, $hits );
	}

	public function test_empty_transaction_allows_multiple_notifications(): void {
		$hits = 0;
		add_action(
			FacilitatorHooks::PAYMENT_SETTLED,
			static function () use ( &$hits ): void {
				++$hits;
			}
		);
		$n = new PaymentSettlementNotifier();
		$n->notify( array( 'post_id' => 1 ) );
		$n->notify( array( 'post_id' => 1 ) );
		$this->assertSame( 2, $hits );
	}
}
