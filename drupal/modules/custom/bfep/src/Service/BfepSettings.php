<?php

declare(strict_types=1);

namespace Drupal\bfep\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Typed access to BFEP public-site configuration with safe upgrade defaults.
 */
final class BfepSettings {

  public const CONFIG_NAME = 'bfep.settings';

  private const DEFAULTS = [
    'base_url' => 'https://butterfly.joshcross.co.nz',
    'organization_name' => 'The Butterfly Effect Project',
    'title_suffix' => '| The Butterfly Effect Project',
    'tagline' => 'Helping those in need be seen, shared, and supported.',
    'operator_name' => 'The Butterfly Effect Project volunteer team',
    'operator_location' => 'Aotearoa New Zealand',
    'technical_operator' => 'Josh Cross Creative',
    'policy_last_updated' => '3 September 2026',
    'public_contact_email' => '',
    'privacy_contact_email' => '',
    'default_share_image' => '',
    'social_links' => '',
    'google_site_verification' => '',
    'bing_site_verification' => '',
    'home_title' => 'Humanitarian Aid',
    'home_description' => 'Browse humanitarian fundraising campaigns, discover urgent cases by country, submit referrals, and help keep campaign information current.',
    'campaign_title_pattern' => '[campaign:name] – [campaign:country]',
    'country_title_pattern' => 'Humanitarian Fundraisers in [country:name]',
    'public_cache_max_age' => 900,
    'listing_cache_max_age' => 300,
    'search_cache_max_age' => 300,
    'submission_limit' => 5,
    'submission_window' => 3600,
    'index_referral_page' => TRUE,
    'index_volunteer_page' => TRUE,
    'index_initiatives_page' => FALSE,
    'index_transparency_pages' => TRUE,
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public function string(string $key): string {
    $value = $this->configFactory->get(self::CONFIG_NAME)->get($key);
    if ($value === NULL && array_key_exists($key, self::DEFAULTS)) {
      $value = self::DEFAULTS[$key];
    }
    return trim((string) ($value ?? ''));
  }

  public function integer(string $key): int {
    $value = $this->configFactory->get(self::CONFIG_NAME)->get($key);
    if ($value === NULL && array_key_exists($key, self::DEFAULTS)) {
      $value = self::DEFAULTS[$key];
    }
    return (int) ($value ?? 0);
  }

  public function boolean(string $key): bool {
    $value = $this->configFactory->get(self::CONFIG_NAME)->get($key);
    if ($value === NULL && array_key_exists($key, self::DEFAULTS)) {
      $value = self::DEFAULTS[$key];
    }
    return (bool) $value;
  }

  public function baseUrl(): string {
    $url = rtrim($this->string('base_url'), '/');
    if (!filter_var($url, FILTER_VALIDATE_URL)
      || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
      return self::DEFAULTS['base_url'];
    }
    return $url;
  }

  public function absoluteUrl(string $path = '/'): string {
    $path = '/' . ltrim($path, '/');
    return $this->baseUrl() . ($path === '/' ? '/' : $path);
  }

  public function organizationName(): string {
    return $this->string('organization_name');
  }

  public function titleSuffix(): string {
    $suffix = $this->string('title_suffix');
    return $suffix === '' ? '' : ' ' . $suffix;
  }

  public function publicCacheMaxAge(): int {
    return max(60, min(86400, $this->integer('public_cache_max_age')));
  }

  public function listingCacheMaxAge(): int {
    return max(60, min($this->publicCacheMaxAge(), $this->integer('listing_cache_max_age')));
  }

  public function searchCacheMaxAge(): int {
    return max(60, min($this->publicCacheMaxAge(), $this->integer('search_cache_max_age')));
  }

  /**
   * Returns one validated HTTPS social profile URL per configured line.
   */
  public function socialLinks(): array {
    $lines = preg_split('/\R/u', $this->string('social_links')) ?: [];
    return array_values(array_filter(array_map(static function (string $url): string {
      $url = trim($url);
      return filter_var($url, FILTER_VALIDATE_URL) && str_starts_with(strtolower($url), 'https://') ? $url : '';
    }, $lines)));
  }

  public function shareImageUrl(): string {
    $image = $this->string('default_share_image');
    if ($image === '') {
      return '';
    }
    if (str_starts_with($image, '/')) {
      return $this->baseUrl() . $image;
    }
    return filter_var($image, FILTER_VALIDATE_URL)
      && strtolower((string) parse_url($image, PHP_URL_SCHEME)) === 'https'
        ? $image
        : '';
  }

}
