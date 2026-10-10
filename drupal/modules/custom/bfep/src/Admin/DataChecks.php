<?php

declare(strict_types=1);

namespace Drupal\bfep\Admin;

/**
 * Campaign data checks: records that are missing details or look wrong.
 *
 * Each check is a fixed SQL condition used inside
 * "FROM campaigns WHERE deleted_at IS NULL AND ...", so it can name campaigns
 * columns directly. Nothing from the request reaches the SQL: callers look a
 * check up by key and get NULL for an unknown one.
 */
final class DataChecks {

  /**
   * All checks, in display order.
   *
   * @return array<string, array{label: string, help: string, sql: string}>
   *   Check definitions keyed by machine name.
   */
  public static function all(): array {
    $active = 'SELECT 1 FROM campaign_fundraisers f WHERE f.campaign_id = campaigns.id AND f.is_active';
    $sameUrl = AdminFormat::urlKeySql('g.url') . ' = ' . AdminFormat::urlKeySql('f.url');

    return [
      'no_fundraiser' => [
        'label' => 'No active fundraiser',
        'help' => 'Visitors have nothing to donate to. Add the fundraiser, or check whether the campaign should still be listed.',
        'sql' => "NOT EXISTS ({$active})",
      ],
      'shared_url' => [
        'label' => 'Fundraiser shared with another campaign',
        'help' => 'Two campaigns point at the same fundraiser, which usually means one is a duplicate.',
        'sql' => "EXISTS ({$active} AND COALESCE(TRIM(f.url), '') <> '' AND EXISTS ("
        . 'SELECT 1 FROM campaign_fundraisers g JOIN campaigns c2 ON c2.id = g.campaign_id AND c2.deleted_at IS NULL '
        . "WHERE g.is_active AND g.campaign_id <> campaigns.id AND {$sameUrl}))",
      ],
      'bad_url' => [
        'label' => 'Fundraiser link is not a web address',
        'help' => 'The link is empty, contains spaces or line breaks (often the fundraiser title pasted in), or has no domain. The donate button cannot work and the sync skips it. Replace it with the fundraiser address.',
        'sql' => "EXISTS ({$active} AND (COALESCE(TRIM(f.url), '') = '' OR TRIM(f.url) ~ '\\s' OR POSITION('.' IN TRIM(f.url)) = 0))",
      ],
      'duplicate_line' => [
        'label' => 'Line number used twice',
        'help' => 'Searching for the line number cannot open the right campaign. Give one of them a new line number.',
        'sql' => 'line_number IN (SELECT c2.line_number FROM campaigns c2 WHERE c2.deleted_at IS NULL AND c2.line_number IS NOT NULL GROUP BY c2.line_number HAVING COUNT(*) > 1)',
      ],
      'no_line' => [
        'label' => 'No line number',
        'help' => 'Staff and visitors cannot find it by line number.',
        'sql' => 'line_number IS NULL',
      ],
      'no_country' => [
        'label' => 'No country',
        'help' => 'It does not appear on any country page.',
        'sql' => 'country_id IS NULL',
      ],
      'no_description' => [
        'label' => 'No public description',
        'help' => 'The campaign page says nothing about who the fundraiser is for.',
        'sql' => "COALESCE(TRIM(description), '') = ''",
      ],
      'no_goal' => [
        'label' => 'Fundraiser has no goal',
        'help' => 'The campaign page cannot show a progress bar.',
        'sql' => "EXISTS ({$active} AND (f.goal_amount IS NULL OR f.goal_amount <= 0))",
      ],
      'no_currency' => [
        'label' => 'Amounts without a currency',
        'help' => 'Amounts show without a currency, and the sync skips them (currency_unknown).',
        'sql' => "EXISTS ({$active} AND COALESCE(TRIM(f.currency_code), '') = '' AND (f.goal_amount IS NOT NULL OR f.donated_amount IS NOT NULL))",
      ],
      'funded' => [
        'label' => 'Fully funded',
        'help' => 'Raised has reached the goal. Check whether it should stay featured or urgent, or whether the goal has gone up.',
        'sql' => "EXISTS ({$active} AND f.goal_amount > 0 AND f.donated_amount >= f.goal_amount)",
      ],
      'stale' => [
        'label' => 'Not edited in 6 months',
        'help' => 'Check that the fundraiser is still running and the details are current. Automatic amount updates do not count as edits.',
        'sql' => "updated_at < NOW() - INTERVAL '6 months'",
      ],
    ];
  }

  /**
   * The definition of one check, or NULL when the key is unknown.
   *
   * @return array{label: string, help: string, sql: string}|null
   *   The check definition.
   */
  public static function get(mixed $key): ?array {
    return is_string($key) ? (self::all()[$key] ?? NULL) : NULL;
  }

  /**
   * Check labels keyed by machine name, for select lists.
   *
   * @return array<string, string>
   *   Labels in display order.
   */
  public static function options(): array {
    return array_map(static fn(array $check): string => $check['label'], self::all());
  }

  /**
   * One query that counts the campaigns each check flags, plus the total.
   *
   * The result has one column per check key and a "flagged" column counting
   * campaigns flagged by at least one check.
   */
  public static function countSql(): string {
    $columns = [];
    $any = [];
    foreach (self::all() as $key => $check) {
      $columns[] = "COUNT(*) FILTER (WHERE {$check['sql']}) AS {$key}";
      $any[] = "({$check['sql']})";
    }
    $columns[] = 'COUNT(*) FILTER (WHERE ' . implode(' OR ', $any) . ') AS flagged';
    return 'SELECT ' . implode(",\n", $columns) . "\nFROM campaigns WHERE deleted_at IS NULL";
  }

}
