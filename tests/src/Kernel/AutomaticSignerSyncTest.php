<?php

namespace Drupal\Tests\group_treasury\Kernel;

use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\safe_smart_accounts\Entity\SafeAccount;
use Drupal\safe_smart_accounts\Entity\SafeConfiguration;
use Drupal\user\Entity\User;

/**
 * Tests automatic signer synchronization when group roles change.
 *
 * @group group_treasury
 */
class AutomaticSignerSyncTest extends KernelTestBase {

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
   * Test group.
   *
   * @var \Drupal\group\Entity\GroupInterface
   */
  protected $group;

  /**
   * Test treasury Safe account.
   *
   * @var \Drupal\safe_smart_accounts\Entity\SafeAccountInterface
   */
  protected $treasury;

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
    $this->installEntitySchema('safe_transaction');
    $this->installSchema('system', ['sequences']);
    $this->installConfig(['group', 'group_treasury', 'safe_smart_accounts']);

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

    // Create group creator.
    $creator = User::create([
      'name' => 'creator',
      'field_ethereum_address' => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    ]);
    $creator->save();

    // Create test group.
    $this->group = Group::create([
      'type' => $this->groupType->id(),
      'label' => 'Test Group',
      'uid' => $creator->id(),
    ]);
    $this->group->save();

    // Create and add treasury.
    $this->treasury = SafeAccount::create([
      'label' => 'Test Treasury',
      'user_id' => $creator->id(),
      'network' => 'sepolia',
      'safe_address' => '0x1234567890123456789012345678901234567890',
      'status' => 'active',
      'threshold' => 2,
    ]);
    $this->treasury->save();

    // Create Safe configuration.
    $config = SafeConfiguration::create([
      'id' => 'safe_' . $this->treasury->id(),
      'safe_account' => $this->treasury->id(),
      'signers' => ['0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'],
      'threshold' => 1,
    ]);
    $config->save();

    $this->treasuryService->addTreasury($this->group, $this->treasury);
  }

  /**
   * Tests that adding an admin role proposes addOwnerWithThreshold transaction.
   */
  public function testAddingAdminRoleProposesAddOwnerTransaction() {
    // Create a new user with Ethereum address.
    $new_admin = User::create([
      'name' => 'new_admin',
      'field_ethereum_address' => '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    ]);
    $new_admin->save();

    // Get initial transaction count.
    $transaction_storage = $this->container->get('entity_type.manager')
      ->getStorage('safe_transaction');
    $initial_count = count($transaction_storage->loadMultiple());

    // Add user to group with admin role.
    $this->group->addMember($new_admin, ['group_roles' => ['test_group-admin']]);

    // Verify a new transaction was created.
    $transactions = $transaction_storage->loadMultiple();
    $this->assertGreaterThan($initial_count, count($transactions));

    // Get the newest transaction.
    $latest_transaction = end($transactions);

    // Verify it's an addOwnerWithThreshold transaction.
    $data = $latest_transaction->get('data')->value;
    $this->assertStringContainsString('0x0d582f13', $data, 'Transaction should use addOwnerWithThreshold selector');

    // Verify it targets the new admin's address.
    $address_without_prefix = substr('0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 2);
    $this->assertStringContainsString(
      strtolower($address_without_prefix),
      strtolower($data),
      'Transaction data should contain new admin address'
    );

    // Verify transaction is linked to treasury.
    $this->assertEquals(
      $this->treasury->id(),
      $latest_transaction->get('safe_account')->target_id
    );
  }

  /**
   * Tests that removing an admin role proposes removeOwner transaction.
   */
  public function testRemovingAdminRoleProposesRemoveOwnerTransaction() {
    // First, add a user as admin.
    $admin = User::create([
      'name' => 'admin',
      'field_ethereum_address' => '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    ]);
    $admin->save();

    $this->group->addMember($admin, ['group_roles' => ['test_group-admin']]);

    // Update Safe configuration to include this user as a signer.
    $config_storage = $this->container->get('entity_type.manager')
      ->getStorage('safe_configuration');
    $config = $config_storage->load('safe_' . $this->treasury->id());
    $config->set('signers', [
      '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    ]);
    $config->set('threshold', 2);
    $config->save();

    // Get transaction count before removal.
    $transaction_storage = $this->container->get('entity_type.manager')
      ->getStorage('safe_transaction');
    $before_count = count($transaction_storage->loadMultiple());

    // Get the membership and remove admin role.
    $membership = $this->group->getMember($admin);
    $membership->getGroupRelationship()->set('group_roles', [])->save();

    // Verify a new transaction was created.
    $transactions = $transaction_storage->loadMultiple();
    $this->assertGreaterThan($before_count, count($transactions));

    // Get the newest transaction.
    $latest_transaction = end($transactions);

    // Verify it's a removeOwner transaction.
    $data = $latest_transaction->get('data')->value;
    $this->assertStringContainsString('0xf8dc5dd9', $data, 'Transaction should use removeOwner selector');

    // Verify it targets the admin's address.
    $address_without_prefix = substr('0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 2);
    $this->assertStringContainsString(
      strtolower($address_without_prefix),
      strtolower($data),
      'Transaction data should contain admin address to remove'
    );
  }

  /**
   * Tests that member leaving group proposes removeOwner if they're a signer.
   */
  public function testMemberLeavingGroupProposesRemoveOwnerIfSigner() {
    // Add a user as member (not admin) but who is a signer.
    $member = User::create([
      'name' => 'member_signer',
      'field_ethereum_address' => '0xcccccccccccccccccccccccccccccccccccccccc',
    ]);
    $member->save();

    $this->group->addMember($member);

    // Manually add them as signer in configuration.
    $config_storage = $this->container->get('entity_type.manager')
      ->getStorage('safe_configuration');
    $config = $config_storage->load('safe_' . $this->treasury->id());
    $config->set('signers', [
      '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      '0xcccccccccccccccccccccccccccccccccccccccc',
    ]);
    $config->set('threshold', 2);
    $config->save();

    // Get transaction count before removal.
    $transaction_storage = $this->container->get('entity_type.manager')
      ->getStorage('safe_transaction');
    $before_count = count($transaction_storage->loadMultiple());

    // Remove member from group.
    $membership = $this->group->getMember($member);
    $membership->getGroupRelationship()->delete();

    // Verify a removeOwner transaction was created.
    $transactions = $transaction_storage->loadMultiple();
    $this->assertGreaterThan($before_count, count($transactions));

    // Get the newest transaction.
    $latest_transaction = end($transactions);

    // Verify it's a removeOwner transaction.
    $data = $latest_transaction->get('data')->value;
    $this->assertStringContainsString('0xf8dc5dd9', $data, 'Transaction should use removeOwner selector');
  }

  /**
   * Tests that no transaction is created for user without Ethereum address.
   */
  public function testNoTransactionForUserWithoutEthereumAddress() {
    // Create user without Ethereum address.
    $user = User::create(['name' => 'no_eth_user']);
    $user->save();

    // Get transaction count.
    $transaction_storage = $this->container->get('entity_type.manager')
      ->getStorage('safe_transaction');
    $before_count = count($transaction_storage->loadMultiple());

    // Add as admin.
    $this->group->addMember($user, ['group_roles' => ['test_group-admin']]);

    // Verify no new transaction was created.
    $after_count = count($transaction_storage->loadMultiple());
    $this->assertEquals($before_count, $after_count);
  }

  /**
   * Tests that no transaction is created for pending treasury.
   */
  public function testNoTransactionForPendingTreasury() {
    // Create new group with pending treasury.
    $creator = User::create([
      'name' => 'creator2',
      'field_ethereum_address' => '0xdddddddddddddddddddddddddddddddddddddddd',
    ]);
    $creator->save();

    $group2 = Group::create([
      'type' => $this->groupType->id(),
      'label' => 'Test Group 2',
      'uid' => $creator->id(),
    ]);
    $group2->save();

    // Create pending treasury.
    $pending_safe = SafeAccount::create([
      'label' => 'Pending Safe',
      'user_id' => $creator->id(),
      'network' => 'sepolia',
      'status' => 'pending',
    ]);
    $pending_safe->save();

    $this->treasuryService->addTreasury($group2, $pending_safe);

    // Get transaction count.
    $transaction_storage = $this->container->get('entity_type.manager')
      ->getStorage('safe_transaction');
    $before_count = count($transaction_storage->loadMultiple());

    // Add admin to group.
    $new_user = User::create([
      'name' => 'test_admin',
      'field_ethereum_address' => '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
    ]);
    $new_user->save();

    $group2->addMember($new_user, ['group_roles' => ['test_group-admin']]);

    // Verify no transaction was created for pending Safe.
    $after_count = count($transaction_storage->loadMultiple());
    $this->assertEquals($before_count, $after_count);
  }

  /**
   * Tests that nonces are sequential.
   */
  public function testSequentialNonces() {
    // Add multiple admins to trigger multiple transactions.
    $admin1 = User::create([
      'name' => 'admin1',
      'field_ethereum_address' => '0x1111111111111111111111111111111111111111',
    ]);
    $admin1->save();

    $admin2 = User::create([
      'name' => 'admin2',
      'field_ethereum_address' => '0x2222222222222222222222222222222222222222',
    ]);
    $admin2->save();

    $admin3 = User::create([
      'name' => 'admin3',
      'field_ethereum_address' => '0x3333333333333333333333333333333333333333',
    ]);
    $admin3->save();

    // Add them sequentially.
    $this->group->addMember($admin1, ['group_roles' => ['test_group-admin']]);
    $this->group->addMember($admin2, ['group_roles' => ['test_group-admin']]);
    $this->group->addMember($admin3, ['group_roles' => ['test_group-admin']]);

    // Get all transactions for this treasury.
    $transaction_storage = $this->container->get('entity_type.manager')
      ->getStorage('safe_transaction');

    $transaction_ids = $transaction_storage->getQuery()
      ->condition('safe_account', $this->treasury->id())
      ->condition('nonce', NULL, 'IS NOT NULL')
      ->sort('nonce', 'ASC')
      ->accessCheck(FALSE)
      ->execute();

    $transactions = $transaction_storage->loadMultiple($transaction_ids);

    // Collect nonces.
    $nonces = [];
    foreach ($transactions as $transaction) {
      $nonce = $transaction->get('nonce')->value;
      $nonces[] = $nonce;
    }

    // Verify nonces are sequential (0, 1, 2, ...).
    $expected_nonces = range(0, count($nonces) - 1);
    $this->assertEquals($expected_nonces, $nonces, 'Nonces should be sequential starting from 0');

    // Verify no duplicate nonces.
    $this->assertEquals(count($nonces), count(array_unique($nonces)), 'Nonces should be unique');
  }

}
