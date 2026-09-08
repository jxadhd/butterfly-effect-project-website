<?php

declare(strict_types=1);

namespace Drupal\bfep_content\Form;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Edits BFEP trust pages and the official communication channel.
 */
final class ContentSettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {
    parent::__construct($configFactory, $typedConfigManager);
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('cache_tags.invalidator'),
    );
  }

  public function getFormId(): string {
    return 'bfep_content_settings_form';
  }

  protected function getEditableConfigNames(): array {
    return ['bfep_content.settings', 'bfep.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('bfep_content.settings');
    $bfep = $this->config('bfep.settings');

    $form['instagram'] = [
      '#type' => 'details',
      '#title' => $this->t('Official Instagram channel'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#description' => $this->t('Displayed prominently on the homepage and in the footer of every public page. The profile is also added to the site’s Organization metadata.'),
    ];
    $form['instagram']['url'] = [
      '#type' => 'url',
      '#title' => $this->t('Official Instagram profile URL'),
      '#default_value' => $config->get('instagram.url'),
      '#required' => TRUE,
      '#maxlength' => 2048,
    ];
    $form['instagram']['handle'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Instagram handle'),
      '#default_value' => $config->get('instagram.handle'),
      '#required' => TRUE,
      '#maxlength' => 80,
      '#description' => $this->t('Include the @ symbol.'),
    ];
    $form['instagram']['message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Official-channel message'),
      '#default_value' => $config->get('instagram.message'),
      '#required' => TRUE,
      '#rows' => 3,
      '#maxlength' => 320,
    ];
    $form['instagram']['embed_note'] = [
      '#type' => 'item',
      '#title' => $this->t('Instagram feed'),
      '#markup' => $this->t('A feed is intentionally not loaded automatically. Instagram embeds add third-party scripts, tracking requests, and page weight. Individual public posts can be added later on an explicit click-to-load basis.'),
    ];

    $form['policy_last_updated'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Policy pages last updated'),
      '#default_value' => $bfep->get('policy_last_updated'),
      '#required' => TRUE,
      '#maxlength' => 100,
      '#description' => $this->t('Displayed beneath trust and policy pages.'),
    ];

    $form['policy_tabs'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('Trust and policy pages'),
    ];
    $form['policies'] = [
      '#tree' => TRUE,
    ];
    foreach (\bfep_content_policy_pages() as $pageId => $label) {
      $page = $config->get('policies.' . $pageId);
      $page = is_array($page) ? $page : [];
      $body = is_array($page['body'] ?? NULL) ? $page['body'] : [];
      $form['policies'][$pageId] = [
        '#type' => 'details',
        '#title' => $this->t($label),
        '#group' => 'policy_tabs',
      ];
      $form['policies'][$pageId]['intro'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Introduction'),
        '#default_value' => $page['intro'] ?? '',
        '#required' => TRUE,
        '#rows' => 3,
        '#maxlength' => 1000,
      ];
      $form['policies'][$pageId]['body'] = [
        '#type' => 'text_format',
        '#title' => $this->t('Page content'),
        '#default_value' => $body['value'] ?? '',
        '#format' => $body['format'] ?? 'basic_html',
        '#allowed_formats' => ['basic_html'],
        '#required' => TRUE,
      ];
    }

    $form['editor_note'] = [
      '#type' => 'item',
      '#markup' => $this->t('Page titles and action buttons remain code-managed so navigation and safety routes cannot be accidentally broken. Public and privacy email addresses remain under BFEP Settings.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $url = trim((string) $form_state->getValue(['instagram', 'url']));
    $parts = parse_url($url);
    if (!is_array($parts)
      || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
      || !in_array(strtolower((string) ($parts['host'] ?? '')), ['instagram.com', 'www.instagram.com'], TRUE)) {
      $form_state->setErrorByName('instagram][url', $this->t('Enter a complete HTTPS URL on instagram.com.'));
    }

    $handle = trim((string) $form_state->getValue(['instagram', 'handle']));
    if (!preg_match('/^@[A-Za-z0-9._]+$/', $handle)) {
      $form_state->setErrorByName('instagram][handle', $this->t('Enter a valid Instagram handle beginning with @.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->configFactory()->getEditable('bfep_content.settings');
    $previousUrl = trim((string) $config->get('instagram.url'));
    $managedUrl = trim((string) $config->get('instagram.managed_same_as'));
    $instagram = [
      'url' => rtrim(trim((string) $form_state->getValue(['instagram', 'url'])), '/') . '/',
      'handle' => trim((string) $form_state->getValue(['instagram', 'handle'])),
      'message' => trim((string) $form_state->getValue(['instagram', 'message'])),
    ];
    foreach (\bfep_content_policy_pages() as $pageId => $label) {
      $body = $form_state->getValue(['policies', $pageId, 'body']);
      $body = is_array($body) ? $body : [];
      $config->set('policies.' . $pageId, [
        'intro' => trim((string) $form_state->getValue(['policies', $pageId, 'intro'])),
        'body' => [
          'value' => trim((string) ($body['value'] ?? '')),
          'format' => 'basic_html',
        ],
      ]);
    }
    $bfep = $this->configFactory()->getEditable('bfep.settings');
    $links = array_values(array_filter(array_map(
      'trim',
      preg_split('/\R/u', (string) $bfep->get('social_links')) ?: [],
    ), static fn(string $url): bool => $managedUrl === '' || $url !== $managedUrl));
    if (!in_array($instagram['url'], $links, TRUE)) {
      $links[] = $instagram['url'];
      $managedUrl = $instagram['url'];
    }
    elseif ($managedUrl !== $previousUrl || $instagram['url'] !== $previousUrl) {
      // The matching URL was configured outside this module; do not claim it.
      $managedUrl = '';
    }
    $config
      ->set('instagram', $instagram)
      ->set('instagram.managed_same_as', $managedUrl)
      ->save(TRUE);
    $bfep
      ->set('social_links', implode("\n", $links))
      ->set('policy_last_updated', trim((string) $form_state->getValue('policy_last_updated')))
      ->save(TRUE);

    $this->cacheTagsInvalidator->invalidateTags([
      'config:bfep_content.settings',
      'config:bfep.settings',
    ]);
    parent::submitForm($form, $form_state);
  }

}
