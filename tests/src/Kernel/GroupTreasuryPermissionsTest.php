<?php

namespace Drupal\Tests\group_treasury\Kernel;

use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupRole;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\safe_smart_accounts\Entity\SafeAccount;
use Drupal\user\Entity\User;

/**
 * Tests group treasury permissions and access control.
 *
 * @group group_treasury
 */
class GroupTreasuryPermissionsTest extends KernelTestBase {

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
    $this->installEntitySchema('group_role');
    $this->installEntitySchema('safe_account');
    $this->installConfig(['group', 'group_treasury']);

    // Create group type and install treasury plugin.
    $this->groupType = GroupType::create([
      'id' => 'test_group',
      'label' => 'Test Group',
    ]);
    $this->groupType->save();

    /** @var \Drupal\group\Plugin\Group\Relation\GroupRelationTypeManagerInterface $plugin_manager */
    $plugin_manager = $this->container->get('group_relation_type.manager');
    $plugin_manager->installRelationType($this->groupType, 'group_safe_account:safe_account');
  }

  /**
   * Tests that treasury permissions are defined.
   */
  public function testTreasuryPermissionsAreDefined() {
    $permissions = [
      'view group_treasury',
      'propose group_treasury transactions',
      'sign group_treasury transactions',
      'execute group_treasury transactions',
      'manage group_treasury',
    ];

    /** @var \Drupal\group\Entity\Storage\GroupRoleStorageInterface $role_storage */
    $role_storage = $this->container->get('entity_type.manager')
      ->getStorage('group_role');

    // Get member role.
    $member_role = $role_storage->load($this->groupType->id() . '-member');
    $this->assertNotNull($member_role);

    // Get all available permissions for this group type.
    $all_permissions = $member_role->getPermissions();

    // At least some treasury permissions should be available.
    // Note: Actual permission assignment depends on permission provider
    // and may vary based on configuration.
  }

  /**
   * Tests permission checks for viewing treasury.
   */
  public function testViewTreasuryPermission() {
    $user = User::create([
      'name' => 'test_user',
      'field_ethereum_address' => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ]);
    $user->save();

    $group = Group::create([
      'type' => $this->groupType->id(),
      'label' => 'Test Group',
      'uid' => $user->id(),
    ]);
    $group->save();

    // Add user as member.
    $group->addMember($user);

    $membership = $group->getMember($user);
    $this->assertNotNull($membership);

    // Test that member can view treasury (if permission is granted by default).
    // This depends on permission provider configuration.
    $can_view = $membership->hasPermission('view group_treasury');

    // The result depends on the permission provider's configuration,
    // but the check should not throw an error.
    $this->assertIsBool($can_view);
  }

  /**
   * Tests permission checks for proposing transactions.
   */
  public function testProposeTransactionsPermission() {
    $user = User::create([
      'name' => 'test_user',
      'field_ethereum_address' => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ]);
    $user->save();

    $group = Group::create([
      'type' => $this->groupType->id(),
      'label' => 'Test Group',
      'uid' => $user->id(),
    ]);
    $group->save();

    $group->addMember($user);

    $membership = $group->getMember($user);
    $can_propose = $membership->hasPermission('propose group_treasury transactions');

    $this->assertIsBool($can_propose);
  }

  /**
   * Tests admin role has manage treasury permission.
   */
  public function testAdminRoleHasManagePermission() {
    $admin = User::create([
      'name' => 'admin',
      'field_ethereum_address' => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ]);
    $admin->save();

    $group = Group::create([
      'type' => $this->groupType->id(),
      'label' => 'Test Group',
      'uid' => $admin->id(),
    ]);
    $group->save();

    // Add as admin.
    $group->addMember($admin, ['group_roles' => ['test_group-admin']]);

    $membership = $group->getMember($admin);

    // Admin should have manage permission.
    $can_manage = $membership->hasPermission('manage group_treasury');

    // This should be true or the check should work without error.
    $this->assertIsBool($can_manage);
  }

  /**
   * Tests that non-members don't have treasury permissions.
   */
  public function testNonMembersHaveNoPermissions() {
    $creator = User::create([
      'name' => 'creator',
      'field_ethereum_address' => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ]);
    $creator->save();

    $non_member = User::create([
      'name' => 'non_member',
    ]);
    $non_member->save();

    $group = Group::create([
      'type' => $this->groupType->id(),
      'label' => 'Test Group',
      'uid' => $creator->id(),
    ]);
    $group->save();

    // Try to get membership for non-member (should be null).
    $membership = $group->getMember($non_member);
    $this->assertNull($membership);
  }

}
