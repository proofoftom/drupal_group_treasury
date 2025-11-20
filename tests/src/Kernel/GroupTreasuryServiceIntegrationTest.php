<?php

namespace Drupal\Tests\group_treasury\Kernel;

use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\safe_smart_accounts\Entity\SafeAccount;
use Drupal\user\Entity\User;

/**
 * Integration tests for GroupTreasuryService with real entities.
 *
 * @group group_treasury
 */
class GroupTreasuryServiceIntegrationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'group',
    'safe_smart_accounts',
    'group_treasury',
  ];

  /**
   * The treasury service.
   *
   * @var \Drupal\group_treasury\Service\GroupTreasuryService
   */
  protected $treasuryService;

  /**
   * Test group type.
   *
   * @var \Drupal\group\Entity\GroupTypeInterface
   */
  protected $groupType;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('group');
    $this->installEntitySchema('group_type');
    $this->installEntitySchema('group_relationship');
    $this->installEntitySchema('safe_account');
    $this->installEntitySchema('safe_configuration');
    $this->installConfig(['group', 'group_treasury']);

    $this->treasuryService = $this->container->get('group_treasury.treasury_service');

    // Create a group type and enable the treasury plugin.
    $this->groupType = GroupType::create([
      'id' => 'test_group',
      'label' => 'Test Group',
    ]);
    $this->groupType->save();

    // Install the group_safe_account plugin.
    /** @var \Drupal\group\Plugin\Group\Relation\GroupRelationTypeManagerInterface $plugin_manager */
    $plugin_manager = $this->container->get('group_relation_type.manager');
    $plugin_manager->installRelationType($this->groupType, 'group_safe_account:safe_account');
  }

  /**
   * Tests adding a treasury to a group.
   */
  public function testAddTreasuryToGroup() {
    $user = User::create(['name' => 'test_user']);
    $user->save();

    $group = Group::create([
      'type' => $this->groupType->id(),
      'label' => 'Test Group',
      'uid' => $user->id(),
    ]);
    $group->save();

    $safe = SafeAccount::create([
      'label' => 'Test Safe',
      'user_id' => $user->id(),
      'network' => 'sepolia',
      'safe_address' => '0x1234567890123456789012345678901234567890',
      'status' => 'active',
    ]);
    $safe->save();

    // Initially no treasury.
    $this->assertFalse($this->treasuryService->hasTreasury($group));
    $this->assertNull($this->treasuryService->getTreasury($group));

    // Add treasury.
    $this->treasuryService->addTreasury($group, $safe);

    // Now has treasury.
    $this->assertTrue($this->treasuryService->hasTreasury($group));
    $treasury = $this->treasuryService->getTreasury($group);
    $this->assertNotNull($treasury);
    $this->assertEquals($safe->id(), $treasury->id());
  }

  /**
   * Tests that adding a treasury twice throws an exception.
   */
  public function testAddTreasuryTwiceThrowsException() {
    $user = User::create(['name' => 'test_user']);
    $user->save();

    $group = Group::create([
      'type' => $this->groupType->id(),
      'label' => 'Test Group',
      'uid' => $user->id(),
    ]);
    $group->save();

    $safe1 = SafeAccount::create([
      'label' => 'Test Safe 1',
      'user_id' => $user->id(),
      'network' => 'sepolia',
      'safe_address' => '0x1111111111111111111111111111111111111111',
      'status' => 'active',
    ]);
    $safe1->save();

    $safe2 = SafeAccount::create([
      'label' => 'Test Safe 2',
      'user_id' => $user->id(),
      'network' => 'sepolia',
      'safe_address' => '0x2222222222222222222222222222222222222222',
      'status' => 'active',
    ]);
    $safe2->save();

    $this->treasuryService->addTreasury($group, $safe1);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Group already has a treasury');
    $this->treasuryService->addTreasury($group, $safe2);
  }

  /**
   * Tests removing a treasury from a group.
   */
  public function testRemoveTreasury() {
    $user = User::create(['name' => 'test_user']);
    $user->save();

    $group = Group::create([
      'type' => $this->groupType->id(),
      'label' => 'Test Group',
      'uid' => $user->id(),
    ]);
    $group->save();

    $safe = SafeAccount::create([
      'label' => 'Test Safe',
      'user_id' => $user->id(),
      'network' => 'sepolia',
      'safe_address' => '0x1234567890123456789012345678901234567890',
      'status' => 'active',
    ]);
    $safe->save();

    $this->treasuryService->addTreasury($group, $safe);
    $this->assertTrue($this->treasuryService->hasTreasury($group));

    $this->treasuryService->removeTreasury($group);
    $this->assertFalse($this->treasuryService->hasTreasury($group));
    $this->assertNull($this->treasuryService->getTreasury($group));
  }

  /**
   * Tests getting groups for a treasury.
   */
  public function testGetGroupsForTreasury() {
    $user = User::create(['name' => 'test_user']);
    $user->save();

    $group1 = Group::create([
      'type' => $this->groupType->id(),
      'label' => 'Test Group 1',
      'uid' => $user->id(),
    ]);
    $group1->save();

    $group2 = Group::create([
      'type' => $this->groupType->id(),
      'label' => 'Test Group 2',
      'uid' => $user->id(),
    ]);
    $group2->save();

    $safe = SafeAccount::create([
      'label' => 'Shared Safe',
      'user_id' => $user->id(),
      'network' => 'sepolia',
      'safe_address' => '0x1234567890123456789012345678901234567890',
      'status' => 'active',
    ]);
    $safe->save();

    // For this test, we need to manually add relationships since
    // addTreasury enforces 1:1. In reality, this tests the multi-group
    // support capability of the architecture.
    $group1->addRelationship($safe, 'group_safe_account:safe_account');
    $group2->addRelationship($safe, 'group_safe_account:safe_account');

    $groups = $this->treasuryService->getGroupsForTreasury($safe);
    $this->assertCount(2, $groups);

    $group_ids = array_map(function ($group) {
      return $group->id();
    }, $groups);

    $this->assertContains($group1->id(), $group_ids);
    $this->assertContains($group2->id(), $group_ids);
  }

  /**
   * Tests getting treasury relationship entity.
   */
  public function testGetTreasuryRelationship() {
    $user = User::create(['name' => 'test_user']);
    $user->save();

    $group = Group::create([
      'type' => $this->groupType->id(),
      'label' => 'Test Group',
      'uid' => $user->id(),
    ]);
    $group->save();

    $safe = SafeAccount::create([
      'label' => 'Test Safe',
      'user_id' => $user->id(),
      'network' => 'sepolia',
      'safe_address' => '0x1234567890123456789012345678901234567890',
      'status' => 'active',
    ]);
    $safe->save();

    $this->assertNull($this->treasuryService->getTreasuryRelationship($group));

    $this->treasuryService->addTreasury($group, $safe);

    $relationship = $this->treasuryService->getTreasuryRelationship($group);
    $this->assertNotNull($relationship);
    $this->assertEquals('group_safe_account:safe_account', $relationship->getPluginId());
    $this->assertEquals($safe->id(), $relationship->getEntity()->id());
    $this->assertEquals($group->id(), $relationship->getGroup()->id());
  }

}
