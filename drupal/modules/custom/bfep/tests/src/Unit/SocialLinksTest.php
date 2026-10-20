<?php

declare(strict_types=1);

namespace Drupal\Tests\bfep\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\bfep\Admin\SocialLinks;

/**
 * @coversDefaultClass \Drupal\bfep\Admin\SocialLinks
 *
 * @group bfep
 */
final class SocialLinksTest extends UnitTestCase {

  /**
   * @covers ::url
   * @dataProvider providerUrls
   */
  public function testUrl(string $platform, ?string $value, ?string $expected): void {
    $this->assertSame($expected, SocialLinks::url($platform, $value));
  }

  /**
   * Handles and links with the address Open should go to.
   */
  public static function providerUrls(): array {
    return [
      'full link' => ['instagram', 'https://www.instagram.com/gaza.family/', 'https://www.instagram.com/gaza.family/'],
      'instagram handle' => ['instagram', '@gaza.family', 'https://www.instagram.com/gaza.family/'],
      'handle without @' => ['tiktok', 'gazafamily', 'https://www.tiktok.com/@gazafamily'],
      'telegram' => ['telegram', '@gaza_family', 'https://t.me/gaza_family'],
      'twitter' => ['twitter', '@gaza', 'https://x.com/gaza'],
      'link without https' => ['facebook', 'facebook.com/gaza.family', 'https://facebook.com/gaza.family'],
      'website domain' => ['website', 'example.org', 'https://example.org'],
      'www domain' => ['other', 'www.example.org/about', 'https://www.example.org/about'],
      'email' => ['other', 'family@example.org', 'mailto:family@example.org'],
      'whatsapp number' => ['whatsapp', '+970 59-123-4567', 'https://wa.me/970591234567'],
      'whatsapp digits' => ['whatsapp', '+970591234567', 'https://wa.me/970591234567'],
      'discord username' => ['discord', 'gaza_family', NULL],
      'other text' => ['other', 'ask Amal', NULL],
      'script link' => ['website', 'javascript:alert(1)', NULL],
      'ftp link' => ['website', 'ftp://example.org', NULL],
      'empty' => ['instagram', '', NULL],
      'null' => ['instagram', NULL, NULL],
    ];
  }

  /**
   * @covers ::label
   */
  public function testLabel(): void {
    $this->assertSame('Twitter / X', SocialLinks::label('twitter'));
    $this->assertSame('Signal app', SocialLinks::label('signal_app'));
  }

}
