<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Services\FacilitatorHooks;
use SimpleX402\Services\PaymentSettlementNotifier;

final class PaymentSettlementNotifierTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['__sx402_actions']  = array();
		$GLOBALS['__sx402_filters']  = array();
		$GLOBALS['__sx402_http']    = null;
	}

	public function test_each_notify_fires_action_even_when_transaction_repeated(): void {
		$hits = 0;
		add_action(
			FacilitatorHooks::PAYMENT_SETTLED,
			static function () use ( &$hits ): void {
				++$hits;
			}
		);
		$ctx = array(
			'transaction' => '0xabc',
			'post_id'      => 1,
			'amount'       => '0.01',
		);
		$n = new PaymentSettlementNotifier();
		$n->notify( $ctx );
		$n->notify( $ctx );
		$this->assertSame( 2, $hits );
	}
}
