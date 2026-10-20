<?php

declare(strict_types=1);

namespace Drupal\Tests\bfep\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\bfep\Service\ReferralIntake;

/**
 * @coversDefaultClass \Drupal\bfep\Service\ReferralIntake
 *
 * @group bfep
 */
final class ReferralIntakeTest extends UnitTestCase {

  private function intake(array $values): ReferralIntake {
    return new ReferralIntake(
      $this->getConfigFactoryStub([ReferralIntake::CONFIG_NAME => $values]),
      $this->getStringTranslationStub(),
    );
  }

  /**
   * @covers ::isPaused
   * @covers ::pausedAt
   */
  public function testOpenUntilPaused(): void {
    $this->assertFalse($this->intake([])->isPaused());
    $this->assertNull($this->intake([])->pausedAt());
    $paused = $this->intake(['paused' => TRUE, 'paused_at' => 1760000000]);
    $this->assertTrue($paused->isPaused());
    $this->assertSame(1760000000, $paused->pausedAt());
  }

  /**
   * @covers ::noticeFor
   */
  public function testEachLanguageUsesItsOwnNotice(): void {
    $intake = $this->intake(['notice' => ' Back in December. ', 'notice_ar' => 'نعود في ديسمبر.']);
    $this->assertSame(['text' => 'Back in December.', 'langcode' => 'en'], $intake->noticeFor('en'));
    $this->assertSame(['text' => 'نعود في ديسمبر.', 'langcode' => 'ar'], $intake->noticeFor('ar'));
  }

  /**
   * @covers ::noticeFor
   */
  public function testArabicFallsBackToEnglishNoticeThenStandardWording(): void {
    $this->assertSame(['text' => 'Back in December.', 'langcode' => 'en'], $this->intake(['notice' => 'Back in December.'])->noticeFor('ar'));
    $this->assertSame(['text' => '', 'langcode' => 'ar'], $this->intake([])->noticeFor('ar'));
    // An Arabic notice alone does not replace the standard English wording.
    $this->assertSame(['text' => '', 'langcode' => 'en'], $this->intake(['notice_ar' => 'نعود قريبًا.'])->noticeFor('en'));
  }

  /**
   * @covers ::paragraphs
   */
  public function testParagraphsSplitAtBlankLines(): void {
    $this->assertSame(["One\nstill one", 'Two'], ReferralIntake::paragraphs("\nOne\nstill one\r\n  \r\nTwo\n\n\n"));
    $this->assertSame([], ReferralIntake::paragraphs("  \n "));
  }

}
