<?php
declare(strict_types=1);

namespace SimpleX402\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimpleX402\Services\FacilitatorProfile;

final class FacilitatorProfileTest extends TestCase {

	public function test_test_profile_matches_x402_org_base_sepolia(): void {
		$profile = FacilitatorProfile::for_test();

		$this->assertSame( 'test', $profile->mode );
		$this->assertSame( 'base-sepolia', $profile->network );
		$this->assertSame( '0x036CbD53842c5426634e7929541eC2318f3dCF7e', $profile->asset );
		$this->assertSame( 6, $profile->asset_decimals );
		$this->assertSame( 'https://x402.org/facilitator/', $profile->facilitator_url );
		$this->assertSame( 'USDC', $profile->eip712_name );
		$this->assertSame( '2', $profile->eip712_version );
		$this->assertSame( '', $profile->api_key );
	}

	public function test_live_profile_matches_base_mainnet(): void {
		$profile = FacilitatorProfile::for_live( '', 'secret-key' );

		$this->assertSame( 'live', $profile->mode );
		$this->assertSame( 'base', $profile->network );
		$this->assertSame( '0x833589fCD6eDb6E08f4c7C32D4f71b54bdA02913', $profile->asset );
		$this->assertSame( 6, $profile->asset_decimals );
		$this->assertSame( FacilitatorProfile::LIVE_FACILITATOR_URL_DEFAULT, $profile->facilitator_url );
		// Base mainnet USDC's EIP-712 domain name — see note in FacilitatorProfile::for_live().
		$this->assertSame( 'USD Coin', $profile->eip712_name );
		$this->assertSame( '2', $profile->eip712_version );
		$this->assertSame( 'secret-key', $profile->api_key );
	}

	public function test_live_profile_respects_facilitator_url_override(): void {
		$profile = FacilitatorProfile::for_live( 'https://my-own-facilitator.example/', '' );
		$this->assertSame( 'https://my-own-facilitator.example/', $profile->facilitator_url );
	}

	public function test_live_profile_defaults_url_when_override_blank(): void {
		$profile = FacilitatorProfile::for_live();
		$this->assertSame( FacilitatorProfile::LIVE_FACILITATOR_URL_DEFAULT, $profile->facilitator_url );
		$this->assertSame( '', $profile->api_key );
	}
}
