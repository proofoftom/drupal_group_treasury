<?php

namespace Drupal\group_treasury\Plugin\Group\Relation;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\group\Plugin\Group\Relation\GroupRelationTypeInterface;

/**
 * Deriver for GroupSafeAccount plugin.
 *
 * Creates a single derivative since SafeAccount entity has no bundles.
 */
class GroupSafeAccountDeriver extends DeriverBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    assert($base_plugin_definition instanceof GroupRelationTypeInterface);
    $this->derivatives = [];

    // SafeAccount entity has no bundles, but bundle() returns 'safe_account'.
    $this->derivatives['safe_account'] = clone $base_plugin_definition;
    $this->derivatives['safe_account']->set('entity_bundle', 'safe_account');
    $this->derivatives['safe_account']->set('label', $this->t('Group treasury (Safe Smart Account)'));
    $this->derivatives['safe_account']->set('description', $this->t('Manage group funds with a multi-signature Safe Smart Account'));

    return $this->derivatives;
  }

}
