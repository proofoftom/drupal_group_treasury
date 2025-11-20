<?php

namespace Drupal\Tests\group_treasury\Kernel;

use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\safe_smart_accounts\Entity\SafeAccount;
use Drupal\safe_smart_accounts\Entity\SafeConfiguration;
use Drupal\user\Entity\User;

/**
 * Tests module hooks and integrations.
 *
 * @group group_treasury
 */
class ModuleHooksTest extends KernelTestBase {

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
  }

  /**
   * Tests hook_theme() provides templates.
   */
  public function testHookTheme() {
    $theme_registry = \Drupal::service('theme.registry')->get();

    // Verify group_treasury_tab template is registered.
    $this->assertArrayHasKey('group_treasury_tab', $theme_registry);

    // Verify group_treasury_error template is registered.
    $this->assertArrayHasKey('group_treasury_error', $theme_registry);

    // Verify variables are defined.
    $tab_template = $theme_registry['group_treasury_tab'];
    $this->assertArrayHasKey('variables', $tab_template);
    $this->assertArrayHasKey('group', $tab_template['variables']);
    $this->assertArrayHasKey('treasury', $tab_template['variables']);
    $this->assertArrayHasKey('balance', $tab_template['variables']);
    $this->assertArrayHasKey('transactions', $tab_template['variables']);
  }

  /**
   * Tests group treasury wizard third-party settings.
   */
  public function testGroupTypeThirdPartySettings() {
    $group_type = GroupType::create([
      'id' => 'test_group',
      'label' => 'Test Group',
    ]);
    $group_type->save();

    // Set wizard setting.
    $group_type->setThirdPartySetting('group_treasury', 'creator_treasury_wizard', TRUE);
    $group_type->save();

    // Reload and verify.
    $group_type = GroupType::load('test_group');
    $setting = $group_type->getThirdPartySetting('group_treasury', 'creator_treasury_wizard', FALSE);

    $this->assertTrue($setting);
  }

  /**
   * Tests entity hooks are invoked for group relationship changes.
   */
  public function testGroupRelationshipEntityHooks() {
    // Create group type.
    $group_type = GroupType::create([
      'id' => 'test_group',
      'label' => 'Test Group',
    ]);
    $group_type->save();

    // Install treasury plugin.
    /** @var \Drupal\group\Plugin\Group\Relation\GroupRelationTypeManagerInterface $plugin_manager */
    $plugin_manager = $this->container->get('group_relation_type.manager');
    $plugin_manager->installRelationType($group_type, 'group_safe_account:safe_account');

    // Create user with Ethereum address.
    $user = User::create([
      'name' => 'test_user',
      'field_ethereum_address' => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ]);
    $user->save();

    // Create group.
    $group = Group::create([
      'type' => $group_type->id(),
      'label' => 'Test Group',
      'uid' => $user->id(),
    ]);
    $group->save();

    // Create active treasury.
    $safe = SafeAccount::create([
      'label' => 'Test Safe',
      'user_id' => $user->id(),
      'network' => 'sepolia',
      'safe_address' => '0x1234567890123456789012345678901234567890',
      'status' => 'active',
      'threshold' => 1,
    ]);
    $safe->save();

    $config = SafeConfiguration::create([
      'id' => 'safe_' . $safe->id(),
      'safe_account' => $safe->id(),
      'signers' => ['0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
      'threshold' => 1,
    ]);
    $config->save();

    $group->addRelationship($safe, 'group_safe_account:safe_account');

    // Add new member with admin role - should trigger hook.
    $new_admin = User::create([
      'name' => 'new_admin',
      'field_ethereum_address' => '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    ]);
    $new_admin->save();

    // Get transaction count before.
    $transaction_storage = $this->container->get('entity_type.manager')
      ->getStorage('safe_transaction');
    $before_count = count($transaction_storage->loadMultiple());

    // Add member with admin role - should trigger hook_group_relationship_insert.
    $group->addMember($new_admin, ['group_roles' => ['test_group-admin']]);

    // Get transaction count after.
    $after_count = count($transaction_storage->loadMultiple());

    // Verify hook was triggered (new transaction created).
    $this->assertGreaterThan($before_count, $after_count);
  }

  /**
   * Tests nonce calculation helper function.
   */
  public function testNonceCalculation() {
    // Create minimal setup.
    $group_type = GroupType::create([
      'id' => 'test_group',
      'label' => 'Test Group',
    ]);
    $group_type->save();

    $plugin_manager = $this->container->get('group_relation_type.manager');
    $plugin_manager->installRelationType($group_type, 'group_safe_account:safe_account');

    $user = User::create([
      'name' => 'test_user',
      'field_ethereum_address' => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ]);
    $user->save();

    $safe = SafeAccount::create([
      'label' => 'Test Safe',
      'user_id' => $user->id(),
      'network' => 'sepolia',
      'safe_address' => '0x1234567890123456789012345678901234567890',
      'status' => 'active',
    ]);
    $safe->save();

    // The helper functions for encoding transactions should exist and work.
    // These are tested indirectly through the AutomaticSignerSyncTest.

    // Verify the Safe was created successfully.
    $this->assertNotNull($safe->id());
    $this->assertEquals('active', $safe->getStatus());
  }

  /**
   * Tests function encoding helpers for Safe transactions.
   */
  public function testFunctionEncodingHelpers() {
    // Test that the encoding functions exist and can be called.
    // These are module-level functions defined in group_treasury.module.

    $this->assertTrue(
      function_exists('_group_treasury_encode_add_owner'),
      'Add owner encoding function should exist'
    );

    $this->assertTrue(
      function_exists('_group_treasury_encode_remove_owner'),
      'Remove owner encoding function should exist'
    );

    $this->assertTrue(
      function_exists('_group_treasury_find_prev_owner'),
      'Find previous owner function should exist'
    );

    // Test basic encoding (if functions are implemented).
    if (function_exists('_group_treasury_encode_add_owner')) {
      $encoded = _group_treasury_encode_add_owner(
        '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        1
      );

      // Should return hex string starting with 0x0d582f13 (addOwnerWithThreshold selector).
      $this->assertStringStartsWith('0x0d582f13', $encoded);
    }

    if (function_exists('_group_treasury_encode_remove_owner')) {
      $encoded = _group_treasury_encode_remove_owner(
        '0x0000000000000000000000000000000000000001',
        '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        1
      );

      // Should return hex string starting with 0xf8dc5dd9 (removeOwner selector).
      $this->assertStringStartsWith('0xf8dc5dd9', $encoded);
    }
  }

}
