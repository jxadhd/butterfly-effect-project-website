<?php

declare(strict_types=1);

namespace Drupal\Tests\bfep\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\bfep\Admin\CampaignRemoval;

/**
 * @coversDefaultClass \Drupal\bfep\Admin\CampaignRemoval
 *
 * @group bfep
 */
final class CampaignRemovalTest extends UnitTestCase {

  /**
   * @covers ::removedNote
   */
  public function testRemovedNote(): void {
    $this->assertSame(
      'Removed from the site on 2026-10-12 by josh. Reason: Duplicate of another campaign. Duplicate of line 1112',
      CampaignRemoval::removedNote('duplicate', "  Duplicate of\nline 1112 ", 'josh', '2026-10-12'),
    );
    $this->assertSame(
      'Removed from the site on 2026-10-12 by josh. Reason: Other.',
      CampaignRemoval::removedNote('bogus', '', 'josh', '2026-10-12'),
    );
  }

  /**
   * @covers ::lastReason
   */
  public function testLastReasonReadsTheLatestRemoval(): void {
    $notes = implode("\n\n", [
      'Spoke to the family in May.',
      CampaignRemoval::removedNote('ended', '', 'a', '2026-01-01'),
      CampaignRemoval::restoredNote('b', '2026-02-01'),
      CampaignRemoval::removedNote('duplicate', 'Same as line 9', 'c', '2026-03-01'),
    ]);
    $this->assertSame('Duplicate of another campaign. Same as line 9', CampaignRemoval::lastReason($notes));
    $this->assertSame('', CampaignRemoval::lastReason('Nothing here'));
    $this->assertSame('', CampaignRemoval::lastReason(NULL));
  }

}
