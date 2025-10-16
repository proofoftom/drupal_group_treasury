<?php

namespace Drupal\group_treasury\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group_treasury\Service\GroupTreasuryService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for proposing a treasury transaction.
 */
class TreasuryTransactionProposeForm extends FormBase {

  /**
   * The group treasury service.
   *
   * @var \Drupal\group_treasury\Service\GroupTreasuryService
   */
  protected GroupTreasuryService $treasuryService;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a TreasuryTransactionProposeForm object.
   *
   * @param \Drupal\group_treasury\Service\GroupTreasuryService $treasury_service
   *   The treasury service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    GroupTreasuryService $treasury_service,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    $this->treasuryService = $treasury_service;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('group_treasury.treasury_service'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'group_treasury_transaction_propose_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?GroupInterface $group = NULL): array {
    if (!$group) {
      $form['error'] = [
        '#markup' => $this->t('Invalid group specified.'),
      ];
      return $form;
    }

    $form_state->set('group', $group);

    $treasury = $this->treasuryService->getTreasury($group);
    if (!$treasury) {
      $form['error'] = [
        '#markup' => $this->t('This group does not have a treasury.'),
      ];
      return $form;
    }

    // Check if treasury is active.
    if ($treasury->getStatus() !== 'active') {
      $form['error'] = [
        '#markup' => $this->t('The treasury must be deployed before you can create transactions.'),
      ];
      return $form;
    }

    $form_state->set('treasury', $treasury);

    $form['#tree'] = TRUE;

    $form['description_text'] = [
      '#type' => 'markup',
      '#markup' => '<div class="form-description">' .
      '<h3>' . $this->t('Propose Transaction from @group Treasury', ['@group' => $group->label()]) . '</h3>' .
      '<p>' . $this->t('This transaction will require @threshold signatures to execute.', [
        '@threshold' => $treasury->getThreshold(),
      ]) . '</p>' .
      '</div>',
    ];

    $form['to_address'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Recipient Address'),
      '#required' => TRUE,
      '#description' => $this->t('Ethereum address to send funds to (0x...)'),
      '#placeholder' => '0x742d35Cc6634C0532925a3b8D8938d9e1Aac5C63',
      '#size' => 60,
    ];

    $form['value'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Amount (ETH)'),
      '#required' => TRUE,
      '#description' => $this->t('Amount of ETH to send (e.g., 0.1)'),
      '#placeholder' => '0.0',
      '#default_value' => '0',
    ];

    $form['data'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Data (optional)'),
      '#description' => $this->t('Hex-encoded data for contract interaction. Leave empty for simple ETH transfers.'),
      '#placeholder' => '0x',
      '#rows' => 3,
    ];

    $form['operation'] = [
      '#type' => 'radios',
      '#title' => $this->t('Operation Type'),
      '#options' => [
        '0' => $this->t('Call (standard transaction)'),
        '1' => $this->t('DelegateCall (advanced - use with caution)'),
      ],
      '#default_value' => '0',
      '#description' => $this->t('Call is for standard transactions. DelegateCall executes code in the Safe\'s context.'),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#required' => TRUE,
      '#description' => $this->t('Brief description of this transaction for other signers'),
      '#rows' => 3,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Propose Transaction'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $group->toUrl('canonical'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();

    // Validate Ethereum address.
    $to_address = trim($values['to_address']);
    if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $to_address)) {
      $form_state->setErrorByName('to_address', $this->t('Invalid Ethereum address format.'));
    }

    // Validate value.
    $value = $values['value'];
    if (!is_numeric($value) || (float) $value < 0) {
      $form_state->setErrorByName('value', $this->t('Value must be a positive number.'));
    }

    // Validate data if provided.
    $data = trim($values['data'] ?? '');
    if (!empty($data) && $data !== '0x') {
      if (!preg_match('/^0x[a-fA-F0-9]*$/', $data)) {
        $form_state->setErrorByName('data', $this->t('Data must be valid hex format (0x...)'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $group = $form_state->get('group');
    $treasury = $form_state->get('treasury');

    if (!$group || !$treasury) {
      $this->messenger()->addError($this->t('Unable to determine group or treasury context.'));
      return;
    }

    try {
      // Get the next nonce for this Safe.
      $transaction_storage = $this->entityTypeManager->getStorage('safe_transaction');
      $query = $transaction_storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('safe_account', $treasury->id())
        ->condition('nonce', NULL, 'IS NOT NULL')
        ->sort('nonce', 'DESC')
        ->range(0, 1);

      $result = $query->execute();
      $next_nonce = 0;
      if (!empty($result)) {
        $last_tx = $transaction_storage->load(reset($result));
        $next_nonce = $last_tx->getNonce() + 1;
      }

      // Convert ETH value to Wei.
      $value_in_wei = bcmul($values['value'], '1000000000000000000', 0);

      // Normalize data.
      $data = trim($values['data'] ?? '');
      if (empty($data) || $data === '0x') {
        $data = '0x';
      }

      // Create SafeTransaction entity.
      $transaction = $transaction_storage->create([
        'safe_account' => $treasury->id(),
        'to_address' => strtolower(trim($values['to_address'])),
        'value' => $value_in_wei,
        'data' => $data,
        'operation' => (int) $values['operation'],
        'nonce' => $next_nonce,
        'status' => 'pending',
        'created_by' => $this->currentUser()->id(),
        'description' => trim($values['description']),
      ]);
      $transaction->save();

      $this->messenger()->addStatus($this->t('Transaction proposal created successfully. Nonce: @nonce', [
        '@nonce' => $next_nonce,
      ]));

      // Redirect to treasury tab.
      $form_state->setRedirect('group_treasury.treasury', ['group' => $group->id()]);

    }
    catch (\Exception $e) {
      \Drupal::logger('group_treasury')->error('Failed to create transaction proposal: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('An error occurred while creating the transaction proposal. Please try again.'));
    }
  }

}
