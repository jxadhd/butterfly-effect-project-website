<?php

declare(strict_types=1);

namespace Drupal\Tests\bfep\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\bfep\Admin\DataChecks;

/**
 * @coversDefaultClass \Drupal\bfep\Admin\DataChecks
 *
 * @group bfep
 */
final class DataChecksTest extends UnitTestCase {

  /**
   * @covers ::get
   */
  public function testUnknownChecksAreRejected(): void {
    $this->assertNotNull(DataChecks::get('no_fundraiser'));
    $this->assertNull(DataChecks::get('bogus'));
    $this->assertNull(DataChecks::get('no_fundraiser; DROP TABLE campaigns'));
    $this->assertNull(DataChecks::get(['no_fundraiser']));
    $this->assertNull(DataChecks::get(NULL));
  }

  /**
   * @covers ::all
   * @covers ::options
   */
  public function testDefinitions(): void {
    $checks = DataChecks::all();
    $this->assertSame(array_keys($checks), array_keys(DataChecks::options()));
    foreach ($checks as $key => $check) {
      // Keys become SQL column aliases in countSql().
      $this->assertMatchesRegularExpression('/^[a-z_]+$/', $key);
      $this->assertNotSame('', $check['label']);
      $this->assertNotSame('', $check['help']);
      $this->assertNotSame('', $check['sql']);
    }
  }

  /**
   * @covers ::countSql
   */
  public function testCountSqlCoversEveryCheck(): void {
    $sql = DataChecks::countSql();
    foreach (array_keys(DataChecks::all()) as $key) {
      $this->assertStringContainsString(" AS {$key}", $sql);
    }
    $this->assertStringContainsString(' AS flagged', $sql);
    $this->assertStringContainsString('FROM campaigns WHERE deleted_at IS NULL', $sql);
  }

}
