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
      '#attributes' => ['class' => ['group-treasury-none', 'card']],
    ];

    $build['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card__block']],
    ];

    // Check if user has permission to create treasury
    $create_url = Url::fromRoute('group_treasury.create', ['group' => $group->id()]);
    if ($create_url->access()) {
      // Render the treasury creation form inline instead of showing a link
      // This keeps the user in the Group context with hero/sidebar blocks
      $build['content']['form'] = $this->formBuilder()->getForm(
        'Drupal\group_treasury\Form\TreasuryCreateForm',
        $group
      );
    }
    else {
      // No permission - show message only
      $build['content']['message'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('This group does not have a treasury Safe account yet.') . '</p>',
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
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['group-treasury-inaccessible', 'card']],
    ];

    $build['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card__block']],
      'error' => [
        '#theme' => 'group_treasury_error',
        '#treasury' => $treasury,
        '#error_message' => $accessibility['error'] ?? $this->t('Unable to access treasury'),
        '#reconnect_url' => Url::fromRoute('group_treasury.reconnect', ['group' => $group->id()]),
        '#create_new_url' => Url::fromRoute('group_treasury.create', ['group' => $group->id()]),
      ],
    ];

    return $build;
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
      '#attributes' => ['class' => ['group-treasury-pending', 'card']],
    ];

    $build['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card__block']],
    ];

    $build['content']['status_message'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--warning">' .
      $this->t('This treasury Safe account has not been deployed to the blockchain yet. Deploy it to start using the treasury.') .
      '</div>',
    ];

    $build['content']['treasury_info'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['treasury-pending-info']],
    ];

    $build['content']['treasury_info']['network'] = [
      '#type' => 'item',
      '#title' => $this->t('Network'),
      '#markup' => ucfirst($treasury->getNetwork()),
    ];

    $build['content']['treasury_info']['threshold'] = [
      '#type' => 'item',
      '#title' => $this->t('Signature Threshold'),
      '#markup' => $this->t('@threshold of @total', [
        '@threshold' => $threshold,
        '@total' => count($signers),
      ]),
    ];

    $build['content']['signers'] = [
      '#type' => 'details',
      '#title' => $this->t('Configured Signers (@count)', ['@count' => count($signers)]),
      '#open' => TRUE,
    ];

    if (!empty($signers)) {
      $build['content']['signers']['list'] = [
        '#theme' => 'item_list',
        '#items' => $signers,
        '#attributes' => ['class' => ['signers-list']],
      ];
    }

    $build['content']['actions'] = [
      '#type' => 'actions',
    ];

    // Link to the Safe account manage page where deployment happens.
    $deploy_url = Url::fromRoute('safe_smart_accounts.user_account_manage', [
      'user' => $treasury->getUser()->id(),
      'safe_account' => $treasury->id(),
    ]);

    if ($deploy_url->access()) {
      $build['content']['actions']['deploy'] = [
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

    // Build render array that will receive card styling from Open Social theme.
    $build = [];

    // Treasury info section - wrapped in card.
    $build['treasury_info'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card', 'treasury-info-card']],
    ];

    $build['treasury_info']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card__block']],
    ];

    $build['treasury_info']['content']['safe_address'] = [
      '#type' => 'item',
      '#title' => $this->t('Safe Address:'),
      '#markup' => $treasury->getSafeAddress(),
      '#wrapper_attributes' => ['class' => ['treasury-info-item']],
    ];

    $build['treasury_info']['content']['network'] = [
      '#type' => 'item',
      '#title' => $this->t('Network:'),
      '#markup' => ucfirst($treasury->getNetwork()),
      '#wrapper_attributes' => ['class' => ['treasury-info-item']],
    ];

    $build['treasury_info']['content']['balance'] = [
      '#type' => 'item',
      '#title' => $this->t('Balance:'),
      '#markup' => ($accessibility['balance'] ?? '0') . ' ETH',
      '#wrapper_attributes' => ['class' => ['treasury-info-item']],
    ];

    // Signers section - wrapped in card.
    $build['signers'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card', 'signers-card']],
    ];

    $build['signers']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card__block']],
    ];

    $build['signers']['content']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => $this->t('Signers'),
      '#attributes' => ['class' => ['card__title']],
    ];

    $build['signers']['content']['threshold_info'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Requires @threshold of @total signatures to execute transactions', [
        '@threshold' => $threshold,
        '@total' => count($signers),
      ]) . '</p>',
    ];

    if (!empty($signers)) {
      $build['signers']['content']['list'] = [
        '#theme' => 'item_list',
        '#items' => array_map(function($signer) {
          return [
            '#type' => 'markup',
            '#markup' => '<span class="signer-icon">👤</span> <span class="signer-address">' . $signer . '</span>',
          ];
        }, $signers),
        '#attributes' => ['class' => ['signers-list']],
      ];
    }
    else {
      $build['signers']['content']['empty'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('No signers configured') . '</p>',
      ];
    }

    // Transactions section - wrapped in card.
    $build['transactions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card', 'transactions-card']],
    ];

    $build['transactions']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card__block']],
    ];

    $build['transactions']['content']['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['transactions-header', 'clearfix']],
    ];

    $build['transactions']['content']['header']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => $this->t('Transactions'),
      '#attributes' => ['class' => ['card__title', 'pull-left']],
    ];

    if ($can_propose) {
      $build['transactions']['content']['header']['actions'] = [
        '#type' => 'actions',
        '#attributes' => ['class' => ['pull-right']],
        'propose' => [
          '#type' => 'link',
          '#title' => $this->t('Propose Transaction'),
          '#url' => Url::fromRoute('group_treasury.propose_transaction', ['group' => $group->id()]),
          '#attributes' => ['class' => ['button', 'button--primary', 'btn', 'btn-primary']],
        ],
      ];
    }

    if (!empty($transactions)) {
      $transaction_items = [];
      foreach ($transactions as $transaction) {
        $value_eth = $transaction->getValue() ? number_format($transaction->getValue() / 1000000000000000000, 6) : '0.000000';
        $nonce = $transaction->get('nonce')->value;
        $created = $transaction->get('created')->value;

        $item = [
          '#type' => 'container',
          '#attributes' => [
            'class' => [
              'transaction-item',
              'card',
              'card--transaction',
              'transaction-status-' . $transaction->getStatus(),
            ],
          ],
        ];

        $item['header'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['transaction-header', 'clearfix']],
        ];

        // Create transaction ID link.
        $tx_link = Url::fromRoute('group_treasury.transaction_view', [
          'group' => $group->id(),
          'safe_transaction' => $transaction->id(),
        ]);

        $item['header']['id'] = [
          '#type' => 'markup',
          '#markup' => '<div class="transaction-id pull-left">' .
            '<a href="' . $tx_link->toString() . '"><strong>' . $this->t('TX #@id', ['@id' => $transaction->id()]) . '</strong></a> ' .
            ($nonce !== NULL ? '<span class="transaction-nonce">(' . $this->t('Nonce: @nonce', ['@nonce' => $nonce]) . ')</span>' : '') .
            '</div>',
        ];

        $item['header']['status'] = [
          '#type' => 'markup',
          '#markup' => '<div class="transaction-status pull-right"><span class="badge status-' . $transaction->getStatus() . '">' . ucfirst($transaction->getStatus()) . '</span></div>',
        ];

        $item['details'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['transaction-details']],
        ];

        $item['details']['to'] = [
          '#type' => 'item',
          '#title' => $this->t('To:'),
          '#markup' => '<code>' . $transaction->getToAddress() . '</code>',
        ];

        $item['details']['amount'] = [
          '#type' => 'item',
          '#title' => $this->t('Amount:'),
          '#markup' => '<strong>' . $value_eth . ' ETH</strong>',
        ];

        // Add description if present
        $description = $transaction->get('description')->value;
        if (!empty($description)) {
          $item['details']['description'] = [
            '#type' => 'item',
            '#title' => $this->t('Description:'),
            '#markup' => nl2br(htmlspecialchars($description)),
          ];
        }

        $item['details']['created'] = [
          '#type' => 'item',
          '#title' => $this->t('Created:'),
          '#markup' => \Drupal::service('date.formatter')->format($created, 'short'),
        ];

        // Add signature info
        $signatures = $transaction->getSignatures();
        $signature_count = count($signatures);
        $item['details']['signatures'] = [
          '#type' => 'item',
          '#title' => $this->t('Signatures:'),
          '#markup' => $this->t('@count of @threshold', [
            '@count' => $signature_count,
            '@threshold' => $threshold,
          ]),
        ];

        // Add action buttons based on transaction status and permissions
        $status = $transaction->getStatus();
        $item['actions'] = [
          '#type' => 'actions',
          '#attributes' => ['class' => ['transaction-actions']],
        ];

        // Sign button - show if user can sign and transaction is pending
        if ($can_sign && $status === 'pending') {
          $item['actions']['sign'] = [
            '#type' => 'button',
            '#value' => $this->t('Sign'),
            '#attributes' => [
              'class' => ['button', 'button--small', 'btn', 'btn-default', 'safe-transaction-sign'],
              'data-safe-account-id' => $treasury->id(),
              'data-transaction-id' => $transaction->id(),
            ],
          ];
        }

        // Execute button - show if ready to execute
        if ($can_execute && $status === 'pending' && $signature_count >= $threshold && $transaction->isNextExecutable()) {
          $item['actions']['execute'] = [
            '#type' => 'button',
            '#value' => $this->t('Execute'),
            '#attributes' => [
              'class' => ['button', 'button--small', 'button--primary', 'btn', 'btn-primary', 'safe-transaction-execute'],
              'data-safe-account-id' => $treasury->id(),
              'data-transaction-id' => $transaction->id(),
            ],
          ];
        }

        $transaction_items[] = $item;
      }

      $build['transactions']['content']['list'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['transactions-list']],
        'items' => $transaction_items,
      ];
    }
    else {
      $build['transactions']['content']['empty'] = [
        '#type' => 'markup',
        '#markup' => '<p class="text-muted">' . $this->t('No transactions yet') . '</p>',
      ];
    }

    // Add libraries for styling and transaction management.
    $build['#attached']['library'][] = 'group_treasury/treasury_tab';
    $build['#attached']['library'][] = 'safe_smart_accounts/transaction_manager';

    $build['#cache'] = [
      'tags' => ['group:' . $group->id(), 'safe_account:' . $treasury->id()],
      'contexts' => ['user.group_permissions', 'user'],
    ];

    return $build;
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

  /**
   * View a specific treasury transaction.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   * @param \Drupal\safe_smart_accounts\Entity\SafeTransactionInterface $safe_transaction
   *   The transaction entity.
   *
   * @return array
   *   A render array.
   */
  public function viewTransaction(GroupInterface $group, $safe_transaction): array {
    // Load the transaction entity if it's just an ID.
    if (!is_object($safe_transaction)) {
      $transaction_storage = $this->entityTypeManager()->getStorage('safe_transaction');
      $safe_transaction = $transaction_storage->load($safe_transaction);
    }

    if (!$safe_transaction) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $treasury = $this->treasuryService->getTreasury($group);
    if (!$treasury) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('Treasury not found');
    }

    // Check permissions.
    $current_user = $this->currentUser();
    $membership = $group->getMember($current_user);
    $can_sign = $membership && $membership->hasPermission('sign group_treasury transactions');
    $can_execute = $membership && $membership->hasPermission('execute group_treasury transactions');

    // Get threshold from configuration.
    $config_storage = $this->entityTypeManager()->getStorage('safe_configuration');
    $config = $config_storage->load('safe_' . $treasury->id());
    $threshold = $config ? $config->getThreshold() : $treasury->getThreshold();

    $build = [];

    // Attach transaction manager library for signing/executing.
    $build['#attached']['library'][] = 'safe_smart_accounts/transaction_manager';

    // Back to Treasury link.
    $build['back_link'] = [
      '#type' => 'link',
      '#title' => $this->t('← Back to Treasury'),
      '#url' => Url::fromRoute('group_treasury.treasury', ['group' => $group->id()]),
      '#attributes' => ['class' => ['back-link', 'btn', 'btn-default']],
      '#weight' => -100,
    ];

    // Transaction details card.
    $build['transaction_details'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card', 'transaction-details-card']],
    ];

    $build['transaction_details']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card__block']],
    ];

    $build['transaction_details']['content']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => $this->t('Transaction #@id', ['@id' => $safe_transaction->id()]),
      '#attributes' => ['class' => ['card__title']],
    ];

    $value_eth = number_format((float) $safe_transaction->getValue() / 1e18, 6);
    $status = $safe_transaction->getStatus();
    $nonce = $safe_transaction->get('nonce')->value;

    $build['transaction_details']['content']['details'] = [
      '#theme' => 'item_list',
      '#list_type' => 'dl',
      '#items' => [
        [
          '#type' => 'container',
          'term' => ['#markup' => '<dt>' . $this->t('To Address:') . '</dt>'],
          'value' => ['#markup' => '<dd><code>' . $safe_transaction->getToAddress() . '</code></dd>'],
        ],
        [
          '#type' => 'container',
          'term' => ['#markup' => '<dt>' . $this->t('Amount:') . '</dt>'],
          'value' => ['#markup' => '<dd><strong>' . $value_eth . ' ETH</strong></dd>'],
        ],
        [
          '#type' => 'container',
          'term' => ['#markup' => '<dt>' . $this->t('Nonce:') . '</dt>'],
          'value' => ['#markup' => '<dd>' . ($nonce !== NULL ? $nonce : $this->t('Not set')) . '</dd>'],
        ],
        [
          '#type' => 'container',
          'term' => ['#markup' => '<dt>' . $this->t('Status:') . '</dt>'],
          'value' => ['#markup' => '<dd><span class="badge status-' . $status . '">' . ucfirst($status) . '</span></dd>'],
        ],
      ],
      '#attributes' => ['class' => ['transaction-details-list']],
    ];

    // Add description if present.
    $description = $safe_transaction->get('description')->value;
    if (!empty($description)) {
      $build['transaction_details']['content']['description'] = [
        '#type' => 'item',
        '#title' => $this->t('Description:'),
        '#markup' => '<div class="transaction-description">' . nl2br(htmlspecialchars($description)) . '</div>',
      ];
    }

    // Signatures section.
    $signatures = $safe_transaction->getSignatures();
    $signature_count = count($signatures);

    $build['signatures'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card', 'signatures-card']],
    ];

    $build['signatures']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card__block']],
    ];

    $build['signatures']['content']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h3',
      '#value' => $this->t('Signatures (@count of @threshold)', [
        '@count' => $signature_count,
        '@threshold' => $threshold,
      ]),
      '#attributes' => ['class' => ['card__title']],
    ];

    if (!empty($signatures)) {
      $signature_rows = [];
      $signer_resolver = \Drupal::service('safe_smart_accounts.user_signer_resolver');

      foreach ($signatures as $index => $sig) {
        $signer_address = $sig['signer'] ?? '';
        $signer_label = $signer_resolver->formatSignerLabel($signer_address);
        $signed_at = isset($sig['signed_at']) ? \Drupal::service('date.formatter')->format($sig['signed_at'], 'short') : '';

        $signature_rows[] = [
          ($index + 1),
          $signer_label,
          $signed_at,
        ];
      }

      $build['signatures']['content']['table'] = [
        '#type' => 'table',
        '#header' => [$this->t('#'), $this->t('Signer'), $this->t('Signed At')],
        '#rows' => $signature_rows,
      ];
    }
    else {
      $build['signatures']['content']['empty'] = [
        '#markup' => '<p class="text-muted">' . $this->t('No signatures collected yet.') . '</p>',
      ];
    }

    // Action buttons.
    $build['actions'] = [
      '#type' => 'actions',
      '#weight' => 100,
    ];

    // Show warning messages if transaction can't be executed.
    if ($status === 'pending' && !$safe_transaction->isNextExecutable()) {
      $build['actions']['blocked_message'] = [
        '#markup' => '<div class="messages messages--warning">' .
          $this->t('This transaction cannot be executed yet. Transactions must be executed in sequential nonce order.') .
          '</div>',
        '#weight' => -10,
      ];
    }
    elseif ($status === 'pending' && $signature_count < $threshold) {
      $build['actions']['blocked_message'] = [
        '#markup' => '<div class="messages messages--warning">' .
          $this->t('This transaction needs @needed more signature(s) before it can be executed. (Currently @current of @threshold)', [
            '@needed' => $threshold - $signature_count,
            '@current' => $signature_count,
            '@threshold' => $threshold,
          ]) .
          '</div>',
        '#weight' => -10,
      ];
    }

    // Sign button.
    if ($can_sign && !in_array($status, ['executed', 'cancelled'], TRUE)) {
      $build['actions']['sign'] = [
        '#type' => 'button',
        '#value' => $this->t('Sign Transaction'),
        '#attributes' => [
          'class' => ['button', 'button--primary', 'btn', 'btn-primary', 'safe-transaction-sign'],
          'data-safe-account-id' => $treasury->id(),
          'data-transaction-id' => $safe_transaction->id(),
        ],
      ];
    }

    // Execute button.
    if ($can_execute && $safe_transaction->canExecute() && !$safe_transaction->isExecuted()) {
      $build['actions']['execute'] = [
        '#type' => 'button',
        '#value' => $this->t('Execute Transaction'),
        '#attributes' => [
          'class' => ['button', 'button--action', 'btn', 'btn-success', 'safe-transaction-execute'],
          'data-safe-account-id' => $treasury->id(),
          'data-transaction-id' => $safe_transaction->id(),
        ],
      ];
    }

    // Add library for styling.
    $build['#attached']['library'][] = 'group_treasury/treasury_tab';

    $build['#cache'] = [
      'tags' => [
        'group:' . $group->id(),
        'safe_account:' . $treasury->id(),
        'safe_transaction:' . $safe_transaction->id(),
      ],
      'contexts' => ['user.group_permissions', 'user'],
    ];

    return $build;
  }

}
