<?php

declare(strict_types=1);

namespace Drupal\bfep\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\bfep\Service\BfepCacheInvalidator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Edits the public and internal fields of an external BFEP campaign.
 */
final class CampaignEditForm extends FormBase {

  protected int $campaignId = 0;
  protected object $campaign;

  public function __construct(
    protected Connection $database,
    protected BfepCacheInvalidator $cacheInvalidator,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bfep.database'),
      $container->get('bfep.cache_invalidator'),
    );
  }

  public function getFormId(): string {
    return 'bfep_campaign_edit_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $campaign_id = NULL): array {
    $this->campaignId = (int) $campaign_id;
    $this->campaign = $this->database->select('campaigns', 'c')
      ->fields('c')
      ->condition('id', $this->campaignId)
      ->condition('deleted_at', NULL, 'IS NULL')
      ->execute()
      ->fetchObject();
    if (!$this->campaign) {
      throw new NotFoundHttpException();
    }

    $countries = [];
    foreach ($this->database->query('SELECT id, name, global_region FROM countries ORDER BY name')->fetchAll() as $row) {
      $label = (string) $row->name;
      if (trim((string) ($row->global_region ?? '')) !== '') {
        $label .= ' — ' . $row->global_region;
      }
      $countries[(int) $row->id] = $label;
    }

    $form['#attached']['library'][] = 'bfep/admin';
    $form['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['bfep-admin-edit-summary']],
      'text' => [
        '#markup' => '<strong>Campaign #' . $this->campaignId . '</strong>'
          . (!empty($this->campaign->line_number) ? ' · Line ' . htmlspecialchars((string) $this->campaign->line_number, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : ''),
      ],
      'preview' => [
        '#type' => 'link',
        '#title' => $this->t('View public page'),
        '#url' => Url::fromRoute('bfep.campaign_detail', ['campaign_id' => $this->campaignId]),
        '#attributes' => ['class' => ['button'], 'target' => '_blank', 'rel' => 'noopener'],
      ],
    ];

    $form['identity'] = ['#type' => 'details', '#title' => $this->t('Campaign identity'), '#open' => TRUE];
    $form['identity']['contact_name'] = [
      '#type' => 'textfield', '#title' => $this->t('Contact / campaign name'),
      '#default_value' => $this->campaign->contact_name, '#required' => TRUE, '#maxlength' => 500,
    ];
    $form['identity']['country_id'] = [
      '#type' => 'select', '#title' => $this->t('Country'), '#options' => $countries,
      '#default_value' => (int) $this->campaign->country_id, '#required' => TRUE,
      '#empty_option' => $this->t('- Select country -'),
      '#description' => $this->t('This updates both the normalized country relation used by public pages and the legacy country text.'),
    ];
    $form['identity']['career'] = [
      '#type' => 'textfield', '#title' => $this->t('Career / background'),
      '#default_value' => $this->campaign->career, '#maxlength' => 500,
    ];

    $form['public_content'] = ['#type' => 'details', '#title' => $this->t('Public content'), '#open' => TRUE];
    $form['public_content']['description_help'] = [
      '#markup' => '<p><strong>' . $this->t('Public field:') . '</strong> ' . $this->t('Write a respectful reader-facing summary. Do not include contact details, private verification evidence, workflow instructions, or internal volunteer notes.') . '</p>',
    ];
    $form['public_content']['description'] = [
      '#type' => 'textarea', '#title' => $this->t('Public description'),
      '#default_value' => $this->campaign->description, '#rows' => 12, '#maxlength' => 5000,
    ];

    $form['classification'] = ['#type' => 'details', '#title' => $this->t('Classification'), '#open' => TRUE];
    $form['classification']['featured_by_bfep'] = [
      '#type' => 'checkbox', '#title' => $this->t('Featured by BFEP'),
      '#default_value' => $this->truthy($this->campaign->featured_by_bfep ?? NULL),
    ];
    $form['classification']['urgent_medical_needs'] = [
      '#type' => 'checkbox', '#title' => $this->t('Urgent medical needs'),
      '#default_value' => $this->truthy($this->campaign->urgent_medical_needs ?? NULL),
    ];
    $form['classification']['vetted_by_trusted_group'] = [
      '#type' => 'textfield', '#title' => $this->t('Vetted by trusted group'),
      '#default_value' => $this->campaign->vetted_by_trusted_group, '#maxlength' => 500,
    ];
    $form['classification']['featured_self_selected'] = [
      '#type' => 'textfield', '#title' => $this->t('Featured self-selected'),
      '#default_value' => $this->campaign->featured_self_selected, '#maxlength' => 500,
    ];

    $form['internal'] = ['#type' => 'details', '#title' => $this->t('Internal information'), '#open' => FALSE];
    $form['internal']['internal_notes'] = [
      '#type' => 'textarea', '#title' => $this->t('Internal notes'),
      '#default_value' => $this->campaign->internal_notes, '#rows' => 8, '#maxlength' => 10000,
      '#description' => $this->t('Internal only. This field is never selected by the public controllers, templates, search results, or sitemap.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save campaign'), '#button_type' => 'primary'];
    $form['actions']['cancel'] = [
      '#type' => 'link', '#title' => $this->t('Back to campaigns'),
      '#url' => Url::fromRoute('bfep.admin_campaigns'), '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $countryId = (int) $form_state->getValue('country_id');
    if ($countryId < 1 || !(int) $this->database->query('SELECT CASE WHEN EXISTS (SELECT 1 FROM countries WHERE id = :id) THEN 1 ELSE 0 END', [':id' => $countryId])->fetchField()) {
      $form_state->setErrorByName('country_id', $this->t('Select a valid country.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $countryId = (int) $form_state->getValue('country_id');
    $countryName = $this->database->query('SELECT name FROM countries WHERE id = :id', [':id' => $countryId])->fetchField();
    if ($countryName === FALSE) {
      throw new \RuntimeException('Selected BFEP country no longer exists.');
    }
    $values = $form_state->getValues();
    $this->database->update('campaigns')->fields([
      'contact_name' => trim((string) $values['contact_name']),
      'country_id' => $countryId,
      'country_raw' => (string) $countryName,
      'description' => trim((string) $values['description']),
      'career' => trim((string) $values['career']),
      'featured_by_bfep' => !empty($values['featured_by_bfep']) ? 'true' : 'false',
      'urgent_medical_needs' => !empty($values['urgent_medical_needs']) ? 'true' : 'false',
      'vetted_by_trusted_group' => trim((string) $values['vetted_by_trusted_group']),
      'featured_self_selected' => trim((string) $values['featured_self_selected']),
      'internal_notes' => trim((string) $values['internal_notes']),
      'updated_at' => date('c'),
    ])->condition('id', $this->campaignId)->execute();

    $this->cacheInvalidator->invalidateCampaign($this->campaignId);
    $this->messenger()->addStatus($this->t('Campaign saved. Public caches for this campaign, listings, countries, and the homepage were invalidated.'));
    $form_state->setRedirect('bfep.admin_campaign_edit', ['campaign_id' => $this->campaignId]);
  }

  private function truthy(mixed $value): bool {
    return $value === TRUE || $value === 1 || $value === '1'
      || $value === 't' || $value === 'true';
  }

}
