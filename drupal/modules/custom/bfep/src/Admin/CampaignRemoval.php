<?php

declare(strict_types=1);

namespace Drupal\bfep\Admin;

/**
 * Reasons and internal-note lines for removing and restoring campaigns.
 *
 * Removing a campaign sets campaigns.deleted_at, which hides it from the
 * public site, the staff lists, data checks and the fundraiser sync. Nothing
 * is deleted. Each removal and restore adds a dated line to the campaign's
 * internal notes, so the history stays with the record.
 */
final class CampaignRemoval {

  /**
   * Removal reasons, keyed by machine name.
   */
  public const REASONS = [
    'duplicate' => 'Duplicate of another campaign',
    'ended' => 'Fundraiser has ended or was taken down',
    'requested' => 'The family asked to be removed',
    'other' => 'Other',
  ];

  private const REMOVED_PREFIX = 'Removed from the site on ';

  /**
   * The internal-note line recorded when a campaign is removed.
   */
  public static function removedNote(string $reason, string $details, string $user, string $date): string {
    $line = self::REMOVED_PREFIX . $date . ' by ' . $user . '. Reason: ' . (self::REASONS[$reason] ?? self::REASONS['other']) . '.';
    $details = trim(preg_replace('/\s+/u', ' ', $details) ?? '');
    return $details === '' ? $line : $line . ' ' . $details;
  }

  /**
   * The internal-note line recorded when a campaign is restored.
   */
  public static function restoredNote(string $user, string $date): string {
    return 'Restored to the site on ' . $date . ' by ' . $user . '.';
  }

  /**
   * The reason text of the latest removal line in $notes, or '' if none.
   */
  public static function lastReason(mixed $notes): string {
    $lines = preg_split('/\R/u', (string) ($notes ?? '')) ?: [];
    foreach (array_reverse($lines) as $line) {
      if (str_starts_with($line, self::REMOVED_PREFIX)) {
        $position = strpos($line, 'Reason: ');
        return $position === FALSE ? '' : trim(substr($line, $position + 8));
      }
    }
    return '';
  }

}
