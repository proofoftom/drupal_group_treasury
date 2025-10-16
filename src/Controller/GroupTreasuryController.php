<?php

namespace Drupal\group_treasury\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\group_treasury\Service\GroupTreasuryService;
use Drupal\group_treasury\Service\TreasuryAccessibilityChecker;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for group treasury tab.
 */
class GroupTreasuryController extends ControllerBase {

  /**
   * The group treasury service.
   *
   * @var \Drupal\group_treasury\Service\GroupTreasuryService
   */
  protected GroupTreasuryService $treasuryService;

  /**
   * The treasury accessibility checker.
   *
   * @var \Drupal\group_treasury\Service\TreasuryAccessibilityChecker
   */
  protected TreasuryAccessibilityChecker $accessibilityChecker;

  /**
   * Constructs a GroupTreasuryController object.
   *
   * @param \Drupal\group_treasury\Service\GroupTreasuryService $treasury_service
   *   The treasury service.
   * @param \Drupal\group_treasury\Service\TreasuryAccessibilityChecker $accessibility_checker
   *   The accessibility checker.
   */
  public function __construct(
    GroupTreasuryService $treasury_service,
    TreasuryAccessibilityChecker $accessibility_checker,
  ) {
    $this->treasuryService = $treasury_service;
    $this->accessibilityChecker = $accessibility_checker;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('group_treasury.treasury_service'),
      $container->get('group_treasury.accessibility_checker')
    );
  }

  /**
   * Display the treasury tab for a group.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   *
   * @return array
   *   A render array.
   */
  public function treasuryTab(GroupInterface $group): array {
    // Check if group has treasury.
    if (!$this->treasuryService->hasTreasury($group)) {
      return $this->buildNoTreasuryView($group);
    }

    $treasury = $this->treasuryService->getTreasury($group);

    // Check if Safe is pending deployment.
    if ($treasury->getStatus() === 'pending') {
      return $this->buildPendingDeploymentView($group, $treasury);
    }

    // Check accessibility (only for deployed Safes).
    $accessibility = $this->accessibilityChecker->checkAccessibility($treasury);

    if (!$accessibility['accessible']) {
      return $this->buildInaccessibleView($group, $treasury, $accessibility);
    }

    // Render active treasury interface.
    return $this->buildTreasuryView($group, $treasury, $accessibility);
  }

  /**
   * Build the view when group has no treasury.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   *
   * @return array
   *   A render array.
   */
  protected function buildNoTreasuryView(GroupInterface $group): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['group-treasury-none']],
    ];

    $build['message'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('This group does not have a treasury Safe account yet.') . '</p>',
    ];

    // Show "Add Treasury" link for admins.
    $create_url = Url::fromRoute('group_treasury.create', ['group' => $group->id()]);
    if ($create_url->access()) {
      $build['actions'] = [
        '#type' => 'actions',
        'create' => [
          '#type' => 'link',
          '#title' => $this->t('Add Treasury'),
          '#url' => $create_url,
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
      ];
    }

    return $build;
  }

  /**
   * Build the view for an inaccessible treasury.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   * @param \Drupal\safe_smart_accounts\Entity\SafeAccountInterface $treasury
   *   The treasury Safe account.
   * @param array $accessibility
   *   The accessibility check results.
   *
   * @return array
   *   A render array.
   */
  protected function buildInaccessibleView(GroupInterface $group, $treasury, array $accessibility): array {
    return [
      '#theme' => 'group_treasury_error',
      '#treasury' => $treasury,
      '#error_message' => $accessibility['error'] ?? $this->t('Unable to access treasury'),
      '#reconnect_url' => Url::fromRoute('group_treasury.reconnect', ['group' => $group->id()]),
      '#create_new_url' => Url::fromRoute('group_treasury.create', ['group' => $group->id()]),
    ];
  }

  /**
   * Build the view for a pending (not yet deployed) treasury.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   * @param \Drupal\safe_smart_accounts\Entity\SafeAccountInterface $treasury
   *   The treasury Safe account.
   *
   * @return array
   *   A render array.
   */
  protected function buildPendingDeploymentView(GroupInterface $group, $treasury): array {
    // Load SafeConfiguration to get signers and threshold.
    $config_storage = $this->entityTypeManager()->getStorage('safe_configuration');
    $config = $config_storage->load('safe_' . $treasury->id());

    $signers = $config ? $config->getSigners() : [];
    $threshold = $config ? $config->getThreshold() : 1;

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['group-treasury-pending']],
    ];

    $build['status_message'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--warning">' .
      $this->t('This treasury Safe account has not been deployed to the blockchain yet. Deploy it to start using the treasury.') .
      '</div>',
    ];

    $build['treasury_info'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['treasury-pending-info']],
    ];

    $build['treasury_info']['network'] = [
      '#type' => 'item',
      '#title' => $this->t('Network'),
      '#markup' => ucfirst($treasury->getNetwork()),
    ];

    $build['treasury_info']['threshold'] = [
      '#type' => 'item',
      '#title' => $this->t('Signature Threshold'),
      '#markup' => $this->t('@threshold of @total', [
        '@threshold' => $threshold,
        '@total' => count($signers),
      ]),
    ];

    $build['signers'] = [
      '#type' => 'details',
      '#title' => $this->t('Configured Signers (@count)', ['@count' => count($signers)]),
      '#open' => TRUE,
    ];

    if (!empty($signers)) {
      $build['signers']['list'] = [
        '#theme' => 'item_list',
        '#items' => $signers,
        '#attributes' => ['class' => ['signers-list']],
      ];
    }

    $build['actions'] = [
      '#type' => 'actions',
    ];

    // Link to the Safe account manage page where deployment happens.
    $deploy_url = Url::fromRoute('safe_smart_accounts.user_account_manage', [
      'user' => $treasury->getUser()->id(),
      'safe_account' => $treasury->id(),
    ]);

    if ($deploy_url->access()) {
      $build['actions']['deploy'] = [
        '#type' => 'link',
        '#title' => $this->t('Deploy Safe'),
        '#url' => $deploy_url,
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }

    $build['#cache'] = [
      'tags' => ['group:' . $group->id(), 'safe_account:' . $treasury->id()],
      'contexts' => ['user.permissions'],
    ];

    return $build;
  }

  /**
   * Build the active treasury management view.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   * @param \Drupal\safe_smart_accounts\Entity\SafeAccountInterface $treasury
   *   The treasury Safe account.
   * @param array $accessibility
   *   The accessibility check results.
   *
   * @return array
   *   A render array.
   */
  protected function buildTreasuryView(GroupInterface $group, $treasury, array $accessibility): array {
    // Check user permissions.
    $current_user = $this->currentUser();
    $membership = $group->getMember($current_user);

    $can_propose = $membership && $membership->hasPermission('propose group_treasury transactions');
    $can_sign = $membership && $membership->hasPermission('sign group_treasury transactions');
    $can_execute = $membership && $membership->hasPermission('execute group_treasury transactions');

    // Get signers from SafeConfiguration (not from accessibility check).
    $config_storage = $this->entityTypeManager()->getStorage('safe_configuration');
    $config = $config_storage->load('safe_' . $treasury->id());
    $signers = $config ? $config->getSigners() : [];
    $threshold = $config ? $config->getThreshold() : $treasury->getThreshold();

    // Get treasury transactions.
    $transaction_storage = $this->entityTypeManager()->getStorage('safe_transaction');
    $transaction_ids = $transaction_storage->getQuery()
      ->condition('safe_account', $treasury->id())
      ->sort('created', 'DESC')
      ->range(0, 20)
      ->accessCheck(TRUE)
      ->execute();

    $transactions = $transaction_ids ? $transaction_storage->loadMultiple($transaction_ids) : [];

    return [
      '#theme' => 'group_treasury_tab',
      '#group' => $group,
      '#treasury' => $treasury,
      '#balance' => $accessibility['balance'] ?? '0',
      '#signers' => $signers,
      '#threshold' => $threshold,
      '#transactions' => $transactions,
      '#can_propose' => $can_propose,
      '#can_sign' => $can_sign,
      '#can_execute' => $can_execute,
      '#propose_url' => Url::fromRoute('group_treasury.propose_transaction', ['group' => $group->id()]),
      '#cache' => [
        'tags' => ['group:' . $group->id(), 'safe_account:' . $treasury->id()],
        'contexts' => ['user.group_permissions', 'user'],
      ],
    ];
  }

  /**
   * Title callback for treasury tab.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   *
   * @return string
   *   The page title.
   */
  public function treasuryTitle(GroupInterface $group): string {
    return $this->t('Treasury');
  }

}
