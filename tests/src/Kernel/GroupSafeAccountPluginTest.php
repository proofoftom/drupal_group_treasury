<?php

namespace Drupal\Tests\group_treasury\Kernel;

use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the GroupSafeAccount plugin and deriver.
 *
 * @group group_treasury
 */
class GroupSafeAccountPluginTest extends KernelTestBase {

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
   * The group relation type manager.
   *
   * @var \Drupal\group\Plugin\Group\Relation\GroupRelationTypeManagerInterface
   */
  protected $pluginManager;

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
    $this->installConfig(['group', 'group_treasury']);

    $this->pluginManager = $this->container->get('group_relation_type.manager');
  }

  /**
   * Tests that the group_safe_account plugin is discovered.
   */
  public function testPluginDiscovery() {
    $definitions = $this->pluginManager->getDefinitions();
    $this->assertArrayHasKey('group_safe_account:safe_account', $definitions);

    $definition = $definitions['group_safe_account:safe_account'];
    $this->assertEquals('safe_account', $definition['entity_type_id']);
    $this->assertEquals('Group Safe Account (Treasury)', (string) $definition['label']);
  }

  /**
   * Tests plugin installation on a group type.
   */
  public function testPluginInstallation() {
    $group_type = GroupType::create([
      'id' => 'test_group',
      'label' => 'Test Group',
    ]);
    $group_type->save();

    // Install the plugin.
    $this->pluginManager->installRelationType($group_type, 'group_safe_account:safe_account');

    // Verify it's installed.
    $installed = $this->pluginManager->getInstalledIds($group_type);
    $this->assertContains('group_safe_account:safe_account', $installed);
  }

  /**
   * Tests the default plugin configuration enforces 1:1 cardinality.
   */
  public function testDefaultConfiguration() {
    $group_type = GroupType::create([
      'id' => 'test_group',
      'label' => 'Test Group',
    ]);
    $group_type->save();

    $this->pluginManager->installRelationType($group_type, 'group_safe_account:safe_account');

    /** @var \Drupal\group\Entity\Storage\GroupRelationshipTypeStorageInterface $storage */
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('group_relationship_type');

    $relation_type = $storage->load('test_group-group_safe_account-safe_account');
    $this->assertNotNull($relation_type);

    $config = $relation_type->getPlugin()->getConfiguration();

    // Verify 1:1 cardinality is enforced.
    $this->assertEquals(1, $config['entity_cardinality']);
  }

  /**
   * Tests plugin permissions are defined.
   */
  public function testPluginPermissions() {
    $group_type = GroupType::create([
      'id' => 'test_group',
      'label' => 'Test Group',
    ]);
    $group_type->save();

    $this->pluginManager->installRelationType($group_type, 'group_safe_account:safe_account');

    /** @var \Drupal\group\Entity\Storage\GroupRelationshipTypeStorageInterface $storage */
    $storage = $this->container->get('entity_type.manager')
      ->getStorage('group_relationship_type');

    $relation_type = $storage->load('test_group-group_safe_account-safe_account');
    $plugin = $relation_type->getPlugin();

    // Get permission provider.
    $permission_provider = $plugin->getRelationHandler('permission_provider');
    $this->assertNotNull($permission_provider);

    $permissions = $permission_provider->getPermissions();
    $this->assertNotEmpty($permissions);

    // Check for treasury-specific permissions.
    $permission_ids = array_keys($permissions);
    $this->assertContains('view group_treasury', $permission_ids);
    $this->assertContains('propose group_treasury transactions', $permission_ids);
    $this->assertContains('sign group_treasury transactions', $permission_ids);
    $this->assertContains('execute group_treasury transactions', $permission_ids);
    $this->assertContains('manage group_treasury', $permission_ids);
  }

  /**
   * Tests that entity_access is enabled for the plugin.
   */
  public function testEntityAccessEnabled() {
    $definitions = $this->pluginManager->getDefinitions();
    $definition = $definitions['group_safe_account:safe_account'];

    $this->assertTrue($definition['entity_access']);
  }

}
