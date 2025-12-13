<?php

namespace Drupal\group_treasury\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group_treasury\Service\GroupTreasuryService;
use Drupal\safe_smart_accounts\Form\SafeTransactionFormTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for proposing a treasury transaction.
 */
class TreasuryTransactionProposeForm extends FormBase {

  use SafeTransactionFormTrait;

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

    // Wrap entire form in card
    $form['#prefix'] = '<div class="card"><div class="card__block">';
    $form['#suffix'] = '</div></div>';

    $form['description_text'] = [
      '#type' => 'markup',
      '#markup' => '<div class="form-description">' .
      '<h3>' . $this->t('Propose Transaction from @group Treasury', ['@group' => $group->label()]) . '</h3>' .
      '<p>' . $this->t('This transaction will require @threshold signatures to execute.', [
        '@threshold' => $treasury->getThreshold(),
      ]) . '</p>' .
      '</div>',
    ];

    // Transaction Details - required fields at top level
    $form['to_address'] = $this->buildTreasuryToAddressField();
    $form['value'] = $this->buildTreasuryValueField();

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#required' => TRUE,
      '#description' => $this->t('Brief description of this transaction for other signers'),
      '#rows' => 3,
    ];

    // Advanced Options (collapsible, closed by default)
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Options'),
      '#open' => FALSE,
      '#attributes' => ['class' => ['social-collapsible-fieldset']],
    ];

    $form['advanced']['operation'] = $this->buildOperationRadiosField();
    $form['advanced']['data'] = $this->buildTreasuryDataField();

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

    // Validate using trait methods
    $to_address = trim($values['to_address']);
    $this->validateToAddress($form_state, 'to_address', $to_address);

    $value = $values['value'];
    $this->validateValue($form_state, 'value', $value);

    // Advanced options may not be present if fieldset is not expanded
    $advanced = $values['advanced'] ?? [];
    $data = trim($advanced['data'] ?? '');
    $this->validateData($form_state, 'advanced][data', $data);
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

    // Extract advanced values with defaults
    $advanced = $values['advanced'] ?? [];

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
        $next_nonce = (int) $last_tx->get('nonce')->value + 1;
      }

      // Convert ETH value to Wei using trait method
      $value_in_wei = $this->ethToWei($values['value']);

      // Normalize data.
      $data = trim($advanced['data'] ?? '');
      if (empty($data) || $data === '0x') {
        $data = '0x';
      }

      // Create SafeTransaction entity.
      $tx = $transaction_storage->create([
        'safe_account' => $treasury->id(),
        'to_address' => strtolower(trim($values['to_address'])),
        'value' => $value_in_wei,
        'data' => $data,
        'operation' => (int) ($advanced['operation'] ?? 0),
        'nonce' => $next_nonce,
        'status' => 'pending',
        'created_by' => $this->currentUser()->id(),
        'description' => trim($values['description']),
      ]);
      $tx->save();

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
