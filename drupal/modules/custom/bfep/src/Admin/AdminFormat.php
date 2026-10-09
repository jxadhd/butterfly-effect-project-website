<?php

declare(strict_types=1);

namespace Drupal\bfep\Admin;

/**
 * Pure helpers shared by the BFEP staff screens.
 *
 * Kept free of services so the rules can be unit tested without Drupal.
 */
final class AdminFormat {

  /**
   * Workflow values offered for referrals even before any record uses them.
   */
  public const REFERRAL_STATUSES = [
    'pending' => 'Pending',
    'needs_information' => 'Needs information',
    'verified' => 'Verified',
    'rejected' => 'Rejected',
  ];

  /**
   * Allowed page sizes on staff listings.
   */
  public const PER_PAGE_OPTIONS = [25, 50, 100];

  public const DEFAULT_PER_PAGE = 50;

  /**
   * Returns a safe http(s) URL, or NULL for anything else.
   *
   * Stored URLs come from public submissions, so a value such as
   * "javascript://x%0Aalert(1)" passes FILTER_VALIDATE_URL and must be rejected
   * by scheme before it is rendered as a link.
   */
  public static function externalUrl(mixed $value): ?string {
    $value = trim((string) ($value ?? ''));
    if ($value === '' || !filter_var($value, FILTER_VALIDATE_URL)) {
      return NULL;
    }
    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], TRUE)) {
      return NULL;
    }
    return (string) parse_url($value, PHP_URL_HOST) === '' ? NULL : $value;
  }

  /**
   * Reduces a URL to a comparable key: host and path, lower-cased.
   *
   * "https://www.GoFundMe.com/f/abc/?utm=x" and "http://gofundme.com/f/abc"
   * give the same key. Returns '' for an empty value.
   */
  public static function urlMatchKey(mixed $url): string {
    $url = strtolower(trim((string) ($url ?? '')));
    $url = (string) preg_replace('~^https?://(www\.)?|[?#].*$~', '', $url);
    return rtrim($url, '/');
  }

  /**
   * SQL expression giving the same key as urlMatchKey() for a URL column.
   *
   * $column must be a trusted identifier, never user input.
   */
  public static function urlKeySql(string $column): string {
    // No square brackets: Drupal turns [ and ] into identifier quotes in
    // every query, even inside string literals, so "[?#]" would break.
    return "regexp_replace(regexp_replace(lower(trim({$column})), '^https?://(www\\.)?|(\\?|#).*$', '', 'g'), '/+$', '')";
  }

  /**
   * Returns the line number when a search is only a line number.
   *
   * "142", "#142" and " 142 " qualify; "142 Gaza" or "1e3" do not.
   */
  public static function lineNumberQuery(mixed $query): ?int {
    if (!is_scalar($query) || !preg_match('/^#?([1-9][0-9]{0,8})$/', trim((string) $query), $matches)) {
      return NULL;
    }
    return (int) $matches[1];
  }

  /**
   * Validates and clamps listing pagination.
   *
   * @return array{page: int, per_page: int, offset: int, total_pages: int}
   *   The page to show (never past the last page), its size, the SQL offset
   *   and the page count.
   */
  public static function pageWindow(mixed $requestedPage, mixed $requestedPerPage, int $total): array {
    $perPage = (int) $requestedPerPage;
    if (!in_array($perPage, self::PER_PAGE_OPTIONS, TRUE)) {
      $perPage = self::DEFAULT_PER_PAGE;
    }
    $totalPages = max(1, (int) ceil(max(0, $total) / $perPage));
    $page = is_numeric($requestedPage) ? (int) $requestedPage : 1;
    $page = min(max(1, $page), $totalPages);
    return [
      'page' => $page,
      'per_page' => $perPage,
      'offset' => ($page - 1) * $perPage,
      'total_pages' => $totalPages,
    ];
  }

  /**
   * Builds the referral status options.
   *
   * Known workflow values come first, followed by any other values already in
   * the database and the record's current value, so saving a referral never
   * silently changes a status the list does not know about.
   *
   * @param string[] $existing
   *   Distinct statuses already stored.
   * @param string|null $current
   *   The status of the record being edited.
   *
   * @return array<string, string>
   *   Options keyed by stored value.
   */
  public static function referralStatusOptions(array $existing, ?string $current = NULL): array {
    $options = self::REFERRAL_STATUSES;
    foreach ([...$existing, (string) $current] as $value) {
      $value = trim((string) $value);
      if ($value !== '' && !isset($options[$value]) && !isset($options[strtolower($value)])) {
        $options[$value] = self::humanize($value);
      }
    }
    return $options;
  }

  /**
   * Maps a stored status to the matching option key, case-insensitively.
   */
  public static function referralStatusKey(?string $value, array $options): string {
    $value = trim((string) $value);
    if ($value === '') {
      return 'pending';
    }
    if (isset($options[$value])) {
      return $value;
    }
    return isset($options[strtolower($value)]) ? strtolower($value) : $value;
  }

  /**
   * A readable label for a stored referral status.
   *
   * Blank counts as pending, standard statuses match case-insensitively, and
   * anything else is shown with underscores and hyphens as spaces.
   */
  public static function referralStatusLabel(?string $value): string {
    $value = trim((string) $value);
    if ($value === '') {
      return self::REFERRAL_STATUSES['pending'];
    }
    return self::REFERRAL_STATUSES[strtolower($value)] ?? self::humanize($value);
  }

  /**
   * Turns a machine value such as "needs_information" into a label.
   */
  public static function humanize(string $value): string {
    $value = trim(str_replace(['_', '-'], ' ', $value));
    return $value === '' ? '' : ucfirst($value);
  }

  /**
   * Checks the fundraiser part of a campaign form.
   *
   * @return array<string, string>
   *   Error messages keyed by form element name. Messages are plain English;
   *   callers pass them through t().
   */
  public static function fundraiserErrors(int $platformId, string $url, string $currency, mixed $goal, mixed $donated): array {
    $errors = [];
    $hasData = $platformId > 0 || $url !== '' || $currency !== ''
      || !self::isBlank($goal) || !self::isBlank($donated);

    if ($hasData && $platformId < 1) {
      $errors['platform_id'] = 'Select a fundraising platform when entering fundraiser information.';
    }
    if ($platformId > 0 && $url === '') {
      $errors['fundraiser_url'] = 'Enter the fundraiser URL.';
    }
    elseif ($url !== '' && self::externalUrl($url) === NULL) {
      $errors['fundraiser_url'] = 'Use a valid public http:// or https:// fundraiser URL.';
    }
    if ($currency !== '' && !preg_match('/^[A-Z]{3}$/', $currency)) {
      $errors['currency_code'] = 'Currency must be a three-letter code such as USD.';
    }
    foreach (['goal_amount' => [$goal, 'Goal amount'], 'donated_amount' => [$donated, 'Donated amount']] as $key => [$value, $label]) {
      if (self::isBlank($value)) {
        continue;
      }
      if (!is_numeric($value)) {
        $errors[$key] = $label . ' must be a number.';
      }
      elseif ((float) $value < 0) {
        $errors[$key] = $label . ' cannot be negative.';
      }
    }
    return $errors;
  }

  /**
   * Lists the keys whose values differ between two records.
   *
   * Values are compared as trimmed strings with booleans normalised, so a
   * PostgreSQL "t" and a form TRUE count as the same.
   *
   * @return string[]
   *   Changed keys, in the order of $after.
   */
  public static function changedKeys(array $before, array $after): array {
    $changed = [];
    foreach ($after as $key => $value) {
      if (self::normalise($before[$key] ?? NULL) !== self::normalise($value)) {
        $changed[] = $key;
      }
    }
    return $changed;
  }

  /**
   * Interprets PostgreSQL and PHP boolean representations.
   */
  public static function truthy(mixed $value): bool {
    return $value === TRUE || $value === 1 || $value === '1'
      || $value === 't' || $value === 'true';
  }

  public static function isBlank(mixed $value): bool {
    return $value === NULL || (is_string($value) && trim($value) === '');
  }

  private static function normalise(mixed $value): string {
    if (is_bool($value)) {
      return $value ? '1' : '0';
    }
    if ($value === 't' || $value === 'true') {
      return '1';
    }
    if ($value === 'f' || $value === 'false') {
      return '0';
    }
    if (is_array($value)) {
      $value = array_map('strval', $value);
      sort($value);
      return implode(',', $value);
    }
    $value = trim((string) ($value ?? ''));
    // Treat 250, "250.00" and 250.0 as the same amount.
    if (is_numeric($value)) {
      return (string) (0 + $value);
    }
    return $value;
  }

}
