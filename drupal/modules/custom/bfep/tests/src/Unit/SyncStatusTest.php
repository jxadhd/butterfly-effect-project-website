<?php

declare(strict_types=1);

namespace Drupal\Tests\bfep\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\bfep\Admin\SyncStatus;

/**
 * @coversDefaultClass \Drupal\bfep\Admin\SyncStatus
 *
 * @group bfep
 */
final class SyncStatusTest extends UnitTestCase {

  /**
   * @covers ::label
   * @covers ::explain
   */
  public function testEveryStatusIsExplained(): void {
    $this->assertSame('Held: big drop', SyncStatus::label('suspicious_drop'));
    $this->assertSame('Not checked yet', SyncStatus::label(NULL));
    $this->assertSame('Platform error (http_503)', SyncStatus::label('http_503'));
    [$meaning, $todo] = SyncStatus::explain('http_503');
    $this->assertStringContainsString('http_503', $meaning);
    $this->assertNotSame('', $todo);
    foreach (['suspicious_drop', 'currency_mismatch', 'hidden_totals', 'currency_unknown', 'parse_error', 'not_found', 'not_found_pending', 'invalid_url', 'not_configured'] as $status) {
      [$meaning, $todo] = SyncStatus::explain($status);
      $this->assertNotSame('', $meaning, $status);
      $this->assertNotSame('', $todo, $status);
    }
  }

  /**
   * @covers ::isHeld
   */
  public function testHeldStatuses(): void {
    $this->assertTrue(SyncStatus::isHeld('suspicious_drop'));
    $this->assertTrue(SyncStatus::isHeld(' currency_mismatch '));
    $this->assertFalse(SyncStatus::isHeld('not_found'));
    $this->assertFalse(SyncStatus::isHeld(NULL));
  }

  /**
   * @covers ::figures
   */
  public function testFigures(): void {
    $this->assertSame('USD 1,200.00 of 10,000.00 (12%)', SyncStatus::figures('1200', '10000.00', 'USD'));
    $this->assertSame('EUR 50.00', SyncStatus::figures(50, NULL, ' EUR '));
    $this->assertSame('— of 500.00', SyncStatus::figures(NULL, 500, NULL));
  }

}
