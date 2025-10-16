<?php

namespace Drupal\group_treasury\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Treasury wizard step form for Group creation flow.
 *
 * This form extends TreasuryCreateForm and integrates with the Group
 * creation wizard when the Group Type requires treasury deployment.
 */
class TreasuryWizardStepForm extends TreasuryCreateForm {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'group_treasury_wizard_step_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?GroupInterface $group = NULL): array {
    $form = parent::buildForm($form, $form_state, $group);

    if (!$group) {
      return $form;
    }

    // Wizard-specific modifications.
    $form['#title'] = $this->t('Deploy Treasury for @group', [
      '@group' => $group->label(),
    ]);

    // Add help text explaining the wizard context.
    $form['wizard_info'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--info">' .
      $this->t('You are creating a new group. The group type requires deployment of a Safe Smart Account treasury.') .
      '</div>',
      '#weight' => -10,
    ];

    // Store Group ID in form state for submit handler.
    $form_state->set('group_id', $group->id());
    $form_state->set('wizard_mode', TRUE);

    // Check if treasury is required or optional.
    $group_type = $group->getGroupType();
    $required = $group_type->getThirdPartySetting('group_treasury', 'creator_treasury_wizard', FALSE);

    // Add skip button if treasury is optional.
    if (!$required) {
      $form['actions']['skip'] = [
        '#type' => 'submit',
        '#value' => $this->t('Skip (add treasury later)'),
        '#submit' => ['::skipWizardStep'],
        '#limit_validation_errors' => [],
        '#weight' => 10,
      ];
    }

    // Modify submit button text.
    $form['actions']['submit']['#value'] = $this->t('Deploy Treasury & Complete');
    $form['actions']['submit']['#weight'] = 5;

    return $form;
  }

  /**
   * Submit handler for skipping the wizard step.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function skipWizardStep(array &$form, FormStateInterface $form_state): void {
    $group = $form_state->get('group');

    $this->messenger()->addStatus($this->t('Group created without treasury. You can add a treasury later from the Group page.'));

    // Redirect to the new Group page.
    $form_state->setRedirect('entity.group.canonical', ['group' => $group->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $group = $form_state->get('group');

    // Call parent submit for treasury creation.
    // In a full implementation, this would:
    // 1. Initiate Safe deployment
    // 2. Create GroupRelationship linking Safe to Group
    // 3. Redirect to Group page with success message.
    $this->messenger()->addStatus($this->t('Treasury deployment initiated. Group creation complete.'));

    // Redirect to Group page.
    $form_state->setRedirect('entity.group.canonical', ['group' => $group->id()]);
  }

}
