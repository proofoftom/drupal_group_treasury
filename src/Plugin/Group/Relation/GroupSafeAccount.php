<?php

namespace Drupal\group_treasury\Plugin\Group\Relation;

use Drupal\Core\Form\FormStateInterface;
use Drupal\group\Plugin\Group\Relation\GroupRelationBase;

/**
 * Provides a group relation for Safe Smart Accounts (treasury).
 *
 * @GroupRelationType(
 *   id = "group_safe_account",
 *   entity_type_id = "safe_account",
 *   label = @Translation("Group Safe Account (Treasury)"),
 *   description = @Translation("Links a Safe Smart Account as the group treasury"),
 *   reference_label = @Translation("Safe Address"),
 *   reference_description = @Translation("The Safe Smart Account to use as treasury"),
 *   entity_access = TRUE,
 *   deriver = "Drupal\group_treasury\Plugin\Group\Relation\GroupSafeAccountDeriver"
 * )
 */
class GroupSafeAccount extends GroupRelationBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $config = parent::defaultConfiguration();
    // Enforce 1:1 relationship - one treasury per Group.
    $config['entity_cardinality'] = 1;
    return $config;
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    // Disable entity cardinality to enforce 1:1 relationship.
    $form['entity_cardinality']['#disabled'] = TRUE;
    $form['entity_cardinality']['#description'] .= '<br /><em>' .
      $this->t('Each group can have exactly one treasury Safe account.') .
      '</em>';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    $dependencies = parent::calculateDependencies();
    // No bundle dependency for safe_account (no bundles).
    return $dependencies;
  }

}
