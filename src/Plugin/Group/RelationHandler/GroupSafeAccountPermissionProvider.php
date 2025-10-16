<?php

namespace Drupal\group_treasury\Plugin\Group\RelationHandler;

use Drupal\group\Plugin\Group\RelationHandler\PermissionProviderInterface;
use Drupal\group\Plugin\Group\RelationHandler\PermissionProviderTrait;

/**
 * Provides permissions for the GroupSafeAccount relation plugin.
 */
class GroupSafeAccountPermissionProvider implements PermissionProviderInterface {
  use PermissionProviderTrait;

  /**
   * {@inheritdoc}
   */
  public function getPermissions() {
    $permissions = [];
    $plugin_id = $this->pluginId;

    $permissions["view $plugin_id entity"] = [
      'title' => 'View group treasury',
    ];

    $permissions["create $plugin_id entity"] = [
      'title' => 'Create group treasury',
      'description' => 'Add a Safe Smart Account as the group treasury',
    ];

    $permissions["delete $plugin_id entity"] = [
      'title' => 'Remove group treasury',
      'description' => 'Remove the treasury relationship (does not delete Safe)',
    ];

    return $permissions;
  }

}
