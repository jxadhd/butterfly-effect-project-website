<?php

declare(strict_types=1);

namespace Drupal\bfep_i18n\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Drupal\bfep_i18n\LanguageSettings;
use Drupal\locale\StringStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Languages tab of the BFEP admin.
 *
 * Staff choose who can see each language (everyone, staff only for review,
 * or nobody) and turn the automatic language choice and the header switch on
 * or off.
 */
final class LanguageSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly StringStorageInterface $localeStorage,
    private readonly LanguageSettings $settings,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct($configFactory, $typedConfigManager);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('language_manager'),
      $container->get('locale.storage'),
      $container->get('bfep_i18n.language_settings'),
      $container->get('module_handler'),
    );
  }

  public function getFormId(): string {
    return 'bfep_i18n_settings_form';
  }

  protected function getEditableConfigNames(): array {
    return ['bfep_i18n.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('bfep_i18n.settings');
    $default = $this->settings->defaultLangcode();
    $counts = $this->localeStorage->countTranslations();

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('The public site is in English, and each language below has its own pages under its own address, such as /ar for Arabic. Staff pages are always in English.') . '</p>',
    ];

    $form['visibility'] = ['#tree' => TRUE];
    foreach ($this->languageManager->getLanguages() as $langcode => $language) {
      if ($langcode === $default) {
        continue;
      }
      $name = $language->getName();
      $form['visibility'][$langcode] = [
        '#type' => 'details',
        '#title' => $name,
        '#open' => TRUE,
      ];
      $form['visibility'][$langcode]['visibility'] = [
        '#type' => 'radios',
        '#title' => $this->t('Who can see the @language pages', ['@language' => $name]),
        '#options' => [
          LanguageSettings::PUBLIC => $this->t('Everyone'),
          LanguageSettings::STAFF => $this->t('Staff only, for review'),
          LanguageSettings::OFF => $this->t('Nobody'),
        ],
        '#default_value' => $this->settings->visibility($langcode),
        LanguageSettings::PUBLIC => [
          '#description' => $this->t('The pages are public. Search engines are told about them, and the language switch and the automatic choice offer them.'),
        ],
        LanguageSettings::STAFF => [
          '#description' => $this->t('Signed-in staff can open the pages and use the switch, so the wording can be checked before launch. Everyone else, search engines included, gets the English page.'),
        ],
        LanguageSettings::OFF => [
          '#description' => $this->t('The language is switched off and its addresses lead to the English pages. Its translations are kept, so it can be switched back on.'),
        ],
      ];
      $form['visibility'][$langcode]['preview'] = [
        '#type' => 'item',
        '#title' => $this->t('Preview'),
        '#markup' => $this->t('<a href=":url">Open the @language homepage</a>', [
          ':url' => Url::fromRoute('<front>', [], ['language' => $language])->toString(),
          '@language' => $name,
        ]),
      ];
      $form['visibility'][$langcode]['wording'] = [
        '#type' => 'item',
        '#title' => $this->t('Wording'),
        '#markup' => $this->t('@count translated texts. Change a label or sentence on <a href=":interface">User interface translation</a>. Change the headline, page titles, policy pages and Instagram message on the Translate tab of <a href=":content">Content and channels</a> or <a href=":settings">BFEP settings</a>.', [
          '@count' => $counts[$langcode] ?? 0,
          ':interface' => Url::fromRoute('locale.translate_page', [], ['query' => ['langcode' => $langcode]])->toString(),
          ':content' => Url::fromRoute($this->moduleHandler->moduleExists('bfep_content') ? 'config_translation.item.overview.bfep_content.settings' : 'config_translation.item.overview.bfep.settings')->toString(),
          ':settings' => Url::fromRoute('config_translation.item.overview.bfep.settings')->toString(),
        ]),
      ];
    }

    $form['choice'] = [
      '#type' => 'details',
      '#title' => $this->t('Choosing the language'),
      '#open' => TRUE,
    ];
    $form['choice']['automatic_choice'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Choose the language automatically'),
      '#default_value' => $config->get('automatic_choice') !== FALSE,
      '#description' => $this->t('On their first visit, visitors whose browser prefers another language to English are sent to its pages. A language picked with the switch is remembered for a year and wins from then on. Search engines are never redirected. When this is off, every address shows its own language.'),
    ];
    $form['choice']['switch'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show the language switch in the header'),
      '#default_value' => $config->get('switch') !== FALSE,
      '#description' => $this->t('It only offers languages the visitor can see, so the public does not see it while every other language is staff only.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('bfep_i18n.settings');
    $before = (array) $config->get('visibility');
    foreach ((array) $form_state->getValue('visibility') as $langcode => $values) {
      $config->set('visibility.' . $langcode, (string) $values['visibility']);
    }
    $config
      ->set('automatic_choice', (bool) $form_state->getValue('automatic_choice'))
      ->set('switch', (bool) $form_state->getValue('switch'))
      ->save();
    parent::submitForm($form, $form_state);

    if ((array) $config->get('visibility') != $before && $this->moduleHandler->moduleExists('simple_sitemap')) {
      $this->messenger()->addStatus($this->t('The sitemap lists the change after it is next rebuilt, which happens on cron.'));
    }
  }

}
