<?php

declare(strict_types=1);

namespace Drupal\bfep\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;

/**
 * Whether the public referral form is open, and the notice shown when not.
 *
 * Staff pause referrals on BFEP Admin › Referrals › Pause referrals when the
 * team cannot take on new ones. Referrals already received are unaffected.
 */
final class ReferralIntake {

  use StringTranslationTrait;

  public const CONFIG_NAME = 'bfep.referrals';

  /**
   * The cache tag to add to anything that changes when referrals pause.
   */
  public const CACHE_TAG = 'config:' . self::CONFIG_NAME;

  /**
   * Longest notice staff can write, per language.
   */
  public const NOTICE_MAX = 1500;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  public function isPaused(): bool {
    return (bool) $this->configFactory->get(self::CONFIG_NAME)->get('paused');
  }

  /**
   * When referrals were last paused, as a Unix timestamp, if known.
   */
  public function pausedAt(): ?int {
    $value = (int) $this->configFactory->get(self::CONFIG_NAME)->get('paused_at');
    return $value > 0 ? $value : NULL;
  }

  /**
   * The notice staff wrote for a language, or '' for the standard one.
   *
   * @param string $langcode
   *   'ar' for the Arabic notice; any other language reads the English one.
   */
  public function customNotice(string $langcode): string {
    $key = $langcode === 'ar' ? 'notice_ar' : 'notice';
    return trim((string) $this->configFactory->get(self::CONFIG_NAME)->get($key));
  }

  /**
   * The notice to show visitors reading a page in one language.
   *
   * @return array{text: string, langcode: string}
   *   The staff-written text and its language, or text '' when the standard
   *   (translated) notice should be shown. A language with no notice of its
   *   own falls back to the English one, so an English notice with news such
   *   as a reopening date is never replaced by the standard wording.
   */
  public function noticeFor(string $langcode): array {
    $own = $this->customNotice($langcode);
    if ($own !== '' || $langcode === 'en') {
      return ['text' => $own, 'langcode' => $langcode];
    }
    $english = $this->customNotice('en');
    return $english !== ''
      ? ['text' => $english, 'langcode' => 'en']
      : ['text' => '', 'langcode' => $langcode];
  }

  /**
   * Splits a staff-written notice into paragraphs at blank lines.
   *
   * @return string[]
   *   Plain-text paragraphs; single line breaks inside them are kept.
   */
  public static function paragraphs(string $text): array {
    return array_values(array_filter(
      array_map('trim', preg_split('/\R\s*\R/u', trim($text)) ?: []),
      static fn(string $paragraph): bool => $paragraph !== '',
    ));
  }

  /**
   * The notice visitors see in place of the referral form.
   *
   * @param string $langcode
   *   The language of the page the notice is shown on.
   */
  public function notice(string $langcode): array {
    $options = ['langcode' => $langcode];
    $notice = $this->noticeFor($langcode);
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['bfep-form-notice', 'bfep-referrals-paused'], 'role' => 'status'],
      'heading' => [
        '#markup' => '<h2>' . $this->t('We are not taking new referrals right now', [], $options) . '</h2>',
      ],
    ];
    if ($notice['text'] !== '') {
      // Staff write plain text, so it is escaped. An English notice on an
      // Arabic page is marked as English so it reads left to right.
      $attributes = $notice['langcode'] === $langcode ? '' : ' lang="' . Html::escape($notice['langcode']) . '" dir="ltr"';
      $build['text'] = [
        '#markup' => implode('', array_map(
          static fn(string $paragraph): string => '<p' . $attributes . '>' . nl2br(Html::escape($paragraph)) . '</p>',
          self::paragraphs($notice['text']),
        )),
      ];
      return $build;
    }
    $build['text'] = [
      '#markup' => '<p>' . $this->t('Thank you for wanting to help someone get support. Our volunteer team is small at the moment, so we have paused new referrals while we catch up on the ones we already have.', [], $options) . '</p>'
        . '<p>' . $this->t('If you have already sent a referral, there is no need to send it again. It is still with us and will be reviewed.', [], $options) . '</p>'
        . '<p>' . $this->t('Please check back soon. In the meantime, you can still <a href=":campaigns">browse campaigns</a> and share them with others, or <a href=":volunteer">volunteer with us</a>.', [
          ':campaigns' => Url::fromRoute('bfep.campaigns')->toString(),
          ':volunteer' => Url::fromRoute('bfep.volunteer')->toString(),
        ], $options) . '</p>',
    ];
    return $build;
  }

}
