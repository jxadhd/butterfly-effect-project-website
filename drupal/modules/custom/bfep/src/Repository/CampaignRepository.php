<?php

declare(strict_types=1);

namespace Drupal\bfep\Repository;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\bfep\Service\BfepCacheInvalidator;
use Drupal\bfep\Service\BfepSettings;

/**
 * Read model for public campaign data in the external bfdb database.
 */
final class CampaignRepository {

  public function __construct(
    private readonly Connection $database,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
    private readonly BfepSettings $settings,
  ) {}

  public function stats(): array {
    return $this->remember('bfep:stats', function (): array {
      $row = $this->database->query(<<<'SQL'
        SELECT
          COUNT(*) FILTER (WHERE id IS NOT NULL) AS total_campaigns,
          COUNT(*) FILTER (WHERE id IS NOT NULL AND featured_by_bfep = TRUE) AS featured_campaigns,
          COUNT(*) FILTER (WHERE id IS NOT NULL AND urgent_medical_needs = TRUE) AS urgent_campaigns,
          COUNT(DISTINCT country) FILTER (WHERE country IS NOT NULL AND country <> '') AS countries
        FROM v_campaigns
        WHERE id IN (SELECT source.id FROM campaigns source WHERE source.deleted_at IS NULL)
        SQL)->fetchObject();

      return [
        'total_campaigns' => (int) ($row->total_campaigns ?? 0),
        'featured_campaigns' => (int) ($row->featured_campaigns ?? 0),
        'urgent_campaigns' => (int) ($row->urgent_campaigns ?? 0),
        'countries' => (int) ($row->countries ?? 0),
      ];
    }, [BfepCacheInvalidator::HOME, BfepCacheInvalidator::CAMPAIGNS]);
  }

  /**
   * Returns a normalized page of campaigns for already-sanitized filters.
   */
  public function list(array $filters): array {
    $cid = 'bfep:campaign-list:' . hash('sha256', serialize($filters));

    return $this->remember($cid, function () use ($filters): array {
      $where = [
        'id IS NOT NULL',
        'id IN (SELECT source.id FROM campaigns source WHERE source.deleted_at IS NULL)',
      ];
      $params = [];

      $lineNumber = NULL;
      if ($filters['q'] !== '') {
        $textSql = <<<'SQL'
          contact_name ILIKE :q
          OR description ILIKE :q
          OR country ILIKE :q
          OR global_region ILIKE :q
          OR platform ILIKE :q
          OR EXISTS (
            SELECT 1 FROM unnest(tags) AS t
            WHERE t ILIKE :q
          )
          SQL;
        $params[':q'] = '%' . $this->database->escapeLike($filters['q']) . '%';

        // A bare number (optionally "#142") also matches that exact line.
        if (preg_match('/^#?([0-9]{1,9})$/', $filters['q'], $matches)) {
          $lineNumber = (int) $matches[1];
          $textSql .= ' OR line_number = :line_number';
          $params[':line_number'] = $lineNumber;
        }
        $where[] = '(' . $textSql . ')';
      }

      foreach ([
        'country' => 'country',
        'region' => 'global_region',
        'platform' => 'platform',
      ] as $filter => $column) {
        if ($filters[$filter] !== '') {
          $where[] = $column . ' = :' . $filter;
          $params[':' . $filter] = $filters[$filter];
        }
      }

      if ($filters['tag'] !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM unnest(tags) AS t WHERE t = :tag)';
        $params[':tag'] = $filters['tag'];
      }
      if ($filters['featured']) {
        $where[] = 'featured_by_bfep = TRUE';
      }
      if ($filters['urgent']) {
        $where[] = 'urgent_medical_needs = TRUE';
      }

      $sortSql = match ($filters['sort']) {
        'updated_desc' => 'updated_at DESC NULLS LAST, id DESC',
        'created_desc' => 'created_at DESC NULLS LAST, id DESC',
        'name_asc' => 'contact_name ASC NULLS LAST, id ASC',
        'country_asc' => 'country ASC NULLS LAST, contact_name ASC NULLS LAST, id ASC',
        // Campaigns without a goal have no percentage and go last.
        'funded_asc' => 'pct_goal_achieved ASC NULLS LAST, line_number DESC NULLS LAST, id DESC',
        // Nearest to 100% first; fully funded campaigns after the rest.
        'funded_desc' => 'COALESCE(pct_goal_achieved >= 100, TRUE) ASC, pct_goal_achieved DESC NULLS LAST, line_number DESC NULLS LAST, id DESC',
        default => 'line_number DESC NULLS LAST, id DESC',
      };
      if ($lineNumber !== NULL) {
        // Put the exact line match first, whatever the chosen sort.
        $sortSql = 'COALESCE(line_number = :line_number, FALSE) DESC, ' . $sortSql;
      }
      $whereSql = implode(' AND ', $where);
      $limit = (int) $filters['per_page'];
      $offset = ((int) $filters['page'] - 1) * $limit;

      $total = (int) $this->database->query(
        "SELECT COUNT(*) FROM v_campaigns WHERE {$whereSql}",
        $params,
      )->fetchField();

      $rows = $this->database->query(<<<SQL
        SELECT
          id,
          line_number,
          contact_name,
          country,
          global_region,
          description,
          featured_by_bfep,
          urgent_medical_needs,
          career,
          fundraiser_url,
          platform,
          currency_code,
          goal_amount,
          donated_amount,
          pct_goal_achieved,
          tags,
          created_at,
          updated_at
        FROM v_campaigns
        WHERE {$whereSql}
        ORDER BY {$sortSql}
        LIMIT {$limit}
        OFFSET {$offset}
        SQL, $params)->fetchAll();

      return ['total' => $total, 'rows' => $rows];
    }, [BfepCacheInvalidator::CAMPAIGNS], $this->settings->listingCacheMaxAge());
  }

  public function find(int $campaignId): ?object {
    $result = $this->remember(
      'bfep:campaign:' . $campaignId,
      function () use ($campaignId): object|false {
        return $this->database->query(<<<'SQL'
          SELECT
            v.id,
            v.line_number,
            v.contact_name,
            v.country,
            v.global_region,
            v.description,
            v.featured_by_bfep,
            v.urgent_medical_needs,
            v.career,
            v.fundraiser_url,
            v.platform,
            v.currency_code,
            v.goal_amount,
            v.donated_amount,
            v.pct_goal_achieved,
            v.tags,
            v.created_at,
            v.updated_at
          FROM v_campaigns v
          INNER JOIN campaigns source ON source.id = v.id AND source.deleted_at IS NULL
          WHERE v.id = :id
          SQL, [':id' => $campaignId])->fetchObject();
      },
      [BfepCacheInvalidator::CAMPAIGNS, 'bfep:campaign:' . $campaignId],
    );

    return $result === FALSE ? NULL : $result;
  }

  /**
   * Other public campaigns in the same country, for the campaign page.
   *
   * Urgent medical cases first, then campaigns still short of their goal,
   * least funded first.
   */
  public function related(int $campaignId, string $country, int $limit = 3): array {
    if (trim($country) === '') {
      return [];
    }
    $limit = max(1, min(12, $limit));
    return $this->remember(
      'bfep:related:' . $campaignId . ':' . $limit,
      fn(): array => $this->database->query(<<<SQL
        SELECT
          v.id,
          v.line_number,
          v.contact_name,
          v.country,
          v.global_region,
          v.description,
          v.featured_by_bfep,
          v.urgent_medical_needs,
          v.fundraiser_url,
          v.platform,
          v.currency_code,
          v.goal_amount,
          v.donated_amount,
          v.pct_goal_achieved,
          v.tags,
          v.created_at,
          v.updated_at
        FROM v_campaigns v
        INNER JOIN campaigns source ON source.id = v.id AND source.deleted_at IS NULL
        WHERE v.country = :country AND v.id <> :id
        ORDER BY
          COALESCE(v.urgent_medical_needs, FALSE) DESC,
          COALESCE(v.pct_goal_achieved >= 100, FALSE) ASC,
          v.pct_goal_achieved ASC NULLS LAST,
          v.id DESC
        LIMIT {$limit}
        SQL, [':country' => $country, ':id' => $campaignId])->fetchAll(),
      [BfepCacheInvalidator::CAMPAIGNS, 'bfep:campaign:' . $campaignId],
    );
  }

  /**
   * Returns the ID of the one public campaign with this line number.
   *
   * NULL when no campaign, or more than one, has that line.
   */
  public function idForLineNumber(int $lineNumber): ?int {
    $ids = $this->remember(
      'bfep:line-number:' . $lineNumber,
      fn(): array => $this->database->query(<<<'SQL'
        SELECT v.id
        FROM v_campaigns v
        INNER JOIN campaigns source ON source.id = v.id AND source.deleted_at IS NULL
        WHERE v.line_number = :line
        LIMIT 2
        SQL, [':line' => $lineNumber])->fetchCol(),
      [BfepCacheInvalidator::CAMPAIGNS],
    );
    return count($ids) === 1 ? (int) $ids[0] : NULL;
  }

  public function countryCount(string $country): int {
    return (int) $this->remember(
      'bfep:country-count:' . hash('sha256', mb_strtolower($country)),
      fn(): int => (int) $this->database->query(
        'SELECT COUNT(*) FROM v_campaigns v INNER JOIN campaigns source ON source.id = v.id AND source.deleted_at IS NULL WHERE v.country = :country',
        [':country' => $country],
      )->fetchField(),
      [BfepCacheInvalidator::CAMPAIGNS, BfepCacheInvalidator::COUNTRIES],
    );
  }

  public function countries(): array {
    return $this->remember('bfep:countries', fn(): array => $this->database->query(<<<'SQL'
      SELECT
        v.country,
        v.global_region,
        COUNT(*) AS campaign_count,
        COUNT(*) FILTER (WHERE v.featured_by_bfep = TRUE) AS featured_count,
        COUNT(*) FILTER (WHERE v.urgent_medical_needs = TRUE) AS urgent_count
      FROM v_campaigns v
      INNER JOIN campaigns source ON source.id = v.id AND source.deleted_at IS NULL
      WHERE v.country IS NOT NULL AND v.country <> ''
      GROUP BY v.country, v.global_region
      ORDER BY campaign_count DESC, v.country ASC
      SQL)->fetchAll(), [BfepCacheInvalidator::COUNTRIES, BfepCacheInvalidator::CAMPAIGNS]);
  }

  public function canonicalCountry(string $country): ?string {
    $result = $this->remember(
      'bfep:canonical-country:' . hash('sha256', mb_strtolower($country)),
      fn(): string|false => $this->database->query(<<<'SQL'
        SELECT v.country
        FROM v_campaigns v
        INNER JOIN campaigns source ON source.id = v.id AND source.deleted_at IS NULL
        WHERE v.country IS NOT NULL
          AND v.country <> ''
          AND LOWER(v.country) = LOWER(:country)
        GROUP BY v.country
        ORDER BY COUNT(*) DESC, v.country ASC
        LIMIT 1
        SQL, [':country' => $country])->fetchField(),
      [BfepCacheInvalidator::COUNTRIES, BfepCacheInvalidator::CAMPAIGNS],
    );
    return $result === FALSE ? NULL : (string) $result;
  }

  public function filterOptions(): array {
    return $this->remember('bfep:filter-options', function (): array {
      $options = [];
      foreach (['country', 'global_region', 'platform'] as $column) {
        $options[$column] = $this->database->query(<<<SQL
          SELECT DISTINCT v.{$column} AS value
          FROM v_campaigns v
          INNER JOIN campaigns source ON source.id = v.id AND source.deleted_at IS NULL
          WHERE v.{$column} IS NOT NULL AND v.{$column} <> ''
          ORDER BY v.{$column}
          SQL)->fetchCol();
      }
      $options['tag'] = $this->database->query(<<<'SQL'
        SELECT DISTINCT tag AS value
        FROM v_campaigns v
        INNER JOIN campaigns source ON source.id = v.id AND source.deleted_at IS NULL
        CROSS JOIN LATERAL unnest(v.tags) AS expanded(tag)
        WHERE v.tags IS NOT NULL AND tag <> ''
        ORDER BY tag
        SQL)->fetchCol();
      return $options;
    }, [BfepCacheInvalidator::FORM_OPTIONS, BfepCacheInvalidator::CAMPAIGNS]);
  }

  /**
   * Returns lightweight records used only while generating the XML sitemap.
   */
  public function sitemapCampaigns(): array {
    return $this->database->query(<<<'SQL'
      SELECT v.id, COALESCE(v.updated_at, v.created_at) AS lastmod
      FROM v_campaigns v
      INNER JOIN campaigns source ON source.id = v.id AND source.deleted_at IS NULL
      WHERE v.id IS NOT NULL
      ORDER BY v.id
      SQL)->fetchAll();
  }

  public function sitemapCountries(): array {
    return $this->database->query(<<<'SQL'
      SELECT v.country, MAX(COALESCE(v.updated_at, v.created_at)) AS lastmod
      FROM v_campaigns v
      INNER JOIN campaigns source ON source.id = v.id AND source.deleted_at IS NULL
      WHERE v.country IS NOT NULL AND v.country <> ''
      GROUP BY v.country
      ORDER BY v.country
      SQL)->fetchAll();
  }

  private function remember(
    string $cid,
    callable $callback,
    array $tags,
    ?int $maxAge = NULL,
  ): mixed {
    $cached = $this->cache->get($cid);
    if ($cached !== FALSE) {
      return $cached->data;
    }

    $data = $callback();
    $ttl = $maxAge ?? $this->settings->publicCacheMaxAge();
    $this->cache->set(
      $cid,
      $data,
      $this->time->getRequestTime() + $ttl,
      array_values(array_unique($tags)),
    );
    return $data;
  }

}
