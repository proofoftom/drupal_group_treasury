<?php

namespace Drupal\group_treasury\Service;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\group\Entity\GroupInterface;
use Drupal\safe_smart_accounts\Entity\SafeAccountInterface;

/**
 * Service for managing group treasury relationships.
 */
class GroupTreasuryService {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The cache tags invalidator.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface
   */
  protected CacheTagsInvalidatorInterface $cacheTagsInvalidator;

  /**
   * Constructs a GroupTreasuryService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cache_tags_invalidator
   *   The cache tags invalidator.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    CacheTagsInvalidatorInterface $cache_tags_invalidator,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->cacheTagsInvalidator = $cache_tags_invalidator;
  }

  /**
   * Get the treasury Safe account for a Group.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   *
   * @return \Drupal\safe_smart_accounts\Entity\SafeAccountInterface|null
   *   The Safe account entity, or NULL if no treasury exists.
   */
  public function getTreasury(GroupInterface $group): ?SafeAccountInterface {
    $relationships = $group->getRelationships('group_safe_account:safe_account');
    if (empty($relationships)) {
      return NULL;
    }

    /** @var \Drupal\group\Entity\GroupRelationshipInterface $relationship */
    $relationship = reset($relationships);
    return $relationship->getEntity();
  }

  /**
   * Check if Group has a treasury.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   *
   * @return bool
   *   TRUE if the group has a treasury, FALSE otherwise.
   */
  public function hasTreasury(GroupInterface $group): bool {
    return !empty($group->getRelationships('group_safe_account:safe_account'));
  }

  /**
   * Add Safe account as Group treasury.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   * @param \Drupal\safe_smart_accounts\Entity\SafeAccountInterface $safe_account
   *   The Safe account to add as treasury.
   *
   * @throws \RuntimeException
   *   If the group already has a treasury.
   */
  public function addTreasury(GroupInterface $group, SafeAccountInterface $safe_account): void {
    if ($this->hasTreasury($group)) {
      throw new \RuntimeException('Group already has a treasury');
    }

    $group->addRelationship($safe_account, 'group_safe_account:safe_account');

    // Invalidate the group's cache tags to ensure treasury tab updates.
    $this->cacheTagsInvalidator->invalidateTags($group->getCacheTags());
  }

  /**
   * Remove treasury from Group.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   */
  public function removeTreasury(GroupInterface $group): void {
    $relationships = $group->getRelationships('group_safe_account:safe_account');
    foreach ($relationships as $relationship) {
      $relationship->delete();
    }
  }

  /**
   * Get all Groups that use a Safe account as treasury.
   *
   * @param \Drupal\safe_smart_accounts\Entity\SafeAccountInterface $safe_account
   *   The Safe account entity.
   *
   * @return \Drupal\group\Entity\GroupInterface[]
   *   Array of group entities.
   */
  public function getGroupsForTreasury(SafeAccountInterface $safe_account): array {
    // Note: Entity type is 'group_content' even in Group 2.x
    // (database tables use 'group_relationship' naming).
    $relationship_storage = $this->entityTypeManager->getStorage('group_content');
    $relationships = $relationship_storage->loadByProperties([
      'entity_id' => $safe_account->id(),
      'plugin_id' => 'group_safe_account:safe_account',
    ]);

    $groups = [];
    foreach ($relationships as $relationship) {
      $groups[] = $relationship->getGroup();
    }

    return $groups;
  }

  /**
   * Get the treasury relationship entity for a Group.
   *
   * @param \Drupal\group\Entity\GroupInterface $group
   *   The group entity.
   *
   * @return \Drupal\group\Entity\GroupRelationshipInterface|null
   *   The relationship entity, or NULL if none exists.
   */
  public function getTreasuryRelationship(GroupInterface $group) {
    $relationships = $group->getRelationships('group_safe_account:safe_account');
    return empty($relationships) ? NULL : reset($relationships);
  }

}
