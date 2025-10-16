<?php

namespace Drupal\group_treasury\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Access control handler for treasury operations.
 */
class TreasuryAccessControlHandler {

  /**
   * Check if user has treasury access for the given operation.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   * @param string $operation
   *   The operation to check: view, propose, sign, execute, manage.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function checkTreasuryAccess(GroupInterface $group, AccountInterface $account, string $operation): AccessResultInterface {
    // Map operations to module permissions.
    $permission_map = [
      'view' => 'view group_treasury',
      'propose' => 'propose group_treasury transactions',
      'sign' => 'sign group_treasury transactions',
      'execute' => 'execute group_treasury transactions',
      'manage' => 'manage group_treasury',
    ];

    if (!isset($permission_map[$operation])) {
      return AccessResult::forbidden('Invalid treasury operation')
        ->addCacheContexts(['user.permissions']);
    }

    $membership = $group->getMember($account);
    if (!$membership) {
      return AccessResult::forbidden('User is not a group member')
        ->addCacheContexts(['user', 'route.group']);
    }

    // Check if member has the required permission.
    $has_permission = $membership->hasPermission($permission_map[$operation]);

    return AccessResult::allowedIf($has_permission)
      ->addCacheContexts(['user.group_permissions', 'route.group'])
      ->addCacheTags(['group:' . $group->id()]);
  }

}
