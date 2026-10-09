<?php

declare(strict_types=1);

namespace Drupal\bfep\Repository;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\bfep\Service\BfepCacheInvalidator;
use Drupal\bfep\Service\BfepSettings;

/**
 * Cached read model for public ground initiatives.
 */
final class InitiativeRepository {

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

  public function featured(): array {
    $cached = $this->cache->get('bfep:featured-initiatives');
    if ($cached !== FALSE) {
      return $cached->data;
    }

    $rows = $this->db()->select('ground_initiatives', 'g')
      ->fields('g', [
        'id',
        'contact_name',
        'country_raw',
        'project_description',
        'initiative_type',
        'featured_by_bfep',
        'created_at',
      ])
      ->condition('featured_by_bfep', TRUE)
      ->orderBy('created_at', 'DESC')
      ->range(0, 100)
      ->execute()
      ->fetchAll();

    $this->cache->set(
      'bfep:featured-initiatives',
      $rows,
      $this->time->getRequestTime() + $this->settings->listingCacheMaxAge(),
      [BfepCacheInvalidator::INITIATIVES],
    );
    return $rows;
  }

}
