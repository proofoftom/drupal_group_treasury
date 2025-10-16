<?php

namespace Drupal\group_treasury\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\group_treasury\Service\GroupTreasuryService;
use Drupal\group_treasury\Service\TreasuryAccessibilityChecker;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for reconnecting an inaccessible treasury.
 */
class TreasuryReconnectForm extends FormBase {

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
   * Constructs a TreasuryReconnectForm object.
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
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'group_treasury_reconnect_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?GroupInterface $group = NULL): array {
    if (!$group) {
      return ['#markup' => $this->t('Invalid group.')];
    }

    $treasury = $this->treasuryService->getTreasury($group);
    if (!$treasury) {
      return ['#markup' => $this->t('No treasury found.')];
    }

    $form_state->set('group', $group);
    $form_state->set('treasury', $treasury);

    $form['current_address'] = [
      '#type' => 'item',
      '#title' => $this->t('Current treasury address'),
      '#markup' => $treasury->getSafeAddress(),
    ];

    $form['network'] = [
      '#type' => 'select',
      '#title' => $this->t('Network'),
      '#options' => [
        'sepolia' => $this->t('Sepolia Testnet'),
        'mainnet' => $this->t('Ethereum Mainnet'),
      ],
      '#default_value' => $treasury->getNetwork(),
    ];

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Verify and Reconnect'),
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $group = $form_state->get('group');
    $treasury = $form_state->get('treasury');

    // Verify Safe accessibility.
    $verification = $this->accessibilityChecker->verifySafeAddress(
      $treasury->getSafeAddress(),
      $form_state->getValue('network')
    );

    if ($verification['valid']) {
      $this->messenger()->addStatus($this->t('Treasury reconnected successfully.'));
    }
    else {
      $this->messenger()->addError($this->t('Unable to reconnect: @error', [
        '@error' => $verification['error'],
      ]));
    }

    $form_state->setRedirect('group_treasury.treasury', ['group' => $group->id()]);
  }

}
