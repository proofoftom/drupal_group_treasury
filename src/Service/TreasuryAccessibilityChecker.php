<?php

namespace Drupal\group_treasury\Service;

use Drupal\safe_smart_accounts\Entity\SafeAccountInterface;
use Drupal\safe_smart_accounts\Service\SafeApiService;

/**
 * Service for checking treasury Safe accessibility.
 */
class TreasuryAccessibilityChecker {

  /**
   * The Safe API service.
   *
   * @var \Drupal\safe_smart_accounts\Service\SafeApiService
   */
  protected SafeApiService $safeApiService;

  /**
   * Constructs a TreasuryAccessibilityChecker object.
   *
   * @param \Drupal\safe_smart_accounts\Service\SafeApiService $safe_api_service
   *   The Safe API service.
   */
  public function __construct(SafeApiService $safe_api_service) {
    $this->safeApiService = $safe_api_service;
  }

  /**
   * Check if treasury Safe is accessible.
   *
   * @param \Drupal\safe_smart_accounts\Entity\SafeAccountInterface $safe_account
   *   The Safe account entity.
   *
   * @return array
   *   Array with accessibility info:
   *   - accessible: (bool) Whether the Safe is accessible.
   *   - safe_info: (array) Safe information if accessible.
   *   - balance: (string) Safe balance if accessible.
   *   - threshold: (int) Signature threshold if accessible.
   *   - owners: (array) List of owner addresses if accessible.
   *   - error: (string) Error message if not accessible.
   *   - error_code: (int) Error code if not accessible.
   *   - recovery_options: (array) Recovery options if not accessible.
   */
  public function checkAccessibility(SafeAccountInterface $safe_account): array {
    try {
      $safe_info = $this->safeApiService->getSafeInfo($safe_account->getSafeAddress());

      return [
        'accessible' => TRUE,
        'safe_info' => $safe_info,
        'balance' => $safe_info['balance'] ?? '0',
        'threshold' => $safe_info['threshold'] ?? 1,
        'owners' => $safe_info['owners'] ?? [],
      ];
    }
    catch (\Exception $e) {
      return [
        'accessible' => FALSE,
        'error' => $e->getMessage(),
        'error_code' => $e->getCode(),
        'recovery_options' => ['reconnect', 'create_new'],
      ];
    }
  }

  /**
   * Verify if a Safe address is valid and accessible.
   *
   * @param string $safe_address
   *   The Safe address to verify.
   * @param string $network
   *   The network to check on.
   *
   * @return array
   *   Array with verification results:
   *   - valid: (bool) Whether the address is valid and accessible.
   *   - safe_info: (array) Safe information if valid.
   *   - error: (string) Error message if invalid.
   */
  public function verifySafeAddress(string $safe_address, string $network): array {
    try {
      $safe_info = $this->safeApiService->getSafeInfo($safe_address);

      return [
        'valid' => TRUE,
        'safe_info' => $safe_info,
      ];
    }
    catch (\Exception $e) {
      return [
        'valid' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
  }

}
