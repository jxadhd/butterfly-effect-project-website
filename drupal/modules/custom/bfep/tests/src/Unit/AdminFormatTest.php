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
   * @covers ::urlKeySql
   */
  public function testUrlKeySqlAvoidsSquareBrackets(): void {
    // Drupal rewrites [ and ] as identifier quotes anywhere in a query, so a
    // regex character class would silently stop matching query strings.
    $sql = AdminFormat::urlKeySql('cf.url');
    $this->assertStringNotContainsString('[', $sql);
    $this->assertStringNotContainsString(']', $sql);
    $this->assertStringContainsString('lower(trim(cf.url))', $sql);
  }

  /**
   * @covers ::matchCountry
   */
  public function testMatchCountry(): void {
    $names = [1 => 'Sudan', 2 => 'South Sudan', 3 => 'Palestine', 4 => 'Niger', 5 => 'Nigeria'];
    $this->assertSame(3, AdminFormat::matchCountry('Gaza City, Palestine', $names));
    $this->assertSame(2, AdminFormat::matchCountry('Juba, south sudan', $names));
    $this->assertSame(1, AdminFormat::matchCountry('Khartoum (Sudan)', $names));
    $this->assertSame(5, AdminFormat::matchCountry('Lagos, Nigeria', $names));
    $this->assertSame(4, AdminFormat::matchCountry('Niamey, Niger', $names));
    $this->assertNull(AdminFormat::matchCountry('Somewhere else', $names));
    $this->assertNull(AdminFormat::matchCountry('', $names));
  }

  /**
   * @covers ::matchPlatform
   */
  public function testMatchPlatform(): void {
    $names = [1 => 'GoFundMe', 2 => 'Chuffed', 3 => 'Give Send Go', 4 => 'Other'];
    $this->assertSame(1, AdminFormat::matchPlatform('https://www.gofundme.com/f/family-1', $names));
    $this->assertSame(1, AdminFormat::matchPlatform('https://uk.gofundme.com/f/x?utm=y', $names));
    $this->assertSame(2, AdminFormat::matchPlatform('http://chuffed.org/project/abc', $names));
    $this->assertSame(3, AdminFormat::matchPlatform('https://www.givesendgo.com/abc', $names));
    $this->assertNull(AdminFormat::matchPlatform('https://example.org/gofundme', $names));
    $this->assertNull(AdminFormat::matchPlatform('javascript:alert(1)', $names));
    $this->assertNull(AdminFormat::matchPlatform('', $names));
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
   * @covers ::csvCell
   */
  public function testCsvCell(): void {
    $this->assertSame('', AdminFormat::csvCell(NULL));
    $this->assertSame('Family 1', AdminFormat::csvCell('Family 1'));
    $this->assertSame('-12.5', AdminFormat::csvCell('-12.5'));
    $this->assertSame('42', AdminFormat::csvCell(42));
    $this->assertSame("'=HYPERLINK(\"http://x\")", AdminFormat::csvCell('=HYPERLINK("http://x")'));
    $this->assertSame("'+1 555", AdminFormat::csvCell('+1 555'));
    $this->assertSame("'-cmd", AdminFormat::csvCell('-cmd'));
    $this->assertSame("'@SUM(A1)", AdminFormat::csvCell('@SUM(A1)'));
  }

  /**
   * @covers ::csv
   */
  public function testCsv(): void {
    $csv = AdminFormat::csv([['Name', 'Note'], ['A, B', "Line \"one\"\ntwo"], ['=1+1', NULL]]);
    $this->assertStringStartsWith("\u{FEFF}Name,Note\n", $csv);
    $this->assertStringContainsString("\"A, B\",\"Line \"\"one\"\"\ntwo\"\n", $csv);
    $this->assertStringEndsWith("'=1+1,\n", $csv);
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

  /**
   * @covers ::referralStatusTone
   */
  public function testReferralStatusTone(): void {
    $this->assertSame('pending', AdminFormat::referralStatusTone(NULL));
    $this->assertSame('pending', AdminFormat::referralStatusTone(' Pending '));
    $this->assertSame('info', AdminFormat::referralStatusTone('needs_information'));
    $this->assertSame('success', AdminFormat::referralStatusTone('VERIFIED'));
    $this->assertSame('danger', AdminFormat::referralStatusTone('rejected'));
    $this->assertSame('neutral', AdminFormat::referralStatusTone('on hold'));
  }

}
