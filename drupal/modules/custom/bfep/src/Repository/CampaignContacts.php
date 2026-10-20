<?php

declare(strict_types=1);

namespace Drupal\bfep\Repository;

use Drupal\Core\Database\Connection;
use Drupal\bfep\Admin\PgEnum;
use Drupal\bfep\Admin\SocialLinks;

/**
 * A campaign's contact and social media links (bfdb campaign_social_media).
 *
 * The site login could only add these rows at first. Reading, changing and
 * removing them needs sql/campaign-contact-links.sql (run once as the schema
 * owner), which also gives the table an id. Until then access() says what
 * the site may do and the edit form shows only what it can.
 */
final class CampaignContacts {

  /**
   * What the site login may do with the table.
   *
   * @return array{read: bool, write: bool}
   *   read: rows can be listed. write: rows can be changed and removed.
   */
  public static function access(Connection $database): array {
    try {
      $row = $database->query("
        SELECT has_table_privilege('campaign_social_media', 'SELECT') AS can_read,
          has_table_privilege('campaign_social_media', 'UPDATE')
            AND has_table_privilege('campaign_social_media', 'DELETE')
            AND has_table_privilege('campaign_social_media', 'INSERT') AS can_write,
          EXISTS (
            SELECT 1 FROM pg_attribute
            WHERE attrelid = CAST('campaign_social_media' AS regclass)
              AND attname = 'id' AND NOT attisdropped
          ) AS has_id
      ")->fetchObject();
    }
    catch (\Throwable) {
      return ['read' => FALSE, 'write' => FALSE];
    }
    $yes = static fn(mixed $value): bool => in_array($value, [TRUE, 1, '1', 't', 'true'], TRUE);
    $read = $yes($row->can_read ?? NULL);
    return ['read' => $read, 'write' => $read && $yes($row->can_write ?? NULL) && $yes($row->has_id ?? NULL)];
  }

  /**
   * The platforms a link can be filed under, as enum label => name.
   */
  public static function platforms(Connection $database): array {
    $labels = PgEnum::labels($database, 'campaign_social_media', 'platform')
      ?? ['instagram', 'facebook', 'tiktok', 'telegram', 'twitter', 'youtube', 'discord', 'website', 'other'];
    $options = [];
    foreach ($labels as $label) {
      $options[$label] = SocialLinks::label($label);
    }
    return $options;
  }

  /**
   * A campaign's links, in the order platforms are listed.
   *
   * @return object[]
   *   Rows with id (NULL before the SQL script), platform, handle_or_url and
   *   is_active, keyed by id when there is one.
   */
  public static function rows(Connection $database, int $campaignId, bool $hasId): array {
    $rows = $database->query('
      SELECT ' . ($hasId ? 'id' : 'NULL AS id') . ', CAST(platform AS TEXT) AS platform, handle_or_url, is_active
      FROM campaign_social_media
      WHERE campaign_id = :id
      ORDER BY campaign_social_media.platform, campaign_social_media.handle_or_url', [':id' => $campaignId])->fetchAll();
    if (!$hasId) {
      return $rows;
    }
    $keyed = [];
    foreach ($rows as $row) {
      $keyed[(int) $row->id] = $row;
    }
    return $keyed;
  }

  /**
   * Saves the edited links and returns whether anything changed.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The bfdb connection.
   * @param int $campaignId
   *   The campaign.
   * @param object[] $current
   *   The rows the form was built from, keyed by id.
   * @param array<int, array{platform: string, value: string, active: bool}> $existing
   *   Edited existing rows keyed by id. An empty value removes the row.
   * @param array<int, array{platform: string, value: string, active: bool}> $new
   *   New rows; empty ones are skipped.
   */
  public static function save(Connection $database, int $campaignId, array $current, array $existing, array $new): bool {
    $changed = FALSE;
    foreach ($current as $id => $row) {
      $edit = $existing[$id] ?? NULL;
      if ($edit === NULL) {
        continue;
      }
      if ($edit['value'] === '') {
        $database->query('DELETE FROM campaign_social_media WHERE id = :id AND campaign_id = :campaign', [':id' => $id, ':campaign' => $campaignId]);
        $changed = TRUE;
        continue;
      }
      $wasActive = in_array($row->is_active, [TRUE, 1, '1', 't', 'true'], TRUE);
      if ($edit['platform'] === (string) $row->platform && $edit['value'] === (string) $row->handle_or_url && $edit['active'] === $wasActive) {
        continue;
      }
      $database->query(
        'UPDATE campaign_social_media
        SET platform = CAST(:platform AS social_platform), handle_or_url = :value, is_active = :active
        WHERE id = :id AND campaign_id = :campaign',
        self::args($campaignId, $edit) + [':id' => $id],
      );
      $changed = TRUE;
    }
    foreach ($new as $add) {
      if ($add['value'] === '') {
        continue;
      }
      $database->query(
        'INSERT INTO campaign_social_media (campaign_id, platform, handle_or_url, is_active)
        VALUES (:campaign, CAST(:platform AS social_platform), :value, :active)',
        self::args($campaignId, $add),
      );
      $changed = TRUE;
    }
    return $changed;
  }

  /**
   * Query arguments for one link.
   */
  private static function args(int $campaignId, array $link): array {
    return [
      ':campaign' => $campaignId,
      ':platform' => $link['platform'],
      ':value' => $link['value'],
      ':active' => $link['active'] ? 'true' : 'false',
    ];
  }

}
