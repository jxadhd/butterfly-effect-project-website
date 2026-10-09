<?php

declare(strict_types=1);

namespace Drupal\bfep\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Psr\Log\LoggerInterface;

/**
 * Checks bfdb and the fundraiser sync for the status report and dashboard.
 */
final class HealthCheck {

  use StringTranslationTrait;

  /**
   * Hours without any fundraiser being checked before the sync is stalled.
   *
   * The example timer in the sync service runs twice a day, so two days
   * allows for a missed run or two before warning.
   */
  public const SYNC_STALE_HOURS = 48;

  /**
   * Database views the public pages read from.
   */
  private const VIEWS = ['v_campaigns'];

  public function __construct(
    private readonly \Closure $connection,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Summarises the sync columns on active fundraisers of listed campaigns.
   *
   * @return array{auto: int, problems: int, last_checked: int|null}|null
   *   How many fundraisers update automatically, how many of those failed
   *   their last check, and when any of them was last checked. NULL when
   *   the sync columns do not exist.
   */
  public function syncSummary(Connection $db): ?array {
    try {
      $row = $db->query(<<<'SQL'
        SELECT
          COUNT(*) FILTER (WHERE cf.auto_sync) AS auto,
          COUNT(*) FILTER (WHERE cf.auto_sync AND cf.sync_status IS NOT NULL AND cf.sync_status <> 'ok') AS problems,
          MAX(cf.last_checked_at) FILTER (WHERE cf.auto_sync) AS last_checked
        FROM campaign_fundraisers cf
        JOIN campaigns c ON c.id = cf.campaign_id AND c.deleted_at IS NULL
        WHERE cf.is_active
        SQL)->fetchObject();
    }
    catch (\Throwable) {
      return NULL;
    }
    $lastChecked = $row->last_checked !== NULL ? strtotime((string) $row->last_checked) : FALSE;
    return [
      'auto' => (int) $row->auto,
      'problems' => (int) $row->problems,
      'last_checked' => $lastChecked ?: NULL,
    ];
  }

  /**
   * Whether the sync has gone too long without checking any fundraiser.
   */
  public static function syncIsStale(?int $lastChecked, int $now, int $hours = self::SYNC_STALE_HOURS): bool {
    return $lastChecked === NULL || $now - $lastChecked > $hours * 3600;
  }

  /**
   * Whether the sync looks stalled for this summary.
   *
   * @param array{auto: int, problems: int, last_checked: int|null}|null $summary
   *   A result of syncSummary().
   */
  public function syncStalled(?array $summary): bool {
    return $summary !== NULL && $summary['auto'] > 0
      && self::syncIsStale($summary['last_checked'], $this->time->getRequestTime());
  }

  /**
   * Says when the sync last checked a fundraiser, for a summary line.
   */
  public function lastCheckedText(?int $lastChecked): string {
    return $lastChecked === NULL
      ? (string) $this->t('The sync has not checked any fundraiser yet')
      : (string) $this->t('Last checked a fundraiser @time ago', ['@time' => $this->dateFormatter->formatTimeDiffSince($lastChecked, ['granularity' => 1])]);
  }

  /**
   * Builds the BFEP entries for Reports › Status report.
   *
   * @return array<string, array<string, mixed>>
   *   Requirements as documented for hook_runtime_requirements().
   */
  public function requirements(): array {
    try {
      $db = ($this->connection)();
      $version = $db->version();
      $missing = [];
      foreach (self::VIEWS as $view) {
        if ($db->query('SELECT to_regclass(:name)', [':name' => 'public.' . $view])->fetchField() === NULL) {
          $missing[] = $view;
        }
      }
    }
    catch (\Throwable $exception) {
      $this->logger->error('The status report could not reach bfdb: @message', ['@message' => $exception->getMessage()]);
      return [
        'bfep_bfdb' => [
          'title' => $this->t('BFEP database (bfdb)'),
          'value' => $this->t('Not reachable'),
          'description' => $this->t('Campaign pages, the public forms and the BFEP admin cannot read or save data. Check that PostgreSQL is running and that the bfdb connection in settings.php is correct. The error is in Recent log messages.'),
          'severity' => RequirementSeverity::Error,
        ],
      ];
    }

    $requirements['bfep_bfdb'] = [
      'title' => $this->t('BFEP database (bfdb)'),
      'value' => $this->t('Connected (PostgreSQL @version)', ['@version' => $version]),
      'severity' => RequirementSeverity::OK,
    ];
    if ($missing) {
      $requirements['bfep_bfdb']['description'] = $this->t('Missing database views: @views. Campaign pages will fail until database updates are run (drush updatedb).', ['@views' => implode(', ', $missing)]);
      $requirements['bfep_bfdb']['severity'] = RequirementSeverity::Error;
    }

    $sync = $this->syncSummary($db);
    $requirements['bfep_sync'] = ['title' => $this->t('BFEP fundraiser sync')];
    if ($sync === NULL) {
      $requirements['bfep_sync'] += [
        'value' => $this->t('Not set up'),
        'description' => $this->t('campaign_fundraisers has no sync columns, so raised and goal amounts change only when staff edit them.'),
        'severity' => RequirementSeverity::Info,
      ];
    }
    elseif ($sync['auto'] === 0) {
      $requirements['bfep_sync'] += [
        'value' => $this->t('No fundraisers update automatically'),
        'severity' => RequirementSeverity::Info,
      ];
    }
    else {
      $description = [
        (string) $this->formatPlural($sync['auto'], '1 fundraiser updates automatically.', '@count fundraisers update automatically.'),
      ];
      if ($sync['problems'] > 0) {
        $description[] = (string) $this->formatPlural($sync['problems'], '<a href=":url">1 has a sync problem</a>.', '<a href=":url">@count have a sync problem</a>.', [
          ':url' => Url::fromRoute('bfep.admin_campaigns', [], ['query' => ['sync' => 'problem']])->toString(),
        ]);
      }
      $stalled = $this->syncStalled($sync);
      if ($stalled) {
        $description[] = (string) $this->t('No fundraiser has been checked for over @hours hours, so amounts on the site may be out of date. Check that the sync timer is running on the server. Dry runs do not count, because they do not write anything.', ['@hours' => self::SYNC_STALE_HOURS]);
      }
      $requirements['bfep_sync'] += [
        'value' => $this->lastCheckedText($sync['last_checked']),
        'description' => ['#markup' => implode(' ', $description)],
        'severity' => $stalled ? RequirementSeverity::Warning : RequirementSeverity::OK,
      ];
    }
    return $requirements;
  }

}
