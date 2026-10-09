<?php

declare(strict_types=1);

namespace Drupal\bfep\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\bfep\Service\BfepCacheInvalidator;
use Drupal\bfep\Service\BfepSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Site-owner UI for public metadata and operational controls.
 */
final class BfepSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
    protected BfepCacheInvalidator $cacheInvalidator,
  ) {
    parent::__construct($configFactory, $typedConfigManager);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('bfep.cache_invalidator'),
    );
  }

  public function getFormId(): string {
    return 'bfep_settings_form';
  }

  protected function getEditableConfigNames(): array {
    return [BfepSettings::CONFIG_NAME];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(BfepSettings::CONFIG_NAME);

    $form['identity'] = [
      '#type' => 'details',
      '#title' => $this->t('Identity and contact'),
      '#open' => TRUE,
    ];
    $this->addText($form['identity'], $config, 'base_url', 'Canonical base URL', 255, 'The public HTTPS origin, without a trailing slash.');
    $this->addText($form['identity'], $config, 'organization_name', 'Organisation name');
    $this->addText($form['identity'], $config, 'tagline', 'Homepage headline', 255);
    $this->addText($form['identity'], $config, 'operator_name', 'Project operator');
    $this->addText($form['identity'], $config, 'operator_location', 'Operator location');
    $this->addText($form['identity'], $config, 'technical_operator', 'Technical operator');
    $this->addText($form['identity'], $config, 'policy_last_updated', 'Policy pages last updated', 100, 'Displayed on transparency, privacy, editorial, and safeguarding pages.');
    foreach (['public_contact_email' => 'Public contact email', 'privacy_contact_email' => 'Privacy contact email'] as $key => $label) {
      $form['identity'][$key] = [
        '#type' => 'email',
        '#title' => $this->t($label),
        '#default_value' => $config->get($key),
        '#maxlength' => 254,
        '#description' => $this->t($key === 'public_contact_email'
          ? 'Optional. Leave blank to direct visitors to the public forms only.'
          : 'Optional. Falls back to the public contact email.'),
      ];
    }

    $form['seo'] = [
      '#type' => 'details',
      '#title' => $this->t('Search and social metadata'),
      '#open' => TRUE,
    ];
    $this->addText($form['seo'], $config, 'title_suffix', 'Browser-title suffix', 100, 'For example: “| The Butterfly Effect Project”. Separator spacing is added automatically.');
    $this->addText($form['seo'], $config, 'home_title', 'Homepage browser title', 100);
    $form['seo']['home_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Homepage search description'),
      '#default_value' => $config->get('home_description'),
      '#rows' => 3,
      '#maxlength' => 320,
    ];
    $this->addText($form['seo'], $config, 'campaign_title_pattern', 'Campaign title pattern', 160, 'Tokens: [campaign:name] and [campaign:country].');
    $this->addText($form['seo'], $config, 'country_title_pattern', 'Country title pattern', 160, 'Token: [country:name].');
    $this->addText($form['seo'], $config, 'default_share_image', 'Default share image', 2048, 'An HTTPS URL or a root-relative path such as /themes/custom/butterfly/share.jpg.');
    $form['seo']['social_links'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Official social profile URLs'),
      '#default_value' => $config->get('social_links'),
      '#rows' => 4,
      '#description' => $this->t('One complete HTTPS URL per line.'),
    ];
    $this->addText($form['seo'], $config, 'google_site_verification', 'Google site-verification token', 255);
    $this->addText($form['seo'], $config, 'bing_site_verification', 'Bing site-verification token', 255);

    $form['indexing'] = [
      '#type' => 'details',
      '#title' => $this->t('Indexing'),
      '#open' => FALSE,
    ];
    foreach ([
      'index_referral_page' => 'Allow indexing of the referral page',
      'index_volunteer_page' => 'Allow indexing of the volunteer page',
      'index_initiatives_page' => 'Allow indexing of ground initiatives',
      'index_transparency_pages' => 'Allow indexing of trust and accountability pages',
    ] as $key => $label) {
      $form['indexing'][$key] = [
        '#type' => 'checkbox',
        '#title' => $this->t($label),
        '#default_value' => (bool) $config->get($key),
      ];
    }

    $form['staff'] = [
      '#type' => 'details',
      '#title' => $this->t('Staff'),
      '#open' => FALSE,
    ];
    $form['staff']['staff_guide_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Full staff guide URL'),
      '#default_value' => $config->get('staff_guide_url'),
      '#maxlength' => 2048,
      '#description' => $this->t('For example, the Nextcloud Collectives page. It is linked from the top of BFEP Admin › Staff guide.'),
    ];

    $form['performance'] = [
      '#type' => 'details',
      '#title' => $this->t('Caching and submission protection'),
      '#open' => FALSE,
      '#description' => $this->t('Values are in seconds. BFEP edits invalidate affected external-data caches immediately; cron provides a backstop for other changes.'),
    ];
    foreach ([
      'public_cache_max_age' => ['Public page/data cache', 60, 86400],
      'listing_cache_max_age' => ['Campaign and country listing cache', 60, 86400],
      'search_cache_max_age' => ['Anonymous search cache', 60, 86400],
      'submission_limit' => ['Submissions allowed per connection/window', 1, 50],
      'submission_window' => ['Submission rate-limit window', 60, 86400],
    ] as $key => [$label, $min, $max]) {
      $form['performance'][$key] = [
        '#type' => 'number',
        '#title' => $this->t($label),
        '#default_value' => (int) $config->get($key),
        '#required' => TRUE,
        '#min' => $min,
        '#max' => $max,
        '#step' => 1,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $baseUrl = rtrim(trim((string) $form_state->getValue('base_url')), '/');
    if (!filter_var($baseUrl, FILTER_VALIDATE_URL) || strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)) !== 'https') {
      $form_state->setErrorByName('base_url', $this->t('Enter a complete HTTPS URL.'));
    }
    if (!in_array((string) parse_url($baseUrl, PHP_URL_PATH), ['', '/'], TRUE)) {
      $form_state->setErrorByName('base_url', $this->t('The base URL must not contain a path.'));
    }

    $shareImage = trim((string) $form_state->getValue('default_share_image'));
    if ($shareImage !== '' && !str_starts_with($shareImage, '/')
      && (!filter_var($shareImage, FILTER_VALIDATE_URL) || strtolower((string) parse_url($shareImage, PHP_URL_SCHEME)) !== 'https')) {
      $form_state->setErrorByName('default_share_image', $this->t('Use an HTTPS URL or a root-relative path beginning with /.'));
    }
    foreach (preg_split('/\R/u', (string) $form_state->getValue('social_links')) ?: [] as $line) {
      $line = trim($line);
      if ($line !== '' && (!filter_var($line, FILTER_VALIDATE_URL) || !str_starts_with(strtolower($line), 'https://'))) {
        $form_state->setErrorByName('social_links', $this->t('Every social profile must be a complete HTTPS URL, one per line.'));
        break;
      }
    }
    $guideUrl = trim((string) $form_state->getValue('staff_guide_url'));
    if ($guideUrl !== '' && !str_starts_with(strtolower($guideUrl), 'https://')) {
      $form_state->setErrorByName('staff_guide_url', $this->t('Use a complete HTTPS URL.'));
    }
    if (!str_contains((string) $form_state->getValue('campaign_title_pattern'), '[campaign:name]')) {
      $form_state->setErrorByName('campaign_title_pattern', $this->t('The campaign pattern must include [campaign:name].'));
    }
    if (!str_contains((string) $form_state->getValue('country_title_pattern'), '[country:name]')) {
      $form_state->setErrorByName('country_title_pattern', $this->t('The country pattern must include [country:name].'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory()->getEditable(BfepSettings::CONFIG_NAME);
    $config->set('base_url', rtrim(trim((string) $form_state->getValue('base_url')), '/'));
    $config->set('social_links', implode("\n", array_values(array_filter(array_map(
      'trim',
      preg_split('/\R/u', (string) $form_state->getValue('social_links')) ?: [],
    )))));
    $integerKeys = ['public_cache_max_age', 'listing_cache_max_age', 'search_cache_max_age', 'submission_limit', 'submission_window'];
    foreach ([
      'organization_name', 'title_suffix', 'tagline', 'operator_name',
      'operator_location', 'technical_operator', 'policy_last_updated',
      'public_contact_email', 'privacy_contact_email', 'default_share_image',
      'google_site_verification', 'bing_site_verification', 'home_title',
      'home_description', 'campaign_title_pattern', 'country_title_pattern',
      ...$integerKeys, 'index_referral_page', 'index_volunteer_page',
      'index_initiatives_page', 'index_transparency_pages', 'staff_guide_url',
    ] as $key) {
      $value = $form_state->getValue($key);
      if (str_starts_with($key, 'index_')) {
        $value = (bool) $value;
      }
      elseif (in_array($key, $integerKeys, TRUE)) {
        $value = (int) $value;
      }
      elseif (is_string($value)) {
        $value = trim($value);
      }
      $config->set($key, $value);
    }
    $config->save();
    // Keep Drupal's anonymous Page Cache ceiling aligned with the BFEP public
    // cache control instead of applying this setting to BFEP routes alone.
    $this->configFactory()->getEditable('system.performance')
      ->set('cache.page.max_age', (int) $config->get('public_cache_max_age'))
      ->save(TRUE);
    $sitemap = $this->configFactory()->getEditable('simple_sitemap.settings');
    if (!$sitemap->isNew()) {
      $sitemap->set('base_url', $config->get('base_url'))->save(TRUE);
    }
    $this->cacheInvalidator->invalidatePublic();
    parent::submitForm($form, $form_state);
  }

  private function addText(array &$section, $config, string $key, string $title, int $maxlength = 255, string $description = ''): void {
    $section[$key] = [
      '#type' => 'textfield',
      '#title' => $this->t($title),
      '#default_value' => $config->get($key),
      '#required' => in_array($key, ['base_url', 'organization_name', 'tagline', 'home_title', 'campaign_title_pattern', 'country_title_pattern'], TRUE),
      '#maxlength' => $maxlength,
    ];
    if ($description !== '') {
      $section[$key]['#description'] = $this->t($description);
    }
  }

}
