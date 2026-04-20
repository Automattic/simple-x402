<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Services\PaymentRequirementsBuilder;

final class PaymentRequirementsBuilderTest extends TestCase {

	public function test_builds_base_sepolia_usdc_requirements(): void {
		$builder = new PaymentRequirementsBuilder();
		$req     = $builder->build(
			'0x1111111111111111111111111111111111111111',
			'0.01',
			'https://example.com/article',
			'Test post'
		);

		$this->assertSame( 'exact', $req['scheme'] );
		$this->assertSame( 'eip155:84532', $req['network'] );
		$this->assertSame( '0x036CbD53842c5426634e7929541eC2318f3dCF7e', $req['asset'] );
		$this->assertSame( '0x1111111111111111111111111111111111111111', $req['payTo'] );
		$this->assertSame( '10000', $req['maxAmountRequired'] ); // 0.01 USDC at 6 decimals.
		$this->assertSame( 'https://example.com/article', $req['resource'] );
		$this->assertSame( 'Test post', $req['description'] );
		$this->assertArrayHasKey( 'maxTimeoutSeconds', $req );
	}

	public function test_price_with_many_decimals_is_truncated_to_6(): void {
		$builder = new PaymentRequirementsBuilder();
		$req     = $builder->build( '0xabc', '0.1234567', 'https://example.com', '' );
		$this->assertSame( '123456', $req['maxAmountRequired'] );
	}

	public function test_whole_dollar_price(): void {
		$builder = new PaymentRequirementsBuilder();
		$req     = $builder->build( '0xabc', '2', 'https://example.com', '' );
		$this->assertSame( '2000000', $req['maxAmountRequired'] );
	}

	public function test_zero_or_negative_price_returns_zero(): void {
		$builder = new PaymentRequirementsBuilder();
		$this->assertSame( '0', $builder->build( '0xabc', '0', 'https://example.com', '' )['maxAmountRequired'] );
		$this->assertSame( '0', $builder->build( '0xabc', '-1', 'https://example.com', '' )['maxAmountRequired'] );
	}
}
