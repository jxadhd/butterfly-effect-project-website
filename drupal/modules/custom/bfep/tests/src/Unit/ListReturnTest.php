<?php

declare(strict_types=1);

namespace Drupal\Tests\bfep\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\bfep\Admin\ListReturn;

/**
 * @coversDefaultClass \Drupal\bfep\Admin\ListReturn
 *
 * @group bfep
 */
final class ListReturnTest extends UnitTestCase {

  /**
   * @covers ::linkQuery
   * @covers ::listQuery
   */
  public function testRoundTripKeepsFiltersSortAndPage(): void {
    $list = ['status' => 'pending', 'q' => 'Gaza & co', 'p' => '3', 'order' => 'Name', 'sort' => 'asc', 'per_page' => '25'];
    $link = ListReturn::linkQuery($list);
    $this->assertSame(['back'], array_keys($link));
    $this->assertSame(
      ['q' => 'Gaza & co', 'status' => 'pending', 'per_page' => '25', 'p' => '3', 'order' => 'Name', 'sort' => 'asc'],
      ListReturn::listQuery($link + ['other' => 'x']),
    );
  }

  /**
   * @covers ::linkQuery
   */
  public function testPlainListAddsNothing(): void {
    $this->assertSame([], ListReturn::linkQuery([]));
    $this->assertSame([], ListReturn::linkQuery(['q' => '', 'unknown' => '1']));
  }

  /**
   * @covers ::listQuery
   */
  public function testDropsUnknownAndNonScalarValues(): void {
    $this->assertSame(
      ['featured' => '1'],
      ListReturn::listQuery(['back' => 'featured=1&destination=https://evil.example&status[]=a&sort[x]=1']),
    );
  }

  /**
   * @covers ::listQuery
   */
  public function testIgnoresMissingOrMalformedBack(): void {
    $this->assertSame([], ListReturn::listQuery([]));
    $this->assertSame([], ListReturn::listQuery(['back' => ['status' => 'pending']]));
    $this->assertSame([], ListReturn::listQuery(['back' => '//evil.example/path']));
    $this->assertSame([], ListReturn::listQuery(['back' => str_repeat('q=a&', 600)]));
  }

  /**
   * @covers ::forward
   */
  public function testForwardPassesBackOn(): void {
    $this->assertSame(['back' => 'status=pending&p=2'], ListReturn::forward(['back' => 'p=2&status=pending&junk=1']));
    $this->assertSame([], ListReturn::forward(['referral' => '5']));
  }

}
