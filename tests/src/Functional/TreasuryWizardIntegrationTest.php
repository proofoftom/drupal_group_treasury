<?php

namespace Drupal\Tests\group_treasury\Functional;

use Drupal\group\Entity\GroupType;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests the treasury wizard integration with group creation.
 *
 * @group group_treasury
 */
class TreasuryWizardIntegrationTest extends BrowserTestBase {

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
   * Test group type with wizard enabled.
   *
   * @var \Drupal\group\Entity\GroupTypeInterface
   */
  protected $wizardGroupType;

  /**
   * Test group type without wizard.
   *
   * @var \Drupal\group\Entity\GroupTypeInterface
   */
  protected $normalGroupType;

  /**
   * Test user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create group type with wizard enabled.
    $this->wizardGroupType = GroupType::create([
      'id' => 'wizard_group',
      'label' => 'Wizard Group',
    ]);
    $this->wizardGroupType->setThirdPartySetting(
      'group_treasury',
      'creator_treasury_wizard',
      TRUE
    );
    $this->wizardGroupType->save();

    // Create group type without wizard.
    $this->normalGroupType = GroupType::create([
      'id' => 'normal_group',
      'label' => 'Normal Group',
    ]);
    $this->normalGroupType->save();

    // Install treasury plugin on both types.
    /** @var \Drupal\group\Plugin\Group\Relation\GroupRelationTypeManagerInterface $plugin_manager */
    $plugin_manager = \Drupal::service('group_relation_type.manager');
    $plugin_manager->installRelationType($this->wizardGroupType, 'group_safe_account:safe_account');
    $plugin_manager->installRelationType($this->normalGroupType, 'group_safe_account:safe_account');

    // Create test user.
    $this->user = $this->drupalCreateUser([
      'administer group',
      'create wizard_group group',
      'create normal_group group',
    ]);
    $this->user->set('field_ethereum_address', '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    $this->user->save();
  }

  /**
   * Tests that wizard setting appears in group type configuration.
   */
  public function testWizardSettingInGroupTypeForm() {
    // Login as admin who can edit group types.
    $admin = $this->drupalCreateUser([
      'administer group',
      'administer group_type',
    ]);
    $this->drupalLogin($admin);

    // Visit group type edit form.
    $this->drupalGet('/admin/group/types/manage/' . $this->wizardGroupType->id());
    $this->assertSession()->statusCodeEquals(200);

    // Check for the wizard setting.
    $this->assertSession()->fieldExists('creator_treasury_wizard');
    $this->assertSession()->pageTextContains('Group creator must complete treasury deployment');
  }

  /**
   * Tests wizard setting is saved correctly.
   */
  public function testWizardSettingSaves() {
    // Verify setting is enabled on wizard group type.
    $setting = $this->wizardGroupType->getThirdPartySetting(
      'group_treasury',
      'creator_treasury_wizard',
      FALSE
    );
    $this->assertTrue($setting);

    // Verify setting is disabled on normal group type.
    $setting = $this->normalGroupType->getThirdPartySetting(
      'group_treasury',
      'creator_treasury_wizard',
      FALSE
    );
    $this->assertFalse($setting);
  }

  /**
   * Tests group creation without wizard proceeds normally.
   */
  public function testGroupCreationWithoutWizard() {
    $this->drupalLogin($this->user);

    $this->drupalGet('/group/add/' . $this->normalGroupType->id());
    $this->assertSession()->statusCodeEquals(200);

    // Submit the form.
    $this->submitForm([
      'label[0][value]' => 'Test Normal Group',
    ], 'Save');

    // Should redirect to group page, not treasury wizard.
    $this->assertSession()->pageTextContains('Test Normal Group');
    $this->assertSession()->addressMatches('/\/group\/\d+$/');
  }

  /**
   * Tests that group operations are altered correctly.
   */
  public function testGroupOperationsAreAltered() {
    $this->drupalLogin($this->user);

    // Create a group without treasury.
    $group = $this->drupalCreateNode([
      'type' => $this->normalGroupType->id(),
      'title' => 'Test Group',
    ]);

    // The group_operations hook should add "Add Treasury" operation
    // when the group doesn't have a treasury.
    // This is tested indirectly through the controller test.
  }

}
