<?php

namespace Drupal\group_treasury\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\group_treasury\Service\GroupTreasuryService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event subscriber for group role removal.
 *
 * Proposes remove-signer transactions when users lose admin roles.
 */
class GroupRoleRemoveSubscriber implements EventSubscriberInterface {
  use StringTranslationTrait;

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
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * Constructs a GroupRoleRemoveSubscriber object.
   *
   * @param \Drupal\group_treasury\Service\GroupTreasuryService $treasury_service
   *   The treasury service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(
    GroupTreasuryService $treasury_service,
    EntityTypeManagerInterface $entity_type_manager,
    MessengerInterface $messenger,
  ) {
    $this->treasuryService = $treasury_service;
    $this->entityTypeManager = $entity_type_manager;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Note: Actual event name may vary based on Group module version.
    // This is a placeholder implementation - verify event names.
    return [
      // 'group.membership.post_delete' => 'onMembershipDelete',
      // 'group.membership.post_update' => 'onMembershipUpdate',
    ];
  }

  /**
   * Respond to membership deletion.
   *
   * @param object $event
   *   The membership event.
   */
  public function onMembershipDelete($event): void {
    // Implementation will be completed in T031.
    // Proposes removeOwner when member leaves Group.
  }

  /**
   * Respond to membership updates.
   *
   * @param object $event
   *   The membership event.
   */
  public function onMembershipUpdate($event): void {
    // Implementation will be completed in T031.
    // Proposes removeOwner when admin role removed.
    // Checks previous roles vs current roles.
  }

  /**
   * Propose removing a signer from the treasury.
   *
   * @param \Drupal\safe_smart_accounts\Entity\SafeAccountInterface $safe_account
   *   The Safe account.
   * @param \Drupal\Core\Session\AccountInterface $user
   *   The user to remove as signer.
   * @param string $address
   *   The user's blockchain address.
   */
  protected function proposeRemoveSigner($safe_account, $user, string $address): void {
    // This will be implemented in T031.
    // Will create SafeTransaction entity with removeOwner call data.
  }

}
