<?php

declare(strict_types=1);

namespace Drupal\Tests\bfep\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\bfep\Admin\AdminFormat;

/**
 * @coversDefaultClass \Drupal\bfep\Admin\AdminFormat
 *
 * @group bfep
 */
final class AdminFormatTest extends UnitTestCase {

  /**
   * @covers ::externalUrl
   * @dataProvider externalUrlProvider
   */
  public function testExternalUrl(mixed $input, ?string $expected): void {
    $this->assertSame($expected, AdminFormat::externalUrl($input));
  }

  public static function externalUrlProvider(): array {
    return [
      'https' => ['https://www.gofundme.com/f/example', 'https://www.gofundme.com/f/example'],
      'http trimmed' => ['  http://example.org/a ', 'http://example.org/a'],
      'javascript with host passes FILTER_VALIDATE_URL' => ['javascript://x%0Aalert(1)', NULL],
      'javascript' => ['javascript:alert(1)', NULL],
      'data' => ['data:text/html,hi', NULL],
      'no scheme' => ['gofundme.com/f/example', NULL],
      'empty' => ['', NULL],
      'null' => [NULL, NULL],
    ];
  }

  /**
   * @covers ::urlMatchKey
   */
  public function testUrlMatchKey(): void {
    $key = 'gofundme.com/f/abc';
    $this->assertSame($key, AdminFormat::urlMatchKey('https://www.GoFundMe.com/f/abc/?utm_source=x'));
    $this->assertSame($key, AdminFormat::urlMatchKey(' http://gofundme.com/f/abc#top '));
    $this->assertSame($key, AdminFormat::urlMatchKey('gofundme.com/f/abc//'));
    $this->assertSame('', AdminFormat::urlMatchKey(NULL));
  }

  /**
   * @covers ::lineNumberQuery
   */
  public function testLineNumberQuery(): void {
    $this->assertSame(142, AdminFormat::lineNumberQuery(' #142 '));
    $this->assertSame(7, AdminFormat::lineNumberQuery('7'));
    $this->assertNull(AdminFormat::lineNumberQuery('142 Gaza'));
    $this->assertNull(AdminFormat::lineNumberQuery('0'));
    $this->assertNull(AdminFormat::lineNumberQuery('1e3'));
    $this->assertNull(AdminFormat::lineNumberQuery(['142']));
  }

  /**
   * @covers ::pageWindow
   */
  public function testPageWindowClampsToLastPage(): void {
    $window = AdminFormat::pageWindow('9', '25', 60);
    $this->assertSame(['page' => 3, 'per_page' => 25, 'offset' => 50, 'total_pages' => 3], $window);
  }

  /**
   * @covers ::pageWindow
   */
  public function testPageWindowRejectsInvalidInput(): void {
    $window = AdminFormat::pageWindow('abc', '7', 0);
    $this->assertSame(['page' => 1, 'per_page' => 50, 'offset' => 0, 'total_pages' => 1], $window);
    $this->assertSame(1, AdminFormat::pageWindow('-4', 50, 500)['page']);
  }

  /**
   * @covers ::referralStatusOptions
   * @covers ::referralStatusKey
   */
  public function testReferralStatusOptionsKeepUnknownValues(): void {
    $options = AdminFormat::referralStatusOptions(['Verified', 'on_hold'], 'legacy-value');
    $this->assertSame(
      ['pending', 'needs_information', 'verified', 'rejected', 'on_hold', 'legacy-value'],
      array_keys($options),
    );
    $this->assertSame('On hold', $options['on_hold']);
    $this->assertSame('verified', AdminFormat::referralStatusKey('Verified', $options));
    $this->assertSame('pending', AdminFormat::referralStatusKey(NULL, $options));
    $this->assertSame('legacy-value', AdminFormat::referralStatusKey('legacy-value', $options));
  }

  /**
   * @covers ::fundraiserErrors
   */
  public function testFundraiserErrors(): void {
    $this->assertSame([], AdminFormat::fundraiserErrors(0, '', '', NULL, ''));
    $this->assertSame([], AdminFormat::fundraiserErrors(3, 'https://example.org/f', 'USD', '100', '0'));

    $errors = AdminFormat::fundraiserErrors(0, 'ftp://example.org', 'usd', '-1', 'abc');
    $this->assertSame(
      ['platform_id', 'fundraiser_url', 'currency_code', 'goal_amount', 'donated_amount'],
      array_keys($errors),
    );
    $this->assertArrayHasKey('fundraiser_url', AdminFormat::fundraiserErrors(2, '', '', NULL, NULL));
  }

  /**
   * @covers ::changedKeys
   */
  public function testChangedKeysNormalisesDatabaseValues(): void {
    $before = [
      'featured_by_bfep' => 't',
      'urgent_medical_needs' => 'f',
      'goal_amount' => '250.00',
      'description' => "Text \n",
      'tags' => [3, 1],
      'career' => NULL,
    ];
    $after = [
      'featured_by_bfep' => TRUE,
      'urgent_medical_needs' => TRUE,
      'goal_amount' => '250',
      'description' => 'Text',
      'tags' => ['1', '3'],
      'career' => '',
    ];
    $this->assertSame(['urgent_medical_needs'], AdminFormat::changedKeys($before, $after));
  }

  /**
   * @covers ::referralStatusLabel
   */
  public function testReferralStatusLabel(): void {
    $this->assertSame('Pending', AdminFormat::referralStatusLabel(NULL));
    $this->assertSame('Pending', AdminFormat::referralStatusLabel('  '));
    $this->assertSame('Needs information', AdminFormat::referralStatusLabel('needs_information'));
    $this->assertSame('Verified', AdminFormat::referralStatusLabel('VERIFIED'));
    $this->assertSame('On hold awaiting docs', AdminFormat::referralStatusLabel('on_hold-awaiting docs'));
  }

}
