<?php

declare(strict_types=1);

namespace Drupal\Tests\bfep\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\bfep\Repository\InitiativeMedia;

/**
 * @coversDefaultClass \Drupal\bfep\Repository\InitiativeMedia
 *
 * @group bfep
 */
final class InitiativeMediaTest extends UnitTestCase {

  /**
   * @covers ::fit
   */
  public function testFitShrinksLongEdgeOnly(): void {
    $this->assertSame([1600, 1067], InitiativeMedia::fit(3000, 2000, 1600));
    $this->assertSame([1067, 1600], InitiativeMedia::fit(2000, 3000, 1600));
    $this->assertSame([400, 300], InitiativeMedia::fit(400, 300, 1600));
  }

  /**
   * @covers ::extension
   */
  public function testExtension(): void {
    $this->assertSame('webp', InitiativeMedia::extension('image/webp'));
    $this->assertSame('png', InitiativeMedia::extension('image/png'));
    $this->assertSame('jpg', InitiativeMedia::extension('image/jpeg'));
  }

  /**
   * @covers ::prepareImage
   */
  public function testPrepareImage(): void {
    $dir = sys_get_temp_dir();
    $text = tempnam($dir, 'bfep');
    file_put_contents($text, 'not an image');
    $this->assertIsString(InitiativeMedia::prepareImage($text));

    $big = tempnam($dir, 'bfep');
    $image = imagecreatetruecolor(2400, 1200);
    imagejpeg($image, $big);
    $prepared = InitiativeMedia::prepareImage($big);
    $this->assertIsArray($prepared);
    $this->assertSame([1600, 800], [$prepared['width'], $prepared['height']]);
    $this->assertSame($prepared['mime'] === 'image/webp' ? IMAGETYPE_WEBP : IMAGETYPE_JPEG, getimagesizefromstring($prepared['data'])[2]);
    unlink($text);
    unlink($big);
  }

}
