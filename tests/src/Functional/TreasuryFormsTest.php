<?php

namespace Drupal\Tests\group_treasury\Functional;

use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\safe_smart_accounts\Entity\SafeAccount;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests treasury forms (create, reconnect, propose transaction).
 *
 * @group group_treasury
 */
class TreasuryFormsTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'group',
    'safe_smart_accounts',
    'group_treasury',
    'node',
  ];

  /**
   * Test group type.
   *
   * @var \Drupal\group\Entity\GroupTypeInterface
   */
  protected $groupType;

  /**
   * Test group.
   *
   * @var \Drupal\group\Entity\GroupInterface
   */
  protected $group;

  /**
   * Group admin user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create group type.
    $this->groupType = GroupType::create([
      'id' => 'test_group',
      'label' => 'Test Group',
    ]);
    $this->groupType->save();

    // Install the treasury plugin.
    /** @var \Drupal\group\Plugin\Group\Relation\GroupRelationTypeManagerInterface $plugin_manager */
    $plugin_manager = \Drupal::service('group_relation_type.manager');
    $plugin_manager->installRelationType($this->groupType, 'group_safe_account:safe_account');

    // Create admin user.
    $this->adminUser = $this->drupalCreateUser([
      'administer group',
    ]);
    $this->adminUser->set('field_ethereum_address', '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    $this->adminUser->save();

    // Create test group.
    $this->group = Group::create([
      'type' => $this->groupType->id(),
      'label' => 'Test Group',
      'uid' => $this->adminUser->id(),
    ]);
    $this->group->save();

    $this->group->addMember($this->adminUser, ['group_roles' => ['test_group-admin']]);
  }

  /**
   * Tests treasury create form access.
   */
  public function testTreasuryCreateFormAccess() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/group/' . $this->group->id() . '/treasury/create');
    $this->assertSession()->statusCodeNotEquals(403);
  }

  /**
   * Tests treasury create form displays correctly.
   */
  public function testTreasuryCreateFormDisplay() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/group/' . $this->group->id() . '/treasury/create');

    // The form should either display or redirect to Safe creation.
    // At minimum, we should not get a 404 or 500 error.
    $status_code = $this->getSession()->getStatusCode();
    $this->assertTrue(
      in_array($status_code, [200, 302, 303]),
      'Create form should be accessible (200) or redirect (302/303)'
    );
  }

  /**
   * Tests treasury reconnect form access when treasury exists.
   */
  public function testTreasuryReconnectFormAccess() {
    // Create a Safe and add as treasury.
    $safe = SafeAccount::create([
      'label' => 'Test Safe',
      'user_id' => $this->adminUser->id(),
      'network' => 'sepolia',
      'safe_address' => '0x1234567890123456789012345678901234567890',
      'status' => 'active',
    ]);
    $safe->save();

    $this->group->addRelationship($safe, 'group_safe_account:safe_account');

    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/group/' . $this->group->id() . '/treasury/reconnect');
    $this->assertSession()->statusCodeNotEquals(403);
  }

  /**
   * Tests treasury transaction propose form access.
   */
  public function testTreasuryTransactionProposeFormAccess() {
    // Create a Safe and add as treasury.
    $safe = SafeAccount::create([
      'label' => 'Test Safe',
      'user_id' => $this->adminUser->id(),
      'network' => 'sepolia',
      'safe_address' => '0x1234567890123456789012345678901234567890',
      'status' => 'active',
    ]);
    $safe->save();

    $this->group->addRelationship($safe, 'group_safe_account:safe_account');

    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/group/' . $this->group->id() . '/treasury/propose-transaction');

    // Should be accessible to members with propose permission.
    $this->assertSession()->statusCodeNotEquals(403);
  }

  /**
   * Tests form access when group has no treasury.
   */
  public function testFormAccessWithoutTreasury() {
    $this->drupalLogin($this->adminUser);

    // Reconnect should not be accessible without treasury.
    $this->drupalGet('/group/' . $this->group->id() . '/treasury/reconnect');
    $this->assertSession()->statusCodeEquals(403);

    // Propose transaction should not be accessible without treasury.
    $this->drupalGet('/group/' . $this->group->id() . '/treasury/propose-transaction');
    $this->assertSession()->statusCodeEquals(403);

    // Create should be accessible.
    $this->drupalGet('/group/' . $this->group->id() . '/treasury/create');
    $this->assertSession()->statusCodeNotEquals(403);
  }

}
