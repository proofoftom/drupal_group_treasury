<?php

namespace Drupal\Tests\group_treasury\Unit;

use Drupal\group_treasury\Service\TreasuryAccessibilityChecker;
use Drupal\safe_smart_accounts\Entity\SafeAccountInterface;
use Drupal\safe_smart_accounts\Service\SafeApiService;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the TreasuryAccessibilityChecker service.
 *
 * @coversDefaultClass \Drupal\group_treasury\Service\TreasuryAccessibilityChecker
 * @group group_treasury
 */
class TreasuryAccessibilityCheckerTest extends UnitTestCase {

  /**
   * The mocked Safe API service.
   *
   * @var \Drupal\safe_smart_accounts\Service\SafeApiService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $safeApiService;

  /**
   * The service under test.
   *
   * @var \Drupal\group_treasury\Service\TreasuryAccessibilityChecker
   */
  protected $accessibilityChecker;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->safeApiService = $this->createMock(SafeApiService::class);
    $this->accessibilityChecker = new TreasuryAccessibilityChecker($this->safeApiService);
  }

  /**
   * Tests checkAccessibility() with accessible Safe.
   *
   * @covers ::checkAccessibility
   */
  public function testCheckAccessibilityWithAccessibleSafe() {
    $safe_account = $this->createMock(SafeAccountInterface::class);
    $safe_account->expects($this->once())
      ->method('getSafeAddress')
      ->willReturn('0x1234567890123456789012345678901234567890');

    $safe_info = [
      'address' => '0x1234567890123456789012345678901234567890',
      'balance' => '1000000000000000000',
      'threshold' => 2,
      'owners' => [
        '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        '0xcccccccccccccccccccccccccccccccccccccccc',
      ],
    ];

    $this->safeApiService->expects($this->once())
      ->method('getSafeInfo')
      ->with('0x1234567890123456789012345678901234567890')
      ->willReturn($safe_info);

    $result = $this->accessibilityChecker->checkAccessibility($safe_account);

    $this->assertTrue($result['accessible']);
    $this->assertSame($safe_info, $result['safe_info']);
    $this->assertSame('1000000000000000000', $result['balance']);
    $this->assertSame(2, $result['threshold']);
    $this->assertCount(3, $result['owners']);
  }

  /**
   * Tests checkAccessibility() with inaccessible Safe.
   *
   * @covers ::checkAccessibility
   */
  public function testCheckAccessibilityWithInaccessibleSafe() {
    $safe_account = $this->createMock(SafeAccountInterface::class);
    $safe_account->expects($this->once())
      ->method('getSafeAddress')
      ->willReturn('0x1234567890123456789012345678901234567890');

    $exception = new \Exception('Safe not found on network', 404);

    $this->safeApiService->expects($this->once())
      ->method('getSafeInfo')
      ->with('0x1234567890123456789012345678901234567890')
      ->willThrowException($exception);

    $result = $this->accessibilityChecker->checkAccessibility($safe_account);

    $this->assertFalse($result['accessible']);
    $this->assertSame('Safe not found on network', $result['error']);
    $this->assertSame(404, $result['error_code']);
    $this->assertArrayHasKey('recovery_options', $result);
    $this->assertContains('reconnect', $result['recovery_options']);
    $this->assertContains('create_new', $result['recovery_options']);
  }

  /**
   * Tests checkAccessibility() with API timeout.
   *
   * @covers ::checkAccessibility
   */
  public function testCheckAccessibilityWithTimeout() {
    $safe_account = $this->createMock(SafeAccountInterface::class);
    $safe_account->expects($this->once())
      ->method('getSafeAddress')
      ->willReturn('0x1234567890123456789012345678901234567890');

    $exception = new \Exception('Connection timeout', 0);

    $this->safeApiService->expects($this->once())
      ->method('getSafeInfo')
      ->willThrowException($exception);

    $result = $this->accessibilityChecker->checkAccessibility($safe_account);

    $this->assertFalse($result['accessible']);
    $this->assertSame('Connection timeout', $result['error']);
  }

  /**
   * Tests verifySafeAddress() with valid address.
   *
   * @covers ::verifySafeAddress
   */
  public function testVerifySafeAddressWithValidAddress() {
    $safe_address = '0x1234567890123456789012345678901234567890';
    $network = 'sepolia';

    $safe_info = [
      'address' => $safe_address,
      'threshold' => 1,
      'owners' => ['0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
    ];

    $this->safeApiService->expects($this->once())
      ->method('getSafeInfo')
      ->with($safe_address)
      ->willReturn($safe_info);

    $result = $this->accessibilityChecker->verifySafeAddress($safe_address, $network);

    $this->assertTrue($result['valid']);
    $this->assertSame($safe_info, $result['safe_info']);
    $this->assertArrayNotHasKey('error', $result);
  }

  /**
   * Tests verifySafeAddress() with invalid address.
   *
   * @covers ::verifySafeAddress
   */
  public function testVerifySafeAddressWithInvalidAddress() {
    $safe_address = '0xinvalidaddress';
    $network = 'sepolia';

    $exception = new \Exception('Invalid address format', 400);

    $this->safeApiService->expects($this->once())
      ->method('getSafeInfo')
      ->with($safe_address)
      ->willThrowException($exception);

    $result = $this->accessibilityChecker->verifySafeAddress($safe_address, $network);

    $this->assertFalse($result['valid']);
    $this->assertSame('Invalid address format', $result['error']);
    $this->assertArrayNotHasKey('safe_info', $result);
  }

  /**
   * Tests checkAccessibility() with Safe having zero balance.
   *
   * @covers ::checkAccessibility
   */
  public function testCheckAccessibilityWithZeroBalance() {
    $safe_account = $this->createMock(SafeAccountInterface::class);
    $safe_account->expects($this->once())
      ->method('getSafeAddress')
      ->willReturn('0x1234567890123456789012345678901234567890');

    $safe_info = [
      'address' => '0x1234567890123456789012345678901234567890',
      'threshold' => 1,
      'owners' => ['0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
    ];

    $this->safeApiService->expects($this->once())
      ->method('getSafeInfo')
      ->willReturn($safe_info);

    $result = $this->accessibilityChecker->checkAccessibility($safe_account);

    $this->assertTrue($result['accessible']);
    $this->assertSame('0', $result['balance']);
  }

}
