<?php

namespace Drupal\group_treasury\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\group_treasury\Service\GroupTreasuryService;
use Drupal\safe_smart_accounts\Service\UserSignerResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for creating a treasury for an existing group.
 */
class TreasuryCreateForm extends FormBase {

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
   * The user signer resolver service.
   *
   * @var \Drupal\safe_smart_accounts\Service\UserSignerResolver
   */
  protected UserSignerResolver $signerResolver;

  /**
   * Constructs a TreasuryCreateForm object.
   *
   * @param \Drupal\group_treasury\Service\GroupTreasuryService $treasury_service
   *   The treasury service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\safe_smart_accounts\Service\UserSignerResolver $signer_resolver
   *   The user signer resolver service.
   */
  public function __construct(
    GroupTreasuryService $treasury_service,
    EntityTypeManagerInterface $entity_type_manager,
    UserSignerResolver $signer_resolver,
  ) {
    $this->treasuryService = $treasury_service;
    $this->entityTypeManager = $entity_type_manager;
    $this->signerResolver = $signer_resolver;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('group_treasury.treasury_service'),
      $container->get('entity_type.manager'),
      $container->get('safe_smart_accounts.user_signer_resolver')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'group_treasury_create_form';
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

    // Check if group already has a treasury.
    if ($this->treasuryService->hasTreasury($group)) {
      $this->messenger()->addWarning($this->t('This group already has a treasury.'));
      return [
        '#markup' => $this->t('This group already has a treasury. <a href="@treasury_url">View treasury</a>.', [
          '@treasury_url' => Url::fromRoute('group_treasury.treasury', ['group' => $group->id()])->toString(),
        ]),
      ];
    }

    // Store group in form state.
    $form_state->set('group', $group);

    $form['#tree'] = TRUE;

    $form['description'] = [
      '#markup' => '<div class="treasury-create-description">' .
      '<h3>' . $this->t('Deploy Treasury for @group', ['@group' => $group->label()]) . '</h3>' .
      '<p>' . $this->t('A Safe Smart Account will be deployed as this group\'s multi-signature treasury. Group admins will automatically be added as signers.') . '</p>' .
      '</div>',
    ];

    $form['network'] = [
      '#type' => 'select',
      '#title' => $this->t('Network'),
      '#description' => $this->t('Select the Ethereum network for the treasury Safe.'),
      '#options' => [
        'sepolia' => $this->t('Sepolia Testnet'),
        'hardhat' => $this->t('Hardhat Local'),
      ],
      '#default_value' => 'sepolia',
      '#required' => TRUE,
    ];

    $form['threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Signature Threshold'),
      '#description' => $this->t('Number of signatures required to execute transactions. Must be between 1 and the number of signers.'),
      '#default_value' => 1,
      '#min' => 1,
      '#max' => 10,
      '#required' => TRUE,
    ];

    // Get group admin members and pre-populate signers.
    $admin_signers = $this->getGroupAdminSigners($group);

    $form['signers'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Signers'),
      '#description' => $this->t('Group admins with Ethereum addresses are automatically included as signers.'),
    ];

    if (empty($admin_signers)) {
      $form['signers']['warning'] = [
        '#markup' => '<div class="messages messages--warning">' .
        $this->t('No group admins have Ethereum addresses configured. You must add signers manually.') .
        '</div>',
      ];
    }
    else {
      $form['signers']['admin_signers'] = [
        '#type' => 'item',
        '#title' => $this->t('Group Admin Signers'),
        '#markup' => '<ul><li>' . implode('</li><li>', $admin_signers) . '</li></ul>',
      ];
    }

    // Get the number of additional signer fields from form state.
    $num_signers = $form_state->get('num_signers');
    if ($num_signers === NULL) {
      $num_signers = empty($admin_signers) ? 1 : 0;
      $form_state->set('num_signers', $num_signers);
    }

    if ($num_signers > 0) {
      $form['signers']['additional_signers'] = [
        '#type' => 'container',
        '#title' => $this->t('Additional Signers'),
        '#prefix' => '<div id="signers-fieldset-wrapper">',
        '#suffix' => '</div>',
        '#tree' => TRUE,
      ];

      for ($i = 0; $i < $num_signers; $i++) {
        $form['signers']['additional_signers'][$i] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['signer-field-row']],
        ];

        $form['signers']['additional_signers'][$i]['address'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Signer @num', ['@num' => $i + 1]),
          '#description' => $i === 0 ? $this->t('Enter a username or Ethereum address. Start typing a username to see suggestions.') : '',
          '#placeholder' => 'alice or 0x742d35Cc6634C0532925a3b8D8938d9e1Aac5C63',
          '#autocomplete_route_name' => 'safe_smart_accounts.signer_autocomplete',
          '#size' => 60,
        ];

        $form['signers']['additional_signers'][$i]['remove'] = [
          '#type' => 'submit',
          '#value' => $this->t('Remove'),
          '#submit' => ['::removeSignerField'],
          '#ajax' => [
            'callback' => '::updateSignerFieldsCallback',
            'wrapper' => 'signers-fieldset-wrapper',
          ],
          '#name' => 'remove_signer_' . $i,
          '#signer_delta' => $i,
          '#attributes' => ['class' => ['button--small', 'button--danger']],
        ];
      }
    }
    else {
      $form['signers']['additional_signers'] = [
        '#prefix' => '<div id="signers-fieldset-wrapper">',
        '#suffix' => '</div>',
      ];
    }

    $form['signers']['add_signer'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add another signer'),
      '#submit' => ['::addSignerField'],
      '#ajax' => [
        'callback' => '::updateSignerFieldsCallback',
        'wrapper' => 'signers-fieldset-wrapper',
      ],
      '#attributes' => ['class' => ['button--small']],
    ];

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Options'),
      '#open' => FALSE,
    ];

    $form['advanced']['salt_nonce'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Salt Nonce'),
      '#description' => $this->t('Optional salt nonce for deterministic Safe address generation. Leave empty for random generation.'),
      '#placeholder' => '0',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Deploy Treasury'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $group->toUrl(),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * AJAX callback to add a signer field.
   */
  public function addSignerField(array &$form, FormStateInterface $form_state): void {
    $num_signers = $form_state->get('num_signers');
    $num_signers++;
    $form_state->set('num_signers', $num_signers);
    $form_state->setRebuild();
  }

  /**
   * AJAX callback to remove a signer field.
   */
  public function removeSignerField(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $delta = $trigger['#signer_delta'];

    // Get current values.
    $values = $form_state->getUserInput();
    $signers = $values['signers']['additional_signers'] ?? [];

    // Remove the signer at this delta.
    unset($signers[$delta]);

    // Re-index the array.
    $signers = array_values($signers);

    // Update form state.
    $values['signers']['additional_signers'] = $signers;
    $form_state->setUserInput($values);

    // Decrease the count.
    $num_signers = $form_state->get('num_signers');
    if ($num_signers > 1) {
      $num_signers--;
      $form_state->set('num_signers', $num_signers);
    }

    $form_state->setRebuild();
  }

  /**
   * AJAX callback to return updated signer fields.
   */
  public function updateSignerFieldsCallback(array &$form, FormStateInterface $form_state): array {
    return $form['signers']['additional_signers'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();

    // Skip validation if this is an AJAX request.
    $triggering_element = $form_state->getTriggeringElement();
    if (isset($triggering_element['#ajax'])) {
      return;
    }

    $group = $form_state->get('group');
    $admin_signers = $this->getGroupAdminSigners($group);
    $additional_signers = $this->parseSignerAddresses($values['signers']['additional_signers'] ?? []);
    $all_signers = array_merge($admin_signers, $additional_signers);

    if (empty($all_signers)) {
      $form_state->setErrorByName('signers', $this->t('At least one signer is required.'));
      return;
    }

    // Validate threshold.
    $threshold = (int) $values['threshold'];
    $total_signers = count($all_signers);

    if ($threshold > $total_signers) {
      $form_state->setErrorByName('threshold', $this->t('Threshold (@threshold) cannot be greater than the number of signers (@signers).', [
        '@threshold' => $threshold,
        '@signers' => $total_signers,
      ]));
    }

    if ($threshold < 1) {
      $form_state->setErrorByName('threshold', $this->t('Threshold must be at least 1.'));
    }

    // Validate additional signer addresses.
    foreach ($additional_signers as $address) {
      if (!$this->isValidEthereumAddress($address)) {
        $form_state->setErrorByName('signers][additional_signers', $this->t('Invalid Ethereum address: @address', [
          '@address' => $address,
        ]));
      }
    }

    // Check for duplicate addresses.
    $lowercase_signers = array_map('strtolower', $all_signers);
    if (count($lowercase_signers) !== count(array_unique($lowercase_signers))) {
      $form_state->setErrorByName('signers][additional_signers', $this->t('Duplicate signer addresses are not allowed.'));
    }

    // Validate salt nonce if provided.
    $salt_nonce = $values['advanced']['salt_nonce'] ?? '';
    if (!empty($salt_nonce) && (!is_numeric($salt_nonce) || (int) $salt_nonce < 0)) {
      $form_state->setErrorByName('advanced][salt_nonce', $this->t('Salt nonce must be a non-negative integer.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $group = $form_state->get('group');

    if (!$group) {
      $this->messenger()->addError($this->t('Unable to determine group context.'));
      return;
    }

    try {
      // Gather all signers.
      $admin_signers = $this->getGroupAdminSigners($group);
      $additional_signers = $this->parseSignerAddresses($values['signers']['additional_signers'] ?? []);
      $all_signers = array_merge($admin_signers, $additional_signers);

      // Create SafeAccount entity owned by the group creator.
      $safe_account_storage = $this->entityTypeManager->getStorage('safe_account');
      $safe_account = $safe_account_storage->create([
        'user_id' => $this->currentUser()->id(),
        'network' => $values['network'],
        'threshold' => (int) $values['threshold'],
        'status' => 'pending',
      ]);
      $safe_account->save();

      // Get salt_nonce value, generate unique value if not provided.
      // Use timestamp to ensure each Safe gets a unique nonce for CREATE2.
      $salt_nonce = !empty($values['advanced']['salt_nonce']) ? (int) $values['advanced']['salt_nonce'] : time();

      // Create SafeConfiguration entity.
      $safe_config_storage = $this->entityTypeManager->getStorage('safe_configuration');
      $safe_config = $safe_config_storage->create([
        'id' => 'safe_' . $safe_account->id(),
        'label' => $this->t('Treasury for @group', ['@group' => $group->label()]),
        'safe_account_id' => $safe_account->id(),
        'signers' => $all_signers,
        'threshold' => (int) $values['threshold'],
        'version' => '1.4.1',
        'salt_nonce' => $salt_nonce,
      ]);
      $safe_config->save();

      // Link Safe to Group as treasury.
      $this->treasuryService->addTreasury($group, $safe_account);

      $this->messenger()->addStatus($this->t('Treasury created successfully! The Safe is currently pending deployment.'));

      // Redirect to the treasury tab.
      $form_state->setRedirect('group_treasury.treasury', [
        'group' => $group->id(),
      ]);

    }
    catch (\Exception $e) {
      \Drupal::logger('group_treasury')->error('Failed to create group treasury: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('An error occurred while creating the treasury. Please try again.'));
    }
  }

  /**
   * Gets Ethereum addresses of group admin members.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   *
   * @return array
   *   Array of Ethereum addresses.
   */
  protected function getGroupAdminSigners(GroupInterface $group): array {
    $signers = [];
    $memberships = $group->getMembers();

    foreach ($memberships as $membership) {
      $member = $membership->getUser();

      // Check if member has admin role.
      $roles = $membership->getRoles();
      $is_admin = FALSE;
      foreach ($roles as $role) {
        if ($role->id() === $group->bundle() . '-admin') {
          $is_admin = TRUE;
          break;
        }
      }

      if ($is_admin) {
        // Try to get member's Ethereum address.
        if ($member->hasField('field_ethereum_address')) {
          $address = $member->get('field_ethereum_address')->value;
          if (!empty($address)) {
            $signers[] = $address;
          }
        }
      }
    }

    return array_unique($signers);
  }

  /**
   * Parses signer addresses from field values.
   *
   * @param array $signer_fields
   *   Array of signer field values from the form.
   *
   * @return array
   *   Array of parsed Ethereum addresses.
   */
  protected function parseSignerAddresses(array $signer_fields): array {
    $addresses = [];

    foreach ($signer_fields as $field) {
      $input = trim($field['address'] ?? '');
      if (empty($input)) {
        continue;
      }

      // Try to resolve as username or address.
      $resolved = $this->signerResolver->resolveToAddress($input);
      if ($resolved) {
        $addresses[] = $resolved;
      }
      else {
        // Keep original if not resolvable (will fail validation).
        $addresses[] = $input;
      }
    }

    return array_unique($addresses);
  }

  /**
   * Validates Ethereum address format.
   *
   * @param string $address
   *   The address to validate.
   *
   * @return bool
   *   TRUE if valid, FALSE otherwise.
   */
  protected function isValidEthereumAddress(string $address): bool {
    return preg_match('/^0x[a-fA-F0-9]{40}$/', $address) === 1;
  }

}
