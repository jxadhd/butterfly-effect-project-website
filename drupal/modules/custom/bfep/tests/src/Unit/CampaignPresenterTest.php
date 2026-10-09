<?php

declare(strict_types=1);

namespace Drupal\Tests\bfep\Unit;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\bfep\Service\CampaignPresenter;

/**
 * @coversDefaultClass \Drupal\bfep\Service\CampaignPresenter
 *
 * @group bfep
 */
final class CampaignPresenterTest extends UnitTestCase {

  private function presenter(): CampaignPresenter {
    $formatter = $this->createMock(DateFormatterInterface::class);
    $formatter->method('format')->willReturn('3 September 2026');
    return new CampaignPresenter($formatter);
  }

  /**
   * @covers ::card
   * @covers ::detail
   */
  public function testCreatesPublicViewModelsWithoutInternalFields(): void {
    $row = (object) [
      'id' => 42,
      'line_number' => 7,
      'contact_name' => 'Test family',
      'country' => 'Palestine',
      'global_region' => 'Middle East',
      'description' => "A public description.\nSecond line.",
      'featured_by_bfep' => TRUE,
      'urgent_medical_needs' => FALSE,
      'career' => NULL,
      'fundraiser_url' => 'gofundme.com/example',
      'platform' => 'GoFundMe',
      'currency_code' => 'USD',
      'goal_amount' => '1000',
      'donated_amount' => '250',
      'pct_goal_achieved' => '25',
      'tags' => '{medical,"food aid"}',
      'updated_at' => '2026-09-03T00:00:00+00:00',
      'internal_notes' => 'must never be copied',
    ];

    $card = $this->presenter()->card($row, '/campaigns/42');
    $detail = $this->presenter()->detail($row);
    $this->assertSame('https://gofundme.com/example', $card['fundraiser_url']);
    $this->assertSame(['medical', 'food aid'], $card['tags']);
    $this->assertArrayNotHasKey('internal_notes', $card);
    $this->assertArrayNotHasKey('internal_notes', $detail);
  }

  /**
   * @covers ::paragraphs
   */
  public function testSplitsDescriptionsIntoParagraphs(): void {
    $presenter = $this->presenter();
    $this->assertSame([], $presenter->paragraphs("  \n "));
    $this->assertSame(['One line.'], $presenter->paragraphs('One line.'));
    $this->assertSame(
      ["First paragraph,\nsecond line.", 'Second paragraph.'],
      $presenter->paragraphs("First paragraph,  \r\nsecond line.\r\n\r\n \n\nSecond paragraph.\n"),
    );
  }

  /**
   * @covers ::progress
   */
  public function testProgressIsCappedAndFallsBackToAmounts(): void {
    $presenter = $this->presenter();
    $this->assertSame(25.0, $presenter->progress((object) ['pct_goal_achieved' => '25']));
    $this->assertSame(100.0, $presenter->progress((object) ['pct_goal_achieved' => '140.5']));
    $this->assertSame(12.3, $presenter->progress((object) ['goal_amount' => '1000', 'donated_amount' => '123']));
    $this->assertNull($presenter->progress((object) ['goal_amount' => '0', 'donated_amount' => '5']));
    $this->assertNull($presenter->progress((object) ['donated_amount' => '5']));
  }

  /**
   * @covers ::card
   */
  public function testRejectsNonHttpSchemes(): void {
    $row = (object) [
      'id' => 1,
      'contact_name' => 'Unsafe URL',
      'fundraiser_url' => 'javascript:alert(1)',
    ];
    $card = $this->presenter()->card($row, '/campaigns/1');
    $this->assertNull($card['fundraiser_url']);
  }

}
