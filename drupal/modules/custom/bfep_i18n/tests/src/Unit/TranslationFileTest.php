<?php

declare(strict_types=1);

namespace Drupal\Tests\bfep_i18n\Unit;

use Drupal\Component\Gettext\PoHeader;
use Drupal\Component\Gettext\PoItem;
use Drupal\Component\Gettext\PoStreamReader;
use Drupal\Tests\UnitTestCase;

/**
 * Checks the shipped .po files, so an edited wording cannot break a page.
 *
 * @group bfep_i18n
 */
final class TranslationFileTest extends UnitTestCase {

  /**
   * Placeholders Drupal replaces: @name, %name, :name and Twig's @a.b.
   */
  private const PLACEHOLDER = '/[@%:][A-Za-z_](?:[A-Za-z0-9_]|\.(?=[A-Za-z0-9_]))*/';

  /**
   * Drupal can read the file's plural formula.
   *
   * @dataProvider fileProvider
   */
  public function testHeader(string $file): void {
    $reader = $this->reader($file);
    $plurals = $reader->getHeader()->getPluralForms();
    $this->assertNotNull($plurals, 'The file declares its plural forms.');
    $this->assertIsArray((new PoHeader())->parsePluralForms($plurals));
  }

  /**
   * Each entry is translated and keeps the English placeholders and tags.
   *
   * @dataProvider fileProvider
   */
  public function testEntries(string $file): void {
    $reader = $this->reader($file);
    $plurals = (new PoHeader())->parsePluralForms((string) $reader->getHeader()->getPluralForms());
    $forms = (int) $plurals[0];
    $seen = [];
    $count = 0;
    while ($item = $reader->readItem()) {
      $count++;
      $source = (array) $item->getSource();
      $translations = (array) $item->getTranslation();
      $label = $item->getContext() . ' | ' . $source[0];

      $key = $item->getContext() . "\x04" . implode(PoItem::DELIMITER, $source);
      $this->assertArrayNotHasKey($key, $seen, "$label: listed once.");
      $seen[$key] = TRUE;

      if ($item->isPlural()) {
        $this->assertCount($forms, $translations, "$label: one translation per plural form.");
      }
      foreach ($translations as $translation) {
        $this->assertNotSame('', trim((string) $translation), "$label: translated.");
        // Every placeholder in a translation must exist in the English, or
        // Drupal prints it as typed. Plural forms for 0, 1 and 2 may leave the
        // number out ("حملتان" is "two campaigns").
        $missing = array_diff($this->placeholders((string) $translation), $this->placeholders(implode(' ', $source)));
        $this->assertSame([], array_values($missing), "$label: unknown placeholder in \"$translation\".");
        $this->assertSame($this->tags($source[0]), $this->tags((string) $translation), "$label: same HTML tags.");
      }
      if (!$item->isPlural()) {
        $expected = $this->placeholders($source[0]);
        $this->assertSame($expected, $this->placeholders((string) $translations[0]), "$label: keeps every placeholder.");
      }
    }
    $this->assertGreaterThan(100, $count);
  }

  public static function fileProvider(): array {
    $files = [];
    foreach (glob(dirname(__DIR__, 3) . '/translations/*.po') as $path) {
      $files[basename($path)] = [$path];
    }
    return $files;
  }

  private function reader(string $file): PoStreamReader {
    $reader = new PoStreamReader();
    $reader->setURI($file);
    $reader->open();
    return $reader;
  }

  /**
   * The distinct placeholders in a string, sorted.
   *
   * @return string[]
   *   Such as ['@count', '@country'].
   */
  private function placeholders(string $text): array {
    // Colons that end a word ("Email:") are not placeholders.
    preg_match_all(self::PLACEHOLDER, $text, $matches);
    $found = array_values(array_unique($matches[0]));
    sort($found);
    return $found;
  }

  /**
   * The HTML tag names in a string, sorted.
   *
   * @return string[]
   *   Such as ['/a', 'a'].
   */
  private function tags(string $text): array {
    preg_match_all('#<(/?[a-z]+)#', $text, $matches);
    $tags = $matches[1];
    sort($tags);
    return $tags;
  }

}
