<?php

declare(strict_types=1);

namespace Drupal\bfep\Admin;

/**
 * The platforms the nightly fundraiser sync (bfep-sync) can read.
 *
 * The patterns copy each provider's url_pattern in bfep-sync, so the site
 * offers automatic updates only where the sync would pick the link up.
 * Update this list when a provider is added there. A provider must also be
 * listed in SYNC_PROVIDERS on the server before it runs.
 */
final class SyncPlatforms {

  /**
   * Platform labels keyed by the regular expression their links match.
   */
  private const PATTERNS = [
    '~gofundme\.com/~i' => 'GoFundMe',
    '~gogetfunding\.com/~i' => 'GoGetFunding',
    '~(spot\.fund|spotfund\.com)/~i' => 'Spotfund',
    '~chuffed\.org/~i' => 'Chuffed',
    '~steunactie\.be/~i' => 'Steunactie',
    '~paypal\.com/donate/?\?(.*&)?campaign_id=~i' => 'PayPal fundraisers',
  ];

  /**
   * Whether the sync can update the fundraiser at this URL.
   */
  public static function supports(?string $url): bool {
    $url = trim((string) $url);
    foreach (array_keys(self::PATTERNS) as $pattern) {
      if ($url !== '' && preg_match($pattern, $url)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The platform names, in display order.
   *
   * @return string[]
   *   Labels such as "GoFundMe".
   */
  public static function labels(): array {
    return array_values(self::PATTERNS);
  }

  /**
   * One sentence for staff entering a fundraiser.
   */
  public static function help(): string {
    $labels = self::labels();
    $last = array_pop($labels);
    return 'Raised and goal amounts update automatically each night for ' . implode(', ', $labels) . ' and ' . $last
      . ' (paypal.com/donate links with a campaign_id; not PayPal.me or donate buttons). For any other platform, enter the amounts by hand.';
  }

}
