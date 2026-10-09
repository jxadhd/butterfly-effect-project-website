<?php

declare(strict_types=1);

namespace Drupal\bfep\Service;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Records who changed which BFEP records, without copying private values.
 *
 * Entries go to the "bfep" log channel (Reports > Recent log messages). Only
 * field names are logged: submissions hold personal information that must not
 * be duplicated into Drupal's watchdog table.
 */
final class AuditLogger {

  public function __construct(
    private readonly LoggerChannelInterface $logger,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * Logs one staff write.
   *
   * @param string $action
   *   A short verb such as "created" or "updated".
   * @param string $type
   *   The record type, for example "campaign" or "referral".
   * @param int $id
   *   The external record ID.
   * @param string[] $fields
   *   Names of the fields that changed.
   */
  public function record(string $action, string $type, int $id, array $fields = []): void {
    $fields = array_values(array_unique(array_filter(array_map('strval', $fields))));
    $this->logger->notice('@user @action @type #@id. Fields: @fields.', [
      '@user' => $this->currentUser->getAccountName() ?: 'uid ' . $this->currentUser->id(),
      '@action' => $action,
      '@type' => $type,
      '@id' => $id,
      '@fields' => $fields ? implode(', ', $fields) : 'none',
    ]);
  }

}
