<?php

declare(strict_types=1);

namespace Drupal\bfep_i18n;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * The Languages settings staff control in the BFEP admin.
 *
 * Each language other than English is visible to everyone, to staff only (so
 * it can be reviewed before launch), or to nobody. English, the default
 * language, is always visible. The automatic language choice and the header
 * switch can each be turned off.
 */
final class LanguageSettings {

  /**
   * Everyone can see the language.
   */
  public const PUBLIC = 'public';

  /**
   * Only staff can see the language, to review it before launch.
   */
  public const STAFF = 'staff';

  /**
   * Nobody can see the language. Its translations are kept.
   */
  public const OFF = 'off';

  /**
   * Any of these permissions lets someone see a staff-only language.
   */
  public const PREVIEW_PERMISSIONS = [
    'administer bfep external data',
    'administer bfep languages',
    'translate interface',
  ];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Who can see a language: self::PUBLIC, self::STAFF or self::OFF.
   */
  public function visibility(string $langcode): string {
    if ($langcode === $this->defaultLangcode()) {
      return self::PUBLIC;
    }
    $value = $this->configFactory->get('bfep_i18n.settings')->get('visibility.' . $langcode);
    return in_array($value, [self::STAFF, self::OFF], TRUE) ? $value : self::PUBLIC;
  }

  /**
   * Whether visitors are sent to their language automatically.
   */
  public function automaticChoice(): bool {
    return $this->configFactory->get('bfep_i18n.settings')->get('automatic_choice') !== FALSE;
  }

  /**
   * Whether the header shows the language switch.
   */
  public function showSwitch(): bool {
    return $this->configFactory->get('bfep_i18n.settings')->get('switch') !== FALSE;
  }

  /**
   * Whether an account may see pages in a language.
   */
  public function canSee(string $langcode, AccountInterface $account): bool {
    return match ($this->visibility($langcode)) {
      self::PUBLIC => TRUE,
      self::STAFF => self::isStaff($account),
      default => FALSE,
    };
  }

  /**
   * Whether an account may see staff-only languages.
   */
  public static function isStaff(AccountInterface $account): bool {
    foreach (self::PREVIEW_PERMISSIONS as $permission) {
      if ($account->hasPermission($permission)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * URL path prefixes keyed by langcode, from the language module's settings.
   *
   * @return array<string, string>
   *   For example ['en' => '', 'ar' => 'ar'], or [] when languages are not
   *   told apart by path prefix.
   */
  public function prefixes(): array {
    $config = $this->configFactory->get('language.negotiation');
    if ($config->get('url.source') !== 'path_prefix') {
      return [];
    }
    return array_map(static fn ($prefix): string => trim((string) $prefix, '/'), (array) $config->get('url.prefixes'));
  }

  /**
   * The langcode of the site's default language.
   */
  public function defaultLangcode(): string {
    return (string) ($this->configFactory->get('system.site')->get('default_langcode') ?: 'en');
  }

  /**
   * A path in the language with the given path prefix.
   *
   * @param string $path
   *   A path without a language prefix, such as /campaigns.
   * @param string $prefix
   *   The language's prefix, such as ar, or an empty string.
   */
  public static function localize(string $path, string $prefix): string {
    if ($prefix === '') {
      return $path;
    }
    return '/' . $prefix . ($path === '/' ? '' : $path);
  }

  /**
   * Splits a path into its language and the path without the prefix.
   *
   * @param string $path
   *   A request path, such as /ar/campaigns.
   * @param array<string, string> $prefixes
   *   Path prefixes keyed by langcode, as prefixes() returns them.
   *
   * @return array{0: string|null, 1: string}
   *   The langcode whose non-empty prefix starts the path (or NULL when none
   *   does), and the path without it.
   */
  public static function split(string $path, array $prefixes): array {
    $first = explode('/', ltrim($path, '/'), 2);
    foreach ($prefixes as $langcode => $prefix) {
      if ($prefix !== '' && $first[0] === $prefix) {
        return [$langcode, '/' . ($first[1] ?? '')];
      }
    }
    return [NULL, $path];
  }

}
