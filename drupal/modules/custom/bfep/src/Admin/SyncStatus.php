<?php

declare(strict_types=1);

namespace Drupal\bfep\Admin;

/**
 * What the nightly fundraiser sync's statuses mean, in staff words.
 *
 * The sync (bfep-sync) writes campaign_fundraisers.sync_status. The wording
 * matches `bfep sync problems` (bfep_sync/repair.py); change both together.
 */
final class SyncStatus {

  /**
   * Statuses where the sync read the platform but did not save its figures.
   */
  public const HELD = ['suspicious_drop', 'currency_mismatch'];

  /**
   * Short label, what happened and what to do, keyed by status.
   */
  private const STATUSES = [
    'ok' => ['Updated', 'The last check updated the figures.', ''],
    'suspicious_drop' => [
      'Held: big drop',
      'Raised fell by more than 20% since the last update, so the sync did not save the new figure.',
      'Compare with the fundraiser page. If the platform’s figure is right, use it. If it is not, leave it: the sync keeps holding it and saves again once the page recovers.',
    ],
    'currency_mismatch' => [
      'Held: other currency',
      'The page states a different currency and goal, so the sync did not save the new figures.',
      'Check the page. If its currency is right, use its figures.',
    ],
    'currency_unknown' => [
      'No currency',
      'The page shows only “$” and no currency is stored, so the sync cannot tell which dollar it is.',
      'Set the currency code above.',
    ],
    'parse_error' => [
      'Unreadable page',
      'The page loaded but the sync could not read its figures.',
      'Check the page. If several fundraisers on one platform show this, the platform changed its page: tell Josh.',
    ],
    'not_found' => [
      'Page not found',
      'The page was not found 3 nights in a row, so the sync stopped updating it.',
      'Fix the link, add the new fundraiser if the campaign moved, or end this one.',
    ],
    'not_found_pending' => [
      'Not found (retrying)',
      'The page was not found, but fewer than 3 nights in a row.',
      'Usually nothing yet. Check the link if it looks wrong.',
    ],
    'invalid_url' => [
      'Bad link',
      'The link is not a fundraiser address the sync understands.',
      'Correct the link.',
    ],
    'not_configured' => [
      'Not set up',
      'The sync for this platform is missing a setting on the server.',
      'Tell Josh.',
    ],
  ];

  /**
   * A short label for tables, such as "Held: big drop".
   */
  public static function label(?string $status): string {
    $status = trim((string) $status);
    if ($status === '') {
      return 'Not checked yet';
    }
    return self::STATUSES[$status][0] ?? 'Platform error (' . $status . ')';
  }

  /**
   * What happened and what to do (the second may be empty).
   *
   * @return array{0: string, 1: string}
   *   The explanation and the advice.
   */
  public static function explain(?string $status): array {
    $status = trim((string) $status);
    if (isset(self::STATUSES[$status])) {
      return [self::STATUSES[$status][1], self::STATUSES[$status][2]];
    }
    return [
      'The platform answered with an error (' . $status . ').',
      'Nothing to do unless it keeps happening for days.',
    ];
  }

  /**
   * Whether the sync read figures it did not save.
   */
  public static function isHeld(?string $status): bool {
    return in_array(trim((string) $status), self::HELD, TRUE);
  }

  /**
   * Figures as "USD 1,200.00 of 10,000.00 (12%)", or without a goal "USD 1,200.00".
   */
  public static function figures(mixed $raised, mixed $goal, ?string $currency): string {
    $currency = trim((string) $currency);
    $text = trim($currency . ' ' . (AdminFormat::isBlank($raised) ? '—' : number_format((float) $raised, 2)));
    if (!AdminFormat::isBlank($goal) && (float) $goal > 0) {
      $text .= ' of ' . number_format((float) $goal, 2);
      if (!AdminFormat::isBlank($raised)) {
        $text .= ' (' . round((float) $raised / (float) $goal * 100) . '%)';
      }
    }
    return $text;
  }

}
