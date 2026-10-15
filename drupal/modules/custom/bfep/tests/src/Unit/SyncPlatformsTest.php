<?php

declare(strict_types=1);

namespace Drupal\Tests\bfep\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\bfep\Admin\SyncPlatforms;

/**
 * @coversDefaultClass \Drupal\bfep\Admin\SyncPlatforms
 *
 * @group bfep
 */
final class SyncPlatformsTest extends UnitTestCase {

  /**
   * @covers ::supports
   */
  public function testSupportedLinks(): void {
    foreach ([
      'https://www.gofundme.com/f/help-a-family',
      'gogetfunding.com/help-gaza/',
      'https://spot.fund/abc123',
      'https://www.spotfund.com/story/xyz',
      'https://www.paypal.com/donate/?campaign_id=ABCD1234EFGH5',
      'https://www.paypal.com/donate?locale.x=en_US&campaign_id=ABCD1234EFGH5',
      'https://chuffed.org/project/120680-a-mothers-plea',
      'https://steunactie.be/actie/help-my-family-in-gaza-5/-38978',
    ] as $url) {
      $this->assertTrue(SyncPlatforms::supports($url), $url);
    }
  }

  /**
   * @covers ::supports
   */
  public function testUnsupportedLinks(): void {
    foreach ([
      NULL,
      '',
      'https://www.paypal.com/paypalme/Example',
      'https://www.paypal.com/donate/?hosted_button_id=QDSBYBVZ5W8SC',
      'https://www.justgiving.com/page/x',
      'https://www.launchgood.com/campaign/x',
    ] as $url) {
      $this->assertFalse(SyncPlatforms::supports($url), (string) $url);
    }
  }

  /**
   * @covers ::help
   */
  public function testHelpNamesEveryPlatform(): void {
    foreach (SyncPlatforms::labels() as $label) {
      $this->assertStringContainsString($label, SyncPlatforms::help());
    }
  }

}
