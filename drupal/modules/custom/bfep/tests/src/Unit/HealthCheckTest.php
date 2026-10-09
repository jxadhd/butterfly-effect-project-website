<?php

declare(strict_types=1);

namespace Drupal\Tests\bfep\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\bfep\Service\HealthCheck;

/**
 * @coversDefaultClass \Drupal\bfep\Service\HealthCheck
 *
 * @group bfep
 */
final class HealthCheckTest extends UnitTestCase {

  /**
   * @covers ::syncIsStale
   */
  public function testSyncIsStale(): void {
    $now = 1_800_000_000;
    $this->assertTrue(HealthCheck::syncIsStale(NULL, $now));
    $this->assertFalse(HealthCheck::syncIsStale($now - 3600, $now));
    $this->assertFalse(HealthCheck::syncIsStale($now - 48 * 3600, $now));
    $this->assertTrue(HealthCheck::syncIsStale($now - 48 * 3600 - 1, $now));
    $this->assertTrue(HealthCheck::syncIsStale($now - 7200, $now, 1));
  }

}
