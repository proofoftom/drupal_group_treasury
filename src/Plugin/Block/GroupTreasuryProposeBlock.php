<?php

namespace Drupal\group_treasury\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Provides a 'GroupTreasuryProposeBlock' block.
 *
 * @Block(
 *  id = "group_treasury_propose_block",
 *  admin_label = @Translation("Propose Treasury Transaction"),
 * )
 */
class GroupTreasuryProposeBlock extends BlockBase {

  /**
   * {@inheritdoc}
   *
   * Custom access logic to display the block.
   */
  public function blockAccess(AccountInterface $account) {
    $group = _social_group_get_current_group();

    if (is_object($group)) {
      // Check if user has permission to propose transactions.
      if ($group->hasPermission('propose group_treasury transactions', $account)) {
        // Also verify that the group has an active treasury.
        $group_treasury_service = \Drupal::service('group_treasury.treasury_service');
        $treasury = $group_treasury_service->getTreasury($group);

        if ($treasury && $treasury->getStatus() === 'active') {
          return AccessResult::allowed()
            ->addCacheContexts(['url.path', 'user.group_permissions'])
            ->addCacheTags(['group:' . $group->id(), 'safe_account:' . $treasury->id()]);
        }
      }
    }

    // By default, the block is not visible.
    // Add cache contexts to ensure proper invalidation.
    return AccessResult::forbidden()
      ->addCacheContexts(['url.path', 'user.group_permissions']);
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [];

    $group = _social_group_get_current_group();

    if (is_object($group)) {
      $url = Url::fromRoute('group_treasury.propose_transaction', [
        'group' => $group->id(),
      ]);
      $link_options = [
        'attributes' => [
          'class' => [
            'btn',
            'btn-primary',
            'btn-raised',
            'waves-effect',
            'brand-bg-primary',
          ],
        ],
      ];
      $url->setOptions($link_options);

      $build['content'] = Link::fromTextAndUrl($this->t('Propose Transaction'), $url)->toRenderable();

      // Cache.
      $build['#cache']['contexts'][] = 'url.path';
      $build['#cache']['contexts'][] = 'user.group_permissions';
      $build['#cache']['tags'][] = 'group:' . $group->id();
    }

    return $build;
  }

}
