<?php

declare(strict_types=1);

namespace Drupal\bfep\Admin;

/**
 * Turns a campaign's social media handles into links volunteers can open.
 *
 * Staff type either a full link or just a handle ("@name"), so the handle is
 * expanded with the platform's profile address. Values that cannot be turned
 * into a safe http(s) or mailto link get no Open button.
 */
final class SocialLinks {

  /**
   * Readable names for the social_platform enum labels.
   */
  public const LABELS = [
    'instagram' => 'Instagram',
    'facebook' => 'Facebook',
    'tiktok' => 'TikTok',
    'telegram' => 'Telegram',
    'whatsapp' => 'WhatsApp',
    'twitter' => 'Twitter / X',
    'youtube' => 'YouTube',
    'discord' => 'Discord',
    'linktree' => 'Linktree',
    'website' => 'Website',
    'email' => 'Email',
    'other' => 'Other',
  ];

  /**
   * Profile addresses for a bare handle, keyed by platform.
   */
  private const PROFILES = [
    'instagram' => 'https://www.instagram.com/%s/',
    'facebook' => 'https://www.facebook.com/%s',
    'tiktok' => 'https://www.tiktok.com/@%s',
    'telegram' => 'https://t.me/%s',
    'twitter' => 'https://x.com/%s',
    'youtube' => 'https://www.youtube.com/@%s',
    'linktree' => 'https://linktr.ee/%s',
  ];

  /**
   * The readable name of a platform.
   */
  public static function label(string $platform): string {
    return self::LABELS[$platform] ?? ucfirst(str_replace('_', ' ', $platform));
  }

  /**
   * The link to open for a stored handle or URL, or NULL when there is none.
   */
  public static function url(string $platform, ?string $value): ?string {
    $value = trim((string) $value);
    if ($value === '') {
      return NULL;
    }
    if (preg_match('~^https?://~i', $value)) {
      return AdminFormat::externalUrl($value);
    }
    if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
      return 'mailto:' . $value;
    }
    // A phone number: WhatsApp opens a chat with it.
    if ($platform === 'whatsapp' && preg_match('/^\+?[\d\-(). ]{7,}$/', $value)) {
      $digits = preg_replace('/\D/', '', $value);
      return strlen($digits) >= 7 ? 'https://wa.me/' . $digits : NULL;
    }
    if (preg_match('/\s/u', $value)) {
      return NULL;
    }
    // A link typed without https://, such as "instagram.com/name". On social
    // platforms "first.last" is a handle, so a bare domain needs a slash.
    $domain = '~^[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,}' . (isset(self::PROFILES[$platform]) ? '/' : '(/|$)') . '~i';
    if (preg_match($domain, $value) || preg_match('~^www\.~i', $value)) {
      return AdminFormat::externalUrl('https://' . $value);
    }
    $handle = ltrim($value, '@');
    if (isset(self::PROFILES[$platform]) && preg_match('/^[\w.\-]{1,100}$/u', $handle)) {
      return sprintf(self::PROFILES[$platform], rawurlencode($handle));
    }
    return NULL;
  }

}
