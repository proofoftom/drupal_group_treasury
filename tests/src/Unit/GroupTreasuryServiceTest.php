<?php

namespace Drupal\Tests\group_treasury\Unit;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group\Entity\GroupRelationshipInterface;
use Drupal\group_treasury\Service\GroupTreasuryService;
use Drupal\safe_smart_accounts\Entity\SafeAccountInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the GroupTreasuryService.
 *
 * @coversDefaultClass \Drupal\group_treasury\Service\GroupTreasuryService
 * @group group_treasury
 */
class GroupTreasuryServiceTest extends UnitTestCase {

  /**
   * The mocked entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $entityTypeManager;

  /**
   * The mocked cache tags invalidator.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $cacheTagsInvalidator;

  /**
   * The service under test.
   *
   * @var \Drupal\group_treasury\Service\GroupTreasuryService
   */
  protected $treasuryService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->cacheTagsInvalidator = $this->createMock(CacheTagsInvalidatorInterface::class);

    $this->treasuryService = new GroupTreasuryService(
      $this->entityTypeManager,
      $this->cacheTagsInvalidator
    );
  }

  /**
   * Tests getTreasury() with no treasury.
   *
   * @covers ::getTreasury
   */
  public function testGetTreasuryWithNoTreasury() {
    $group = $this->createMock(GroupInterface::class);
    $group->expects($this->once())
      ->method('getRelationships')
      ->with('group_safe_account:safe_account')
      ->willReturn([]);

    $result = $this->treasuryService->getTreasury($group);
    $this->assertNull($result);
  }

  /**
   * Tests getTreasury() with an existing treasury.
   *
   * @covers ::getTreasury
   */
  public function testGetTreasuryWithExistingTreasury() {
    $safe_account = $this->createMock(SafeAccountInterface::class);

    $relationship = $this->createMock(GroupRelationshipInterface::class);
    $relationship->expects($this->once())
      ->method('getEntity')
      ->willReturn($safe_account);

    $group = $this->createMock(GroupInterface::class);
    $group->expects($this->once())
      ->method('getRelationships')
      ->with('group_safe_account:safe_account')
      ->willReturn([$relationship]);

    $result = $this->treasuryService->getTreasury($group);
    $this->assertSame($safe_account, $result);
  }

  /**
   * Tests hasTreasury() returns false when no treasury exists.
   *
   * @covers ::hasTreasury
   */
  public function testHasTreasuryReturnsFalseWhenNoTreasury() {
    $group = $this->createMock(GroupInterface::class);
    $group->expects($this->once())
      ->method('getRelationships')
      ->with('group_safe_account:safe_account')
      ->willReturn([]);

    $result = $this->treasuryService->hasTreasury($group);
    $this->assertFalse($result);
  }

  /**
   * Tests hasTreasury() returns true when treasury exists.
   *
   * @covers ::hasTreasury
   */
  public function testHasTreasuryReturnsTrueWhenTreasuryExists() {
    $relationship = $this->createMock(GroupRelationshipInterface::class);

    $group = $this->createMock(GroupInterface::class);
    $group->expects($this->once())
      ->method('getRelationships')
      ->with('group_safe_account:safe_account')
      ->willReturn([$relationship]);

    $result = $this->treasuryService->hasTreasury($group);
    $this->assertTrue($result);
  }

  /**
   * Tests addTreasury() successfully adds treasury.
   *
   * @covers ::addTreasury
   */
  public function testAddTreasurySuccessfully() {
    $safe_account = $this->createMock(SafeAccountInterface::class);

    $group = $this->createMock(GroupInterface::class);
    $group->expects($this->once())
      ->method('getRelationships')
      ->with('group_safe_account:safe_account')
      ->willReturn([]);

    $group->expects($this->once())
      ->method('addRelationship')
      ->with($safe_account, 'group_safe_account:safe_account');

    $group->expects($this->once())
      ->method('getCacheTags')
      ->willReturn(['group:1']);

    $this->cacheTagsInvalidator->expects($this->once())
      ->method('invalidateTags')
      ->with(['group:1']);

    $this->treasuryService->addTreasury($group, $safe_account);
  }

  /**
   * Tests addTreasury() throws exception when treasury already exists.
   *
   * @covers ::addTreasury
   */
  public function testAddTreasuryThrowsExceptionWhenAlreadyExists() {
    $safe_account = $this->createMock(SafeAccountInterface::class);
    $relationship = $this->createMock(GroupRelationshipInterface::class);

    $group = $this->createMock(GroupInterface::class);
    $group->expects($this->once())
      ->method('getRelationships')
      ->with('group_safe_account:safe_account')
      ->willReturn([$relationship]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Group already has a treasury');

    $this->treasuryService->addTreasury($group, $safe_account);
  }

  /**
   * Tests removeTreasury() removes all treasury relationships.
   *
   * @covers ::removeTreasury
   */
  public function testRemoveTreasury() {
    $relationship1 = $this->createMock(GroupRelationshipInterface::class);
    $relationship1->expects($this->once())
      ->method('delete');

    $relationship2 = $this->createMock(GroupRelationshipInterface::class);
    $relationship2->expects($this->once())
      ->method('delete');

    $group = $this->createMock(GroupInterface::class);
    $group->expects($this->once())
      ->method('getRelationships')
      ->with('group_safe_account:safe_account')
      ->willReturn([$relationship1, $relationship2]);

    $this->treasuryService->removeTreasury($group);
  }

  /**
   * Tests getGroupsForTreasury() returns correct groups.
   *
   * @covers ::getGroupsForTreasury
   */
  public function testGetGroupsForTreasury() {
    $safe_account = $this->createMock(SafeAccountInterface::class);
    $safe_account->expects($this->once())
      ->method('id')
      ->willReturn('safe_123');

    $group1 = $this->createMock(GroupInterface::class);
    $group2 = $this->createMock(GroupInterface::class);

    $relationship1 = $this->createMock(GroupRelationshipInterface::class);
    $relationship1->expects($this->once())
      ->method('getGroup')
      ->willReturn($group1);

    $relationship2 = $this->createMock(GroupRelationshipInterface::class);
    $relationship2->expects($this->once())
      ->method('getGroup')
      ->willReturn($group2);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('loadByProperties')
      ->with([
        'entity_id' => 'safe_123',
        'plugin_id' => 'group_safe_account:safe_account',
      ])
      ->willReturn([$relationship1, $relationship2]);

    $this->entityTypeManager->expects($this->once())
      ->method('getStorage')
      ->with('group_relationship')
      ->willReturn($storage);

    $result = $this->treasuryService->getGroupsForTreasury($safe_account);
    $this->assertCount(2, $result);
    $this->assertSame($group1, $result[0]);
    $this->assertSame($group2, $result[1]);
  }

  /**
   * Tests getTreasuryRelationship() with no treasury.
   *
   * @covers ::getTreasuryRelationship
   */
  public function testGetTreasuryRelationshipWithNoTreasury() {
    $group = $this->createMock(GroupInterface::class);
    $group->expects($this->once())
      ->method('getRelationships')
      ->with('group_safe_account:safe_account')
      ->willReturn([]);

    $result = $this->treasuryService->getTreasuryRelationship($group);
    $this->assertNull($result);
  }

  /**
   * Tests getTreasuryRelationship() with existing treasury.
   *
   * @covers ::getTreasuryRelationship
   */
  public function testGetTreasuryRelationshipWithExistingTreasury() {
    $relationship = $this->createMock(GroupRelationshipInterface::class);

    $group = $this->createMock(GroupInterface::class);
    $group->expects($this->once())
      ->method('getRelationships')
      ->with('group_safe_account:safe_account')
      ->willReturn([$relationship]);

    $result = $this->treasuryService->getTreasuryRelationship($group);
    $this->assertSame($relationship, $result);
  }

}
