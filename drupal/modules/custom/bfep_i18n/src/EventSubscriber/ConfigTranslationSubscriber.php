<?php

declare(strict_types=1);

namespace Drupal\bfep_i18n\EventSubscriber;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\bfep_i18n\Translations;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps the shipped Arabic in step when staff change the English.
 *
 * When staff edit the tagline or a policy page, the Arabic this module wrote
 * for the old English is removed, so Arabic visitors see the new English
 * rather than an out-of-date translation. When they change it back, the
 * Arabic returns. Translations staff wrote themselves are never touched.
 */
final class ConfigTranslationSubscriber implements EventSubscriberInterface {

  /**
   * Configuration whose English this module has translations for.
   */
  private const NAMES = ['bfep.settings', 'bfep_content.settings', 'system.site'];

  public function __construct(
    private readonly Translations $translations,
  ) {}

  public static function getSubscribedEvents(): array {
    return [ConfigEvents::SAVE => 'onSave'];
  }

  public function onSave(ConfigCrudEvent $event): void {
    $name = $event->getConfig()->getName();
    if (in_array($name, self::NAMES, TRUE) || str_starts_with($name, 'block.block.')) {
      $this->translations->syncConfig();
    }
  }

}
