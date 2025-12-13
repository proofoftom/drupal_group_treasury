<?php

namespace Drupal\group_treasury\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\CssCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Ajax\SettingsCommand;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\group\Entity\GroupInterface;
use Drupal\group_treasury\Service\GroupTreasuryService;
use Drupal\safe_smart_accounts\Form\SafeAccountFormTrait;
use Drupal\safe_smart_accounts\Service\UserSignerResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for creating a treasury for an existing group.
 */
class TreasuryCreateForm extends FormBase {

  use SafeAccountFormTrait;

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

    // Add AJAX wrapper for inline deployment
    $form['#prefix'] = '<div id="treasury-create-form-wrapper">';
    $form['#suffix'] = '</div>';

    // Attach treasury deployment library (includes JS, depends on safe_deployment for CSS and helpers)
    $form['#attached']['library'][] = 'group_treasury/treasury_deployment';

    // Deployment status container (hidden by default, shown after form submit)
    $form['deployment_status'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'safe-deployment-status-container',
        'class' => ['safe-deployment-status'],
        'style' => 'display: none;',
      ],
      '#weight' => -5,
    ];

    $form['deployment_status']['progress'] = [
      '#markup' => '
        <div class="deployment-progress">
          <h3>' . $this->t('Deploying Group Treasury') . '</h3>
          <div class="progress-steps">
            <div class="step" id="step-1" data-status="pending">
              <span class="step-icon">⏳</span>
              <span class="step-text">' . $this->t('Saving treasury configuration...') . '</span>
              <span class="step-details"></span>
            </div>
            <div class="step" id="step-2" data-status="pending">
              <span class="step-icon">🔐</span>
              <span class="step-text">' . $this->t('Waiting for transaction signature...') . '</span>
              <span class="step-details"></span>
            </div>
            <div class="step" id="step-3" data-status="pending">
              <span class="step-icon">🚀</span>
              <span class="step-text">' . $this->t('Waiting for blockchain confirmation...') . '</span>
              <span class="step-details"></span>
            </div>
            <div class="step" id="step-4" data-status="pending">
              <span class="step-icon">✅</span>
              <span class="step-text">' . $this->t('Deployment complete!') . '</span>
              <span class="step-details"></span>
            </div>
          </div>
        </div>
      ',
    ];

    $form['description'] = [
      '#markup' => '<div class="treasury-create-description">' .
      '<h3>' . $this->t('Deploy Treasury for @group', ['@group' => $group->label()]) . '</h3>' .
      '<p>' . $this->t('A Safe Smart Account will be deployed as this group\'s multi-signature treasury. Group admins will automatically be added as signers.') . '</p>' .
      '</div>',
    ];

    // Use treasury-specific network field wrapper
    $form['network'] = $this->buildTreasuryNetworkField();

    // Use trait method for threshold field
    $form['threshold'] = $this->buildThresholdField();

    // Get group admin members and pre-populate signers
    $admin_signers = $this->getGroupAdminSigners($group);

    // Use trait method for signers fieldset with treasury customizations
    $form['signers'] = $this->buildSignersFieldset($form, $form_state, [
      'description' => $this->t('Group admins with Ethereum addresses are automatically included as signers.'),
      'show_primary_signer' => FALSE,  // Don't show primary signer for treasuries
      'admin_signers' => $admin_signers,
      'admin_signers_title' => $this->t('Group Admin Signers'),
      'show_admin_warning' => TRUE,  // Show warning if no admin signers
    ]);

    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Options'),
      '#open' => FALSE,
    ];

    // Use trait method for salt nonce field
    $form['advanced']['salt_nonce'] = $this->buildSaltNonceField();

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Deploy Treasury'),
      '#button_type' => 'primary',
      '#ajax' => [
        'callback' => '::submitFormAjax',
        'wrapper' => 'treasury-create-form-wrapper',
        'progress' => [
          'type' => 'none', // We'll show custom progress
        ],
      ],
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $group->toUrl(),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  // AJAX callbacks for signer management now provided by SafeAccountFormTrait

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
    $additional_signers = $this->parseSignerAddresses(
      $values['signers']['additional_signers'] ?? [],
      $this->signerResolver
    );
    $all_signers = array_merge($admin_signers, $additional_signers);

    if (empty($all_signers)) {
      $form_state->setErrorByName('signers', $this->t('At least one signer is required.'));
      return;
    }

    // Validate threshold using trait method
    $threshold = (int) $values['threshold'];
    $total_signers = count($all_signers);
    $this->validateThreshold($form_state, 'threshold', $threshold, $total_signers);

    // Validate additional signer addresses using trait method
    foreach ($additional_signers as $address) {
      $this->validateEthereumAddress($form_state, 'signers][additional_signers', $address);
    }

    // Check for duplicate addresses.
    $lowercase_signers = array_map('strtolower', $all_signers);
    if (count($lowercase_signers) !== count(array_unique($lowercase_signers))) {
      $form_state->setErrorByName('signers][additional_signers', $this->t('Duplicate signer addresses are not allowed.'));
    }

    // Validate salt nonce using trait method
    $salt_nonce = $values['advanced']['salt_nonce'] ?? '';
    $this->validateSaltNonce($form_state, 'advanced][salt_nonce', $salt_nonce);
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
      // Gather all signers using trait method (pass UserSignerResolver)
      $admin_signers = $this->getGroupAdminSigners($group);
      $additional_signers = $this->parseSignerAddresses(
        $values['signers']['additional_signers'] ?? [],
        $this->signerResolver
      );
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
      // Store the created Safe account ID for AJAX handler
      $form_state->set('created_safe_account_id', $safe_account->id());

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

      $this->messenger()->addStatus($this->t('Treasury created successfully!'));

      // Skip redirect for AJAX submissions - JavaScript will handle deployment and redirect
      $triggering_element = $form_state->getTriggeringElement();
      if (!isset($triggering_element['#ajax'])) {
        // Redirect to the treasury tab (only for non-AJAX submissions)
        $form_state->setRedirect('group_treasury.treasury', [
          'group' => $group->id(),
        ]);
      }

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

    // Define possible admin role patterns
    // Standard Group module uses: {bundle}-admin
    // Open Social uses: {bundle}-group_manager
    $bundle = $group->bundle();
    $admin_role_patterns = [
      $bundle . '-admin',
      $bundle . '-group_manager',
    ];

    foreach ($memberships as $membership) {
      $member = $membership->getUser();

      // Check if member has any admin role pattern.
      $roles = $membership->getRoles();
      $is_admin = FALSE;
      foreach ($roles as $role) {
        if (in_array($role->id(), $admin_role_patterns, TRUE)) {
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

  // parseSignerAddresses() and validation methods now provided by SafeAccountFormTrait

  /**
   * AJAX callback for form submission.
   *
   * Handles inline deployment by creating entities and triggering JavaScript deployment.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The AJAX response.
   */
  public function submitFormAjax(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();

    // If validation errors, return form with errors
    if ($form_state->hasAnyErrors()) {
      return $response->addCommand(new ReplaceCommand('#treasury-create-form-wrapper', $form));
    }

    // NOTE: Don't call $this->submitForm() here!
    // Drupal automatically calls submitForm() before calling this AJAX callback.
    // Calling it again would create duplicate entities and cause "Group already has treasury" error.

    // Get the created Safe account ID and Group from form_state storage
    // (These were set by submitForm() which Drupal already called)
    $safe_account_id = $form_state->get('created_safe_account_id');
    $group = $form_state->get('group');

    if (!$safe_account_id || !$group) {
      // Error occurred, return form with error message
      $this->messenger()->addError($this->t('Failed to create treasury.'));
      return $response->addCommand(new ReplaceCommand('#treasury-create-form-wrapper', $form));
    }

    // Use proper Drupal AJAX commands (no arbitrary JS execution)
    // Hide form elements via CSS
    $response->addCommand(new CssCommand('.treasury-create-description', ['display' => 'none']));
    $response->addCommand(new CssCommand('#treasury-create-form-wrapper fieldset', ['display' => 'none']));
    $response->addCommand(new CssCommand('.form-actions', ['display' => 'none']));

    // Hide specific form fields (Network, Threshold, Signers, Advanced Options)
    $response->addCommand(new CssCommand('[name="network"]', ['display' => 'none']));
    $response->addCommand(new CssCommand('.js-form-item-network', ['display' => 'none']));
    $response->addCommand(new CssCommand('[name="threshold"]', ['display' => 'none']));
    $response->addCommand(new CssCommand('.js-form-item-threshold', ['display' => 'none']));
    $response->addCommand(new CssCommand('#edit-signers', ['display' => 'none']));
    $response->addCommand(new CssCommand('#edit-advanced', ['display' => 'none']));

    // Show deployment status container
    $response->addCommand(new CssCommand('#safe-deployment-status-container', ['display' => 'block']));

    // Pass deployment trigger and data to JavaScript via drupalSettings
    // A Drupal behavior will handle step updates and trigger deployment
    $response->addCommand(new SettingsCommand([
      'groupTreasury' => [
        'triggerDeployment' => TRUE,
        'safeAccountId' => $safe_account_id,
        'groupId' => $group->id(),
      ],
    ], FALSE));

    return $response;
  }

}
