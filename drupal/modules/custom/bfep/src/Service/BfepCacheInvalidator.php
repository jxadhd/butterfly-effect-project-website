<?php

declare(strict_types=1);

namespace Drupal\bfep\Service;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;

/**
 * Centralises cache tags for data stored outside Drupal entities.
 */
final class BfepCacheInvalidator {

  public const CAMPAIGNS = 'bfep:campaigns';
  public const COUNTRIES = 'bfep:countries';
  public const HOME = 'bfep:home';
  public const INITIATIVES = 'bfep:initiatives';
  public const FORM_OPTIONS = 'bfep:form_options';

  public function __construct(
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  public function invalidateCampaign(int $campaignId = 0): void {
    $tags = [
      self::CAMPAIGNS,
      self::COUNTRIES,
      self::HOME,
      self::FORM_OPTIONS,
    ];
    if ($campaignId > 0) {
      $tags[] = 'bfep:campaign:' . $campaignId;
    }
    $this->cacheTagsInvalidator->invalidateTags($tags);
  }

  public function invalidateInitiatives(): void {
    $this->cacheTagsInvalidator->invalidateTags([
      self::INITIATIVES,
      self::HOME,
    ]);
  }

  public function invalidatePublic(): void {
    $this->cacheTagsInvalidator->invalidateTags([
      self::CAMPAIGNS,
      self::COUNTRIES,
      self::HOME,
      self::INITIATIVES,
      self::FORM_OPTIONS,
    ]);
  }

}
