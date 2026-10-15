<?php

declare(strict_types=1);

namespace Drupal\bfep\Repository;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\bfep\Admin\AdminFormat;
use Drupal\bfep\Service\BfepCacheInvalidator;
use Drupal\bfep\Service\BfepSettings;

/**
 * Read model for public campaign data in the external bfdb database.
 */
final class CampaignRepository {

  public function __construct(
    private readonly \Closure $connection,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
    private readonly BfepSettings $settings,
  ) {}

  /**
   * The bfdb connection, opened on first use.
   */
  private function db(): Connection {
    return ($this->connection)();
  }

  /**
   * One row per public campaign, with its active fundraisers combined.
   *
   * A campaign can have several active fundraisers, and
   * v_campaigns has one row per active fundraiser. This takes one row per
   * campaign from the view and replaces its fundraiser columns:
   * - fundraiser_url and platform are the newest active fundraiser's;
   * - platforms lists every active fundraiser's platform;
   * - currency_code, goal_amount, donated_amount and pct_goal_achieved are
   *   the totals when all active fundraisers use one currency, and NULL when
   *   currencies differ (amounts in different currencies are not added up;
   *   currency_totals then has the totals per currency, as JSON);
   * - fundraiser_count is the number of active fundraisers.
   * A campaign with one active fundraiser keeps the view's values unchanged.
   *
   * Written without square brackets or braces, which Drupal rewrites.
   *
   * @return string
   *   A parenthesised subquery; alias it, as in "FROM {$sql} v".
   */
  private function publicCampaigns(): string {
    return <<<'SQL'
      (
        SELECT
          base.id,
          base.line_number,
          base.contact_name,
          base.country,
          base.global_region,
          base.description,
          base.featured_by_bfep,
          base.urgent_medical_needs,
          base.career,
          base.tags,
          base.created_at,
          base.updated_at,
          COALESCE(totals.fundraiser_count, 0) AS fundraiser_count,
          CASE WHEN COALESCE(totals.fundraiser_count, 0) <= 1 THEN base.fundraiser_url ELSE newest.url END AS fundraiser_url,
          CASE WHEN COALESCE(totals.fundraiser_count, 0) <= 1 THEN base.platform ELSE newest.platform END AS platform,
          totals.platforms,
          CASE
            WHEN COALESCE(totals.fundraiser_count, 0) <= 1 THEN base.currency_code
            WHEN totals.currency_count = 1 THEN totals.currency_code
          END AS currency_code,
          CASE
            WHEN COALESCE(totals.fundraiser_count, 0) <= 1 THEN base.goal_amount
            WHEN totals.currency_count = 1 THEN totals.goal_amount
          END AS goal_amount,
          CASE
            WHEN COALESCE(totals.fundraiser_count, 0) <= 1 THEN base.donated_amount
            WHEN totals.currency_count = 1 THEN totals.donated_amount
          END AS donated_amount,
          CASE
            WHEN COALESCE(totals.fundraiser_count, 0) <= 1 THEN base.pct_goal_achieved
            WHEN totals.currency_count = 1 AND totals.goal_amount > 0 THEN totals.donated_amount / totals.goal_amount * 100
          END AS pct_goal_achieved,
          CASE WHEN totals.currency_count > 1 THEN by_currency.totals END AS currency_totals
        FROM (
          SELECT DISTINCT ON (vc.id)
            vc.id, vc.line_number, vc.contact_name, vc.country, vc.global_region,
            vc.description, vc.featured_by_bfep, vc.urgent_medical_needs, vc.career,
            vc.fundraiser_url, vc.platform, vc.currency_code, vc.goal_amount,
            vc.donated_amount, vc.pct_goal_achieved, vc.tags, vc.created_at, vc.updated_at
          FROM v_campaigns vc
          INNER JOIN campaigns source ON source.id = vc.id AND source.deleted_at IS NULL
          WHERE vc.id IS NOT NULL
          ORDER BY vc.id
        ) base
        LEFT JOIN (
          SELECT
            cf.campaign_id,
            COUNT(*) AS fundraiser_count,
            array_agg(DISTINCT fp.name) FILTER (WHERE fp.name IS NOT NULL AND fp.name <> '') AS platforms,
            COUNT(DISTINCT COALESCE(UPPER(TRIM(cf.currency_code)), '')) AS currency_count,
            MAX(UPPER(TRIM(cf.currency_code))) AS currency_code,
            SUM(cf.goal_amount) AS goal_amount,
            SUM(cf.donated_amount) AS donated_amount
          FROM campaign_fundraisers cf
          LEFT JOIN fundraising_platforms fp ON fp.id = cf.platform_id
          WHERE cf.is_active
          GROUP BY cf.campaign_id
        ) totals ON totals.campaign_id = base.id
        LEFT JOIN (
          SELECT DISTINCT ON (cf.campaign_id) cf.campaign_id, cf.url, fp.name AS platform
          FROM campaign_fundraisers cf
          LEFT JOIN fundraising_platforms fp ON fp.id = cf.platform_id
          WHERE cf.is_active
          ORDER BY cf.campaign_id, cf.id DESC
        ) newest ON newest.campaign_id = base.id
        LEFT JOIN (
          SELECT c.campaign_id, json_agg(json_build_object('currency', c.currency, 'raised', c.raised, 'goal', c.goal) ORDER BY c.currency) AS totals
          FROM (
            SELECT cf.campaign_id, UPPER(TRIM(cf.currency_code)) AS currency, SUM(cf.donated_amount) AS raised, SUM(cf.goal_amount) AS goal
            FROM campaign_fundraisers cf
            WHERE cf.is_active
            GROUP BY cf.campaign_id, UPPER(TRIM(cf.currency_code))
          ) c
          GROUP BY c.campaign_id
        ) by_currency ON by_currency.campaign_id = base.id
      )
      SQL;
  }

  public function stats(): array {
    return $this->remember('bfep:stats', function (): array {
      $row = $this->db()->query(<<<'SQL'
        SELECT
          COUNT(DISTINCT id) AS total_campaigns,
          COUNT(DISTINCT id) FILTER (WHERE featured_by_bfep = TRUE) AS featured_campaigns,
          COUNT(DISTINCT id) FILTER (WHERE urgent_medical_needs = TRUE) AS urgent_campaigns,
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
          OR EXISTS (
            SELECT 1 FROM unnest(platforms) AS p
            WHERE p ILIKE :q
          )
          OR EXISTS (
            SELECT 1 FROM unnest(tags) AS t
            WHERE t ILIKE :q
          )
          SQL;
        $params[':q'] = '%' . $this->db()->escapeLike($filters['q']) . '%';

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
      ] as $filter => $column) {
        if ($filters[$filter] !== '') {
          $where[] = $column . ' = :' . $filter;
          $params[':' . $filter] = $filters[$filter];
        }
      }

      if ($filters['platform'] !== '') {
        // Any of the campaign's active fundraisers.
        $where[] = 'EXISTS (SELECT 1 FROM unnest(platforms) AS p WHERE p = :platform)';
        $params[':platform'] = $filters['platform'];
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

      $lineDesc = AdminFormat::lineOrderSql('line_number', 'DESC');
      $sortSql = match ($filters['sort']) {
        'updated_desc' => 'updated_at DESC NULLS LAST, id DESC',
        'created_desc' => 'created_at DESC NULLS LAST, id DESC',
        'name_asc' => 'contact_name ASC NULLS LAST, id ASC',
        'country_asc' => 'country ASC NULLS LAST, contact_name ASC NULLS LAST, id ASC',
        // Campaigns without a goal have no percentage and go last.
        'funded_asc' => 'pct_goal_achieved ASC NULLS LAST, ' . $lineDesc . ', id DESC',
        // Nearest to 100% first; fully funded campaigns after the rest.
        'funded_desc' => 'COALESCE(pct_goal_achieved >= 100, TRUE) ASC, pct_goal_achieved DESC NULLS LAST, ' . $lineDesc . ', id DESC',
        default => $lineDesc . ', id DESC',
      };
      if ($lineNumber !== NULL) {
        // Put the exact line match first, whatever the chosen sort.
        $sortSql = 'COALESCE(line_number = :line_number, FALSE) DESC, ' . $sortSql;
      }
      $whereSql = implode(' AND ', $where);
      $limit = (int) $filters['per_page'];
      $offset = ((int) $filters['page'] - 1) * $limit;

      $campaigns = $this->publicCampaigns();
      $total = (int) $this->db()->query(
        "SELECT COUNT(*) FROM {$campaigns} v WHERE {$whereSql}",
        $params,
      )->fetchField();

      $rows = $this->db()->query(<<<SQL
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
          fundraiser_count,
          platforms,
          currency_totals,
          tags,
          created_at,
          updated_at
        FROM {$campaigns} v
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
        return $this->db()->query(<<<SQL
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
            v.fundraiser_count,
            v.platforms,
            v.currency_totals,
            v.tags,
            v.created_at,
            v.updated_at
          FROM {$this->publicCampaigns()} v
          WHERE v.id = :id
          SQL, [':id' => $campaignId])->fetchObject();
      },
      [BfepCacheInvalidator::CAMPAIGNS, 'bfep:campaign:' . $campaignId],
    );

    return $result === FALSE ? NULL : $result;
  }

  /**
   * The campaign's active fundraisers, newest first.
   *
   * @return object[]
   *   Rows with id, url, platform, currency_code, goal_amount,
   *   donated_amount, pct_goal_achieved and figures_updated_at (when the sync last refreshed
   *   that fundraiser's amounts, or NULL when it is updated by hand or bfdb
   *   has no sync columns).
   */
  public function fundraisers(int $campaignId): array {
    return $this->remember(
      'bfep:campaign-fundraisers:' . $campaignId,
      function () use ($campaignId): array {
        $select = <<<'SQL'
          SELECT cf.id, cf.url, fp.name AS platform, UPPER(TRIM(cf.currency_code)) AS currency_code,
            cf.goal_amount, cf.donated_amount,
            CASE WHEN cf.goal_amount > 0 THEN cf.donated_amount / cf.goal_amount * 100 END AS pct_goal_achieved,
            %s AS figures_updated_at
          FROM campaign_fundraisers cf
          INNER JOIN campaigns source ON source.id = cf.campaign_id AND source.deleted_at IS NULL
          LEFT JOIN fundraising_platforms fp ON fp.id = cf.platform_id
          WHERE cf.campaign_id = :id AND cf.is_active
          ORDER BY cf.id DESC
          SQL;
        try {
          return $this->db()->query(
            sprintf($select, 'CASE WHEN cf.auto_sync THEN cf.last_success_at END'),
            [':id' => $campaignId],
          )->fetchAll();
        }
        catch (\Throwable) {
          // No sync columns in this bfdb.
          return $this->db()->query(sprintf($select, 'NULL'), [':id' => $campaignId])->fetchAll();
        }
      },
      [BfepCacheInvalidator::CAMPAIGNS, 'bfep:campaign:' . $campaignId],
    );
  }

  /**
   * When the sync service last refreshed this campaign's raised and goal.
   *
   * NULL when the active fundraiser is updated by hand, has never synced, or
   * bfdb has no sync columns (an environment without the sync service).
   */
  public function figuresUpdatedAt(int $campaignId): ?string {
    $value = $this->remember(
      'bfep:campaign-figures:' . $campaignId,
      function () use ($campaignId): string {
        try {
          return (string) $this->db()->query(<<<'SQL'
            SELECT last_success_at
            FROM campaign_fundraisers
            WHERE campaign_id = :id AND is_active AND auto_sync
              AND last_success_at IS NOT NULL
            ORDER BY last_success_at DESC
            LIMIT 1
            SQL, [':id' => $campaignId])->fetchField();
        }
        catch (\Throwable) {
          // Cached like an empty result, so a missing column is not queried
          // on every page view.
          return '';
        }
      },
      [BfepCacheInvalidator::CAMPAIGNS, 'bfep:campaign:' . $campaignId],
    );
    return $value === '' ? NULL : $value;
  }

  /**
   * Campaigns short of their goal for the homepage, neediest first.
   *
   * Urgent medical cases first, then least funded. Fully funded campaigns
   * and campaigns without a goal are left out.
   */
  public function needingHelp(int $limit = 3): array {
    $limit = max(1, min(12, $limit));
    return $this->remember(
      'bfep:needing-help:' . $limit,
      fn(): array => $this->db()->query(<<<SQL
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
          v.fundraiser_count,
          v.currency_totals,
          v.tags,
          v.created_at,
          v.updated_at
        FROM {$this->publicCampaigns()} v
        WHERE v.pct_goal_achieved < 100
        ORDER BY
          COALESCE(v.urgent_medical_needs, FALSE) DESC,
          v.pct_goal_achieved ASC,
          v.id DESC
        LIMIT {$limit}
        SQL)->fetchAll(),
      [BfepCacheInvalidator::HOME, BfepCacheInvalidator::CAMPAIGNS],
    );
  }

  /**
   * The most recently added public campaigns, newest first, for the feed.
   */
  public function recent(int $limit = 30): array {
    $limit = max(1, min(100, $limit));
    return $this->remember(
      'bfep:recent:' . $limit,
      fn(): array => $this->db()->query(<<<SQL
        SELECT
          v.id,
          v.line_number,
          v.contact_name,
          v.country,
          v.description,
          v.featured_by_bfep,
          v.urgent_medical_needs,
          v.platform,
          v.currency_code,
          v.goal_amount,
          v.donated_amount,
          v.pct_goal_achieved,
          v.fundraiser_count,
          v.currency_totals,
          v.created_at,
          v.updated_at
        FROM {$this->publicCampaigns()} v
        ORDER BY v.created_at DESC NULLS LAST, v.id DESC
        LIMIT {$limit}
        SQL)->fetchAll(),
      [BfepCacheInvalidator::CAMPAIGNS],
      $this->settings->listingCacheMaxAge(),
    );
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
      fn(): array => $this->db()->query(<<<SQL
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
          v.fundraiser_count,
          v.currency_totals,
          v.tags,
          v.created_at,
          v.updated_at
        FROM {$this->publicCampaigns()} v
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
      fn(): array => $this->db()->query(<<<'SQL'
        SELECT DISTINCT v.id
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
      fn(): int => (int) $this->db()->query(
        'SELECT COUNT(DISTINCT v.id) FROM v_campaigns v INNER JOIN campaigns source ON source.id = v.id AND source.deleted_at IS NULL WHERE v.country = :country',
        [':country' => $country],
      )->fetchField(),
      [BfepCacheInvalidator::CAMPAIGNS, BfepCacheInvalidator::COUNTRIES],
    );
  }

  public function countries(): array {
    return $this->remember('bfep:countries', fn(): array => $this->db()->query(<<<'SQL'
      SELECT
        v.country,
        v.global_region,
        COUNT(DISTINCT v.id) AS campaign_count,
        COUNT(DISTINCT v.id) FILTER (WHERE v.featured_by_bfep = TRUE) AS featured_count,
        COUNT(DISTINCT v.id) FILTER (WHERE v.urgent_medical_needs = TRUE) AS urgent_count
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
      fn(): string|false => $this->db()->query(<<<'SQL'
        SELECT v.country
        FROM v_campaigns v
        INNER JOIN campaigns source ON source.id = v.id AND source.deleted_at IS NULL
        WHERE v.country IS NOT NULL
          AND v.country <> ''
          AND LOWER(v.country) = LOWER(:country)
        GROUP BY v.country
        ORDER BY COUNT(DISTINCT v.id) DESC, v.country ASC
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
        $options[$column] = $this->db()->query(<<<SQL
          SELECT DISTINCT v.{$column} AS value
          FROM v_campaigns v
          INNER JOIN campaigns source ON source.id = v.id AND source.deleted_at IS NULL
          WHERE v.{$column} IS NOT NULL AND v.{$column} <> ''
          ORDER BY v.{$column}
          SQL)->fetchCol();
      }
      $options['tag'] = $this->db()->query(<<<'SQL'
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
    return $this->db()->query(<<<'SQL'
      SELECT DISTINCT v.id, COALESCE(v.updated_at, v.created_at) AS lastmod
      FROM v_campaigns v
      INNER JOIN campaigns source ON source.id = v.id AND source.deleted_at IS NULL
      WHERE v.id IS NOT NULL
      ORDER BY v.id
      SQL)->fetchAll();
  }

  public function sitemapCountries(): array {
    return $this->db()->query(<<<'SQL'
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
