<?php

declare(strict_types=1);

namespace Drupal\Tests\bfep\Unit;

use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Tests\UnitTestCase;
use Drupal\bfep\Admin\BfdbError;

/**
 * @coversDefaultClass \Drupal\bfep\Admin\BfdbError
 *
 * @group bfep
 */
final class BfdbErrorTest extends UnitTestCase {

  private function wrapped(string $message): DatabaseExceptionWrapper {
    return new DatabaseExceptionWrapper($message, 0, new \PDOException($message));
  }

  /**
   * @covers ::explain
   * @covers ::detail
   */
  public function testPermissionDeniedNamesTheTableAndTheGrant(): void {
    $error = BfdbError::explain($this->wrapped("SQLSTATE[42501]: Insufficient privilege: 7 ERROR:  permission denied for table campaign_fundraisers: \n      SELECT platform_id, url FROM campaign_fundraisers WHERE campaign_id = :id; Array\n(\n    [:id] => 5943\n)\n"));
    $this->assertSame('permission', $error['kind']);
    $this->assertStringContainsString('SELECT on campaign_fundraisers', $error['message']);
    $this->assertStringContainsString('GRANT SELECT ON campaign_fundraisers', $error['action']);
    $this->assertSame('ERROR:  permission denied for table campaign_fundraisers', $error['detail']);
  }

  /**
   * @covers ::explain
   * @covers ::detail
   */
  public function testDetailLeavesOutTheQueryAndArguments(): void {
    $error = BfdbError::explain($this->wrapped("SQLSTATE[22P02]: Invalid text representation: 7 ERROR:  invalid input value for enum verification_status: \"done\": UPDATE referral_submissions SET verification_status = :v WHERE email = 'a@example.org'; Array ( )"));
    $this->assertSame('data', $error['kind']);
    $this->assertStringNotContainsString('example.org', $error['detail']);
    $this->assertStringContainsString('invalid input value for enum', $error['detail']);
  }

  /**
   * @covers ::explain
   */
  public function testKinds(): void {
    $this->assertSame('schema', BfdbError::explain($this->wrapped('SQLSTATE[42883]: Undefined function: 7 ERROR:  function pg_catalog.btrim(verification_status) does not exist'))['kind']);
    $this->assertSame('unreachable', BfdbError::explain(new \PDOException('SQLSTATE[08006] [7] connection to server failed'))['kind']);
    $this->assertSame('unreachable', BfdbError::explain(new \RuntimeException('bfdb is unavailable.'))['kind']);
    $this->assertSame('timeout', BfdbError::explain($this->wrapped('SQLSTATE[57014]: Query canceled: 7 ERROR:  canceling statement due to statement timeout'))['kind']);
    $this->assertNull(BfdbError::explain(new \LogicException('Not a database problem')));
  }

}
