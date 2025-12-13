<?php

namespace Drupal\group_treasury\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\group\Entity\GroupInterface;

/**
 * Access control handler for treasury operations.
 */
class TreasuryAccessControlHandler implements AccessInterface {

  /**
   * Check access for treasury view route.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function accessView(GroupInterface $group, AccountInterface $account): AccessResultInterface {
    return $this->checkTreasuryAccess($group, $account, 'view');
  }

  /**
   * Check access for treasury manage routes.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function accessManage(GroupInterface $group, AccountInterface $account): AccessResultInterface {
    return $this->checkTreasuryAccess($group, $account, 'manage');
  }

  /**
   * Check access for treasury propose route.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function accessPropose(GroupInterface $group, AccountInterface $account): AccessResultInterface {
    return $this->checkTreasuryAccess($group, $account, 'propose');
  }

  /**
   * Check access for viewing/managing a specific treasury transaction.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user account.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function accessTransaction(GroupInterface $group, AccountInterface $account, RouteMatchInterface $route_match): AccessResultInterface {
    // User must have sign OR execute permission to view transactions.
    // Use GroupPermissionChecker to properly handle member, outsider, and
    // anonymous role permissions.
    $permission_checker = \Drupal::service('group_permission.checker');
    $can_sign = $permission_checker->hasPermissionInGroup('sign group_treasury transactions', $account, $group);
    $can_execute = $permission_checker->hasPermissionInGroup('execute group_treasury transactions', $account, $group);

    if (!$can_sign && !$can_execute) {
      return AccessResult::forbidden('User does not have permission to view transactions')
        ->addCacheContexts(['user.group_permissions', 'route.group']);
    }

    // Verify transaction belongs to this group's treasury.
    $transaction = $route_match->getParameter('safe_transaction');
    if ($transaction) {
      $treasury_service = \Drupal::service('group_treasury.treasury_service');
      $treasury = $treasury_service->getTreasury($group);

      if (!$treasury || $transaction->getSafeAccount()->id() !== $treasury->id()) {
        return AccessResult::forbidden('Transaction does not belong to this group treasury')
          ->addCacheContexts(['user.group_permissions', 'route.group'])
          ->addCacheTags(['group:' . $group->id()]);
      }
    }

    return AccessResult::allowed()
      ->addCacheContexts(['user.group_permissions', 'route.group'])
      ->addCacheTags(['group:' . $group->id()]);
  }

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
  protected function checkTreasuryAccess(GroupInterface $group, AccountInterface $account, string $operation): AccessResultInterface {
    // Map operations to module-level Group permissions.
    // These permissions are defined in group_treasury.group.permissions.yml and
    // can be configured per Group Type via the Group permissions UI.
    $permission_map = [
      'view' => 'view group_treasury',
      'propose' => 'propose group_treasury transactions',
      'manage' => 'manage group_treasury',
    ];

    if (!isset($permission_map[$operation])) {
      return AccessResult::forbidden('Invalid treasury operation')
        ->addCacheContexts(['user.permissions']);
    }

    // Use GroupPermissionChecker to properly handle member, outsider, and
    // anonymous role permissions. This allows outsiders to view treasuries
    // when the permission is granted to the Outsider role.
    $permission_checker = \Drupal::service('group_permission.checker');
    $has_permission = $permission_checker->hasPermissionInGroup($permission_map[$operation], $account, $group);

    return AccessResult::allowedIf($has_permission)
      ->addCacheContexts(['user.group_permissions', 'route.group'])
      ->addCacheTags(['group:' . $group->id()]);
  }

}
