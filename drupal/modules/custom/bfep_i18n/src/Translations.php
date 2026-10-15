<?php

declare(strict_types=1);

namespace Drupal\bfep_i18n;

use Drupal\Component\Gettext\PoItem;
use Drupal\Component\Gettext\PoStreamReader;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslationManager;
use Drupal\bfep\Controller\TrustController;
use Drupal\language\Config\LanguageConfigFactoryOverrideInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\locale\PluralFormulaInterface;
use Drupal\locale\StringStorageInterface;

/**
 * Loads the site's shipped translations into Drupal.
 *
 * Two kinds of text are translated:
 * - Interface strings (labels, headings, form text, messages) come from
 *   translations/LANGCODE.po in this module and go into the locale tables.
 * - Text staff can edit in BFEP settings (the tagline, page titles, policy
 *   pages, the Instagram message) is configuration. Its Arabic goes into
 *   configuration translations, but only where the English is still the text
 *   this module knows. Edited English is left for staff to translate on the
 *   settings form's Translate tab.
 *
 * sync() runs on install and on every cache rebuild (so `bfep deploy`, which
 * runs drush cr, picks up a changed .po file). It only re-imports a .po file
 * when the file changed, and only replaces a translation that still holds
 * what this module wrote last time, so wording a reviewer changed on the
 * Interface translation page or a settings form's Translate tab is kept.
 *
 * Imported strings are marked customised, so translations Drupal downloads
 * from localize.drupal.org (drush locale:update) never replace them.
 */
final class Translations {

  /**
   * State key: hash of each imported .po file, keyed by langcode.
   */
  private const STATE_PO = 'bfep_i18n.po_hashes';

  /**
   * State key: the configuration translations this module last wrote.
   */
  private const STATE_CONFIG = 'bfep_i18n.config_written';

  /**
   * State key: hashes of the interface translations this module last wrote.
   */
  private const STATE_STRINGS = 'bfep_i18n.strings_written';

  public function __construct(
    private readonly ModuleExtensionList $moduleList,
    private readonly LanguageManagerInterface $languageManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly LoggerChannelInterface $logger,
    private readonly StringStorageInterface $localeStorage,
    private readonly PluralFormulaInterface $pluralFormula,
    private readonly ClassResolverInterface $classResolver,
    private readonly TranslationManager $translationManager,
    private readonly LanguageConfigFactoryOverrideInterface $configOverride,
  ) {}

  /**
   * Imports changed .po files and refreshes configuration translations.
   *
   * @param bool $force
   *   Re-import .po files even when they have not changed.
   */
  public function sync(bool $force = FALSE): void {
    foreach ($this->languageManager->getLanguages() as $langcode => $language) {
      if ($langcode === 'en') {
        continue;
      }
      try {
        $this->importInterface($langcode, $force);
        $this->translateConfig($langcode);
      }
      catch (\Throwable $exception) {
        $this->logger->error('Could not load the @language translations: @message', [
          '@language' => $language->getName(),
          '@message' => $exception->getMessage(),
        ]);
      }
    }
  }

  /**
   * Refreshes the configuration translations after the English changed.
   */
  public function syncConfig(): void {
    foreach (array_keys($this->languageManager->getLanguages()) as $langcode) {
      if ($langcode !== 'en') {
        $this->translateConfig($langcode);
      }
    }
  }

  /**
   * Imports translations/LANGCODE.po when it is new or has changed.
   */
  private function importInterface(string $langcode, bool $force): void {
    $path = $this->moduleList->getPath('bfep_i18n') . '/translations/' . $langcode . '.po';
    if (!is_file($path)) {
      return;
    }
    $hashes = (array) $this->state->get(self::STATE_PO, []);
    $hash = (string) hash_file('sha256', $path);
    if (!$force && ($hashes[$langcode] ?? NULL) === $hash) {
      return;
    }

    $this->moduleHandler->loadInclude('locale', 'module');
    $this->moduleHandler->loadInclude('locale', 'bulk.inc');
    $this->moduleHandler->loadInclude('locale', 'translation.inc');

    $reader = new PoStreamReader();
    $reader->setLangcode($langcode);
    $reader->setURI($path);
    $reader->open();
    $plurals = $reader->getHeader()->getPluralForms();
    if ($plurals !== NULL && ($parsed = $reader->getHeader()->parsePluralForms($plurals))) {
      [$count, $formula] = $parsed;
      $this->pluralFormula->setPluralFormula($langcode, $count, $formula);
    }

    $written = (array) $this->state->get(self::STATE_STRINGS, []);
    $lids = [];
    $updated = 0;
    $kept = 0;
    while ($item = $reader->readItem()) {
      $source = $item->getSource();
      $translation = $item->getTranslation();
      if ($item->isPlural()) {
        $source = implode(PoItem::DELIMITER, (array) $source);
        $translation = implode(PoItem::DELIMITER, (array) $translation);
      }
      $source = (string) $source;
      $translation = (string) $translation;
      $context = (string) $item->getContext();
      if ($source === '' || trim(str_replace(PoItem::DELIMITER, '', $translation)) === '' || !locale_string_is_safe($translation)) {
        continue;
      }
      $id = md5($langcode . ':' . $context . "\x04" . $source);

      $string = $this->localeStorage->findTranslation([
        'language' => $langcode,
        'source' => $source,
        'context' => $context,
      ]);
      if ($string === NULL) {
        $string = $this->localeStorage->createString(['source' => $source, 'context' => $context])->save();
        $string = $this->localeStorage->createTranslation(['lid' => $string->getId(), 'language' => $langcode]);
      }
      $current = $string->isNew() ? NULL : (string) $string->getString();
      // A customised translation that is not what this module last wrote was
      // changed by a person, so it stays.
      if ($current !== NULL && $current !== $translation && $string->customized && ($written[$id] ?? NULL) !== md5($current)) {
        $kept++;
        continue;
      }
      if ($current !== $translation || !$string->customized) {
        $string->setString($translation);
        $string->setValues(['language' => $langcode]);
        $string->setCustomized(TRUE)->save();
        $lids[] = (int) $string->getId();
        $updated++;
      }
      $written[$id] = md5($translation);
    }
    $reader->close();

    if ($lids) {
      _locale_refresh_translations([$langcode], $lids);
      // Shipped configuration (block titles, menu names) whose source strings
      // just got a translation is translated too.
      _locale_refresh_configuration([$langcode], $lids);
    }

    $this->state->set(self::STATE_STRINGS, $written);
    $hashes[$langcode] = $hash;
    $this->state->set(self::STATE_PO, $hashes);
    $this->logger->notice('Loaded @count @langcode interface translations from @file; kept @kept that were changed on the site.', [
      '@count' => $updated,
      '@langcode' => $langcode,
      '@file' => basename($path),
      '@kept' => $kept,
    ]);
  }

  /**
   * Writes configuration translations for text still in its shipped English.
   */
  private function translateConfig(string $langcode): void {
    if (!$this->languageManager instanceof ConfigurableLanguageManagerInterface) {
      return;
    }
    // Settings such as the organisation name go first: the policy pages'
    // translations are built from them.
    $this->writeConfig($langcode, $this->shippedConfig()[$langcode] ?? []);
    $this->writeConfig($langcode, $this->policyTranslations($langcode));
    $this->writeConfig($langcode, $this->blockTranslations($langcode));
  }

  /**
   * Translations of block titles, such as the menu's "Main navigation".
   *
   * The theme's blocks were copied from Olivero's when it was installed, so
   * Drupal does not know them as shipped configuration and leaves their titles
   * untranslated. Screen readers announce those titles, so each block whose
   * title is an interface string with a translation gets that translation.
   *
   * @return array<string, array<string, array{0: string, 1: string|null}>>
   *   Pairs of [English, translation or NULL], keyed by config name and key.
   */
  private function blockTranslations(string $langcode): array {
    $translations = [];
    foreach ($this->configFactory->listAll('block.block.') as $name) {
      $label = trim((string) $this->configFactory->get($name)->get('settings.label'));
      if ($label === '') {
        continue;
      }
      $string = $this->localeStorage->findTranslation(['language' => $langcode, 'source' => $label, 'context' => '']);
      $translated = $string?->isTranslation() ? trim((string) $string->getString()) : '';
      $translations[$name]['settings.label'] = [$label, $translated !== '' ? $translated : NULL];
    }
    return $translations;
  }

  /**
   * Saves configuration translations that are still this module's to set.
   *
   * @param string $langcode
   *   The language the translations are in.
   * @param array<string, array<string, array{0: string, 1: string|null}>> $translations
   *   Pairs of [English this module knows, translation], keyed by config
   *   name and then by key. A NULL translation removes this module's earlier
   *   translation of that key.
   */
  private function writeConfig(string $langcode, array $translations): void {
    $written = (array) $this->state->get(self::STATE_CONFIG, []);
    foreach ($translations as $name => $values) {
      $source = $this->configFactory->get($name);
      if ($source->isNew()) {
        continue;
      }
      $override = $this->languageManager->getLanguageConfigOverride($langcode, $name);
      $changed = FALSE;
      foreach ($values as $key => [$english, $translated]) {
        $id = $langcode . ':' . $name . ':' . $key;
        $current = $override->get($key);
        $empty = $current === NULL || $current === '';
        // A translation someone wrote on the site stays.
        if (!$empty && ($written[$id] ?? NULL) !== $current) {
          continue;
        }
        if ($translated === NULL || trim((string) $source->getOriginal($key, FALSE)) !== trim($english)) {
          // The English was changed, so this module's translation is of text
          // that is gone. Remove it: the new English shows until someone
          // translates it on the Translate tab.
          if (!$empty) {
            $override->clear($key);
            $changed = TRUE;
          }
          unset($written[$id]);
          continue;
        }
        if ($current !== $translated) {
          $override->set($key, $translated);
          $changed = TRUE;
        }
        $written[$id] = $translated;
      }
      if ($changed) {
        if (self::blank($override->get())) {
          $override->delete();
        }
        else {
          $override->save();
        }
        $this->configFactory->reset($name);
      }
    }
    $this->state->set(self::STATE_CONFIG, $written);
  }

  /**
   * Whether a configuration value holds no text at all.
   */
  private static function blank(mixed $value): bool {
    if (is_array($value)) {
      foreach ($value as $item) {
        if (!self::blank($item)) {
          return FALSE;
        }
      }
      return TRUE;
    }
    return $value === NULL || $value === '';
  }

  /**
   * Translations of the policy pages bfep_content saved, per key.
   *
   * @return array<string, array<string, array{0: string, 1: string}>>
   *   Pairs of [English, translation], keyed by config name and key.
   */
  private function policyTranslations(string $langcode): array {
    $translations = [];
    // The policy pages were saved from TrustController's text when
    // bfep_content was installed. Where they are unchanged, their
    // translation is the same text built in the other language.
    if ($this->moduleHandler->moduleExists('bfep_content')) {
      $this->moduleHandler->loadInclude('bfep_content', 'install');
      foreach (array_keys(\bfep_content_policy_pages()) as $page) {
        $english = $this->inLanguage('en', fn(): ?array => $this->policyPage($page));
        $translated = $this->inLanguage($langcode, fn(): ?array => $this->policyPage($page));
        if ($english === NULL || $translated === NULL) {
          continue;
        }
        $translations['bfep_content.settings']['policies.' . $page . '.intro'] = [$english['intro'], $translated['intro']];
        $translations['bfep_content.settings']['policies.' . $page . '.body.value'] = [$english['body'], $translated['body']];
      }
    }
    return $translations;
  }

  /**
   * A policy page as bfep_content_install() would save it.
   *
   * @return array{intro: string, body: string}|null
   *   The intro and body HTML, or NULL for an unknown page.
   */
  private function policyPage(string $page): ?array {
    try {
      $build = $this->classResolver->getInstanceFromDefinition(TrustController::class)->page($page);
    }
    catch (\Throwable) {
      return NULL;
    }
    return [
      'intro' => trim((string) ($build['#intro'] ?? '')),
      'body' => \bfep_content_sections_to_html((array) ($build['#sections'] ?? [])),
    ];
  }

  /**
   * Runs a callback with interface text and configuration in a language.
   */
  private function inLanguage(string $langcode, callable $callback): mixed {
    $language = $this->languageManager->getLanguage($langcode);
    if ($language === NULL) {
      return NULL;
    }
    $previousLanguage = $this->configOverride->getLanguage();
    $previousLangcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)->getId();
    $this->translationManager->setDefaultLangcode($langcode);
    $this->configOverride->setLanguage($language);
    try {
      return $callback();
    }
    finally {
      $this->translationManager->setDefaultLangcode($previousLangcode);
      $this->configOverride->setLanguage($previousLanguage);
    }
  }

  /**
   * Translations of the English that BFEP settings ship with.
   *
   * @return array<string, array<string, array<string, array{0: string, 1: string}>>>
   *   Keyed by langcode, config name and key.
   */
  private function shippedConfig(): array {
    return [
      'ar' => [
        'bfep.settings' => [
          'organization_name' => ['The Butterfly Effect Project', 'مشروع أثر الفراشة'],
          'title_suffix' => ['| The Butterfly Effect Project', '| مشروع أثر الفراشة'],
          'tagline' => ['Helping those in need be seen, shared, and supported.', 'لكي يُرى المحتاجون، وتُشارَك قصصهم، ويجدوا من يسندهم.'],
          'operator_name' => ['The Butterfly Effect Project volunteer team', 'فريق متطوعي مشروع أثر الفراشة'],
          'operator_location' => ['Aotearoa New Zealand', 'أوتياروا نيوزيلندا'],
          'policy_last_updated' => ['3 September 2026', '3 سبتمبر 2026'],
          'home_title' => ['Humanitarian Aid', 'المساعدات الإنسانية'],
          'home_description' => [
            'Browse humanitarian fundraising campaigns, discover urgent cases by country, submit referrals, and help keep campaign information current.',
            'تصفّح حملات جمع التبرعات الإنسانية، واكتشف الحالات العاجلة حسب البلد، وأرسل ترشيحاتك، وساعدنا في إبقاء معلومات الحملات محدّثة.',
          ],
          'country_title_pattern' => ['Humanitarian Fundraisers in [country:name]', 'حملات جمع التبرعات الإنسانية في [country:name]'],
        ],
        'bfep_content.settings' => [
          'instagram.message' => [
            'Instagram is our main official channel for communication, project updates, and campaign amplification.',
            'إنستغرام هو قناتنا الرسمية الرئيسية للتواصل ونشر مستجدات المشروع وإيصال صوت الحملات إلى عدد أكبر من الناس.',
          ],
        ],
        'system.site' => [
          'name' => ['The Butterfly Effect Project', 'مشروع أثر الفراشة'],
        ],
      ],
    ];
  }

}
