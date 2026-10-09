<?php

declare(strict_types=1);

namespace Drupal\bfep\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\bfep\Admin\AdminFormat;
use Drupal\bfep\Service\AuditLogger;
use Drupal\bfep\Service\BfepCacheInvalidator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Edits the public and internal fields of an external BFEP campaign.
 */
final class CampaignEditForm extends FormBase {

  use SaveFailureTrait;

  protected int $campaignId = 0;
  protected object $campaign;

  /**
   * The campaign's active fundraiser row, if it has one.
   */
  protected ?object $fundraiser = NULL;

  /**
   * IDs of the tags currently attached to the campaign.
   *
   * @var int[]
   */
  protected array $tagIds = [];

  /**
   * Sync service columns of the active fundraiser, when the schema has them.
   */
  protected ?object $syncState = NULL;

  public function __construct(
    protected Connection $database,
    protected BfepCacheInvalidator $cacheInvalidator,
    protected AuditLogger $auditLogger,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bfep.database'),
      $container->get('bfep.cache_invalidator'),
      $container->get('bfep.audit_logger'),
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

    $this->fundraiser = $this->database->query('
      SELECT platform_id, url, currency_code, goal_amount, donated_amount
      FROM campaign_fundraisers
      WHERE campaign_id = :id AND is_active
      LIMIT 1
    ', [':id' => $this->campaignId])->fetchObject() ?: NULL;
    $this->tagIds = array_map('intval', $this->database->query(
      'SELECT tag_id FROM campaign_tags WHERE campaign_id = :id',
      [':id' => $this->campaignId],
    )->fetchCol());

    $platforms = [];
    foreach ($this->database->query('SELECT id, name FROM fundraising_platforms ORDER BY name')->fetchAll() as $row) {
      $platforms[(int) $row->id] = (string) $row->name;
    }
    $tags = [];
    foreach ($this->database->query('SELECT id, name FROM tags ORDER BY name')->fetchAll() as $row) {
      $tags[(int) $row->id] = (string) $row->name;
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
      '#type' => 'textfield',
      '#title' => $this->t('Contact / campaign name'),
      '#default_value' => $this->campaign->contact_name,
      '#required' => TRUE,
      '#maxlength' => 500,
    ];
    $form['identity']['country_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Country'),
      '#options' => $countries,
      '#default_value' => (int) $this->campaign->country_id,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select country -'),
      '#description' => $this->t('This updates both the normalized country relation used by public pages and the legacy country text.'),
    ];
    $form['identity']['career'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Career / background'),
      '#default_value' => $this->campaign->career,
      '#maxlength' => 500,
    ];

    $form['public_content'] = ['#type' => 'details', '#title' => $this->t('Public content'), '#open' => TRUE];
    $form['public_content']['description_help'] = [
      '#markup' => '<p><strong>' . $this->t('Public field:') . '</strong> ' . $this->t('Write a respectful reader-facing summary. Do not include contact details, private verification evidence, workflow instructions, or internal volunteer notes.') . '</p>',
    ];
    $form['public_content']['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Public description'),
      '#default_value' => $this->campaign->description,
      '#rows' => 12,
      '#maxlength' => 5000,
    ];

    $form['fundraiser'] = [
      '#type' => 'details',
      '#title' => $this->t('Active fundraiser'),
      '#open' => TRUE,
      '#description' => $this->t('Changing the URL keeps the old fundraiser as an inactive record and creates a new active one. Choosing “No active fundraiser” deactivates the current one; nothing is deleted.'),
    ];
    $form['fundraiser']['platform_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Fundraising platform'),
      '#options' => $platforms,
      '#empty_option' => $this->t('- No active fundraiser -'),
      '#default_value' => $this->fundraiser ? (int) $this->fundraiser->platform_id : '',
    ];
    $form['fundraiser']['fundraiser_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Fundraiser URL'),
      '#maxlength' => 2000,
      '#default_value' => $this->fundraiser->url ?? '',
    ];
    $form['fundraiser']['currency_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Currency code'),
      '#maxlength' => 3,
      '#size' => 6,
      '#placeholder' => 'USD',
      '#default_value' => $this->fundraiser->currency_code ?? '',
    ];
    $form['fundraiser']['goal_amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Goal amount'),
      '#min' => 0,
      '#step' => '0.01',
      '#default_value' => $this->fundraiser->goal_amount ?? '',
    ];
    $form['fundraiser']['donated_amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Amount donated / raised'),
      '#min' => 0,
      '#step' => '0.01',
      '#default_value' => $this->fundraiser->donated_amount ?? '',
    ];

    $this->syncState = $this->loadSyncState();
    if ($this->syncState !== NULL) {
      $form['fundraiser']['auto_sync'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Update raised and goal amounts automatically'),
        '#default_value' => AdminFormat::truthy($this->syncState->auto_sync),
        '#description' => $this->t('The fundraiser sync service refreshes these figures from the platform. Untick to keep manually entered amounts.'),
      ];
      $status = trim((string) ($this->syncState->sync_status ?? ''));
      $form['fundraiser']['sync_state'] = [
        '#type' => 'item',
        '#title' => $this->t('Last sync'),
        '#markup' => $status === ''
          ? $this->t('Not checked yet.')
          : $this->t('@status · checked @checked · last success @success · @failures consecutive failures', [
            '@status' => $status,
            '@checked' => $this->syncDate($this->syncState->last_checked_at ?? NULL),
            '@success' => $this->syncDate($this->syncState->last_success_at ?? NULL),
            '@failures' => (int) ($this->syncState->consecutive_failures ?? 0),
          ]),
      ];
    }

    $form['tags_section'] = ['#type' => 'details', '#title' => $this->t('Tags'), '#open' => FALSE];
    $form['tags_section']['tag_ids'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Campaign tags'),
      '#options' => $tags,
      '#default_value' => array_values(array_intersect($this->tagIds, array_keys($tags))),
    ];

    $form['classification'] = ['#type' => 'details', '#title' => $this->t('Classification'), '#open' => TRUE];
    $form['classification']['featured_by_bfep'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Featured by BFEP'),
      '#default_value' => $this->truthy($this->campaign->featured_by_bfep ?? NULL),
    ];
    $form['classification']['urgent_medical_needs'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Urgent medical needs'),
      '#default_value' => $this->truthy($this->campaign->urgent_medical_needs ?? NULL),
    ];
    $form['classification']['no_feature_response'] = [
      '#type' => 'textfield',
      '#title' => $this->t('No-feature response'),
      '#default_value' => $this->campaign->no_feature_response ?? '',
      '#maxlength' => 500,
    ];
    $form['classification']['vetted_by_trusted_group'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Vetted by trusted group'),
      '#default_value' => $this->campaign->vetted_by_trusted_group,
      '#maxlength' => 500,
    ];
    $form['classification']['featured_self_selected'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Featured self-selected'),
      '#default_value' => $this->campaign->featured_self_selected,
      '#maxlength' => 500,
    ];

    $form['internal'] = ['#type' => 'details', '#title' => $this->t('Internal information'), '#open' => FALSE];
    $form['internal']['internal_notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Internal notes'),
      '#default_value' => $this->campaign->internal_notes,
      '#rows' => 8,
      '#maxlength' => 10000,
      '#description' => $this->t('Internal only. This field is never selected by the public controllers, templates, search results, or sitemap.'),
    ];

    // The version the editor started from; compared on save so a second
    // editor's changes are not silently overwritten.
    $form['loaded_updated_at'] = [
      '#type' => 'hidden',
      '#default_value' => (string) ($this->campaign->updated_at ?? ''),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save campaign'), '#button_type' => 'primary'];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to campaigns'),
      '#url' => Url::fromRoute('bfep.admin_campaigns'),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $current = $this->database->query('SELECT updated_at FROM campaigns WHERE id = :id', [':id' => $this->campaignId])->fetchField();
    if ((string) ($current ?? '') !== (string) $form_state->getValue('loaded_updated_at')) {
      $form_state->setErrorByName('', $this->t('This campaign was changed by someone else after you opened it. <a href=":url">Reload the campaign</a> to see the latest version, then make your changes again.', [
        ':url' => Url::fromRoute('bfep.admin_campaign_edit', ['campaign_id' => $this->campaignId])->toString(),
      ]));
    }
    $fundraiserErrors = AdminFormat::fundraiserErrors(
      (int) $form_state->getValue('platform_id'),
      trim((string) $form_state->getValue('fundraiser_url')),
      strtoupper(trim((string) $form_state->getValue('currency_code'))),
      $form_state->getValue('goal_amount'),
      $form_state->getValue('donated_amount'),
    );
    foreach ($fundraiserErrors as $name => $message) {
      $form_state->setErrorByName($name, $this->t($message));
    }
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
    $fields = [
      'contact_name' => trim((string) $values['contact_name']),
      'country_id' => $countryId,
      'country_raw' => (string) $countryName,
      'description' => trim((string) $values['description']),
      'career' => trim((string) $values['career']),
      'featured_by_bfep' => !empty($values['featured_by_bfep']) ? 'true' : 'false',
      'urgent_medical_needs' => !empty($values['urgent_medical_needs']) ? 'true' : 'false',
      'vetted_by_trusted_group' => trim((string) $values['vetted_by_trusted_group']),
      'featured_self_selected' => trim((string) $values['featured_self_selected']),
      'no_feature_response' => trim((string) $values['no_feature_response']),
      'internal_notes' => trim((string) $values['internal_notes']),
    ];
    $changed = AdminFormat::changedKeys((array) $this->campaign, $fields);
    $fields['updated_at'] = date('c');
    $transaction = $this->database->startTransaction();
    try {
      $this->database->update('campaigns')->fields($fields)->condition('id', $this->campaignId)->execute();
      $changed = [...$changed, ...$this->saveFundraiser($values), ...$this->saveTags($values)];
      $changed = [...$changed, ...$this->saveAutoSync($values)];
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      $this->reportSaveFailure($form_state, $exception, 'campaign ' . $this->campaignId);
      return;
    }
    // Commit before invalidating caches.
    unset($transaction);
    $this->auditLogger->record('updated', 'campaign', $this->campaignId, $changed);

    $this->cacheInvalidator->invalidateCampaign($this->campaignId);
    $this->messenger()->addStatus($this->t('Campaign saved. Public caches for this campaign, listings, countries, and the homepage were invalidated.'));
    $form_state->setRedirect('bfep.admin_campaign_edit', ['campaign_id' => $this->campaignId]);
  }

  /**
   * Applies fundraiser changes and returns the names of changed fields.
   */
  private function saveFundraiser(array $values): array {
    $platformId = (int) ($values['platform_id'] ?? 0);
    $new = [
      'platform_id' => $platformId,
      'url' => trim((string) ($values['fundraiser_url'] ?? '')),
      'currency_code' => strtoupper(trim((string) ($values['currency_code'] ?? ''))),
      'goal_amount' => AdminFormat::isBlank($values['goal_amount'] ?? NULL) ? NULL : (string) $values['goal_amount'],
      'donated_amount' => AdminFormat::isBlank($values['donated_amount'] ?? NULL) ? NULL : (string) $values['donated_amount'],
    ];
    $old = $this->fundraiser ? (array) $this->fundraiser : [];
    if ($platformId < 1) {
      if (!$old) {
        return [];
      }
      $this->database->update('campaign_fundraisers')
        ->fields(['is_active' => 'false'])
        ->condition('campaign_id', $this->campaignId)
        ->condition('is_active', TRUE)
        ->execute();
      return ['fundraiser (deactivated)'];
    }

    $changed = AdminFormat::changedKeys($old, $new);
    if (!$changed) {
      return [];
    }
    $row = $new;
    $row['currency_code'] = $row['currency_code'] === '' ? NULL : $row['currency_code'];
    $sameFundraiser = $old && AdminFormat::urlMatchKey($old['url'] ?? '') === AdminFormat::urlMatchKey($new['url']);
    if ($sameFundraiser) {
      $this->database->update('campaign_fundraisers')
        ->fields($row)
        ->condition('campaign_id', $this->campaignId)
        ->condition('is_active', TRUE)
        ->execute();
      return array_map(static fn(string $key): string => 'fundraiser.' . $key, $changed);
    }
    // A different URL is a different fundraiser: keep the old row as history.
    if ($old) {
      $this->database->update('campaign_fundraisers')
        ->fields(['is_active' => 'false'])
        ->condition('campaign_id', $this->campaignId)
        ->condition('is_active', TRUE)
        ->execute();
    }
    $this->database->insert('campaign_fundraisers')
      ->fields(['campaign_id' => $this->campaignId, 'is_active' => 'true'] + $row)
      ->execute();
    return [$old ? 'fundraiser (replaced)' : 'fundraiser (added)'];
  }

  /**
   * Reads the sync columns of the active fundraiser.
   *
   * Returns NULL when there is no active fundraiser or the columns do not
   * exist (an environment without the sync service), so the form still works.
   */
  private function loadSyncState(): ?object {
    try {
      $row = $this->database->query('
        SELECT auto_sync, sync_status, last_checked_at, last_success_at, consecutive_failures
        FROM campaign_fundraisers
        WHERE campaign_id = :id AND is_active
        LIMIT 1
      ', [':id' => $this->campaignId])->fetchObject();
      return $row ?: NULL;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  private function syncDate(mixed $value): string {
    $timestamp = !AdminFormat::isBlank($value) ? strtotime((string) $value) : FALSE;
    return $timestamp ? date('j M Y H:i', $timestamp) : (string) $this->t('never');
  }

  /**
   * Saves the auto-sync flag on the (possibly new) active fundraiser.
   */
  private function saveAutoSync(array $values): array {
    if ($this->syncState === NULL || !array_key_exists('auto_sync', $values) || (int) ($values['platform_id'] ?? 0) < 1) {
      return [];
    }
    $wanted = !empty($values['auto_sync']);
    $this->database->update('campaign_fundraisers')
      ->fields(['auto_sync' => $wanted ? 'true' : 'false'])
      ->condition('campaign_id', $this->campaignId)
      ->condition('is_active', TRUE)
      ->execute();
    return AdminFormat::truthy($this->syncState->auto_sync) === $wanted ? [] : ['fundraiser.auto_sync'];
  }

  /**
   * Syncs campaign_tags with the selection and returns changed field names.
   */
  private function saveTags(array $values): array {
    $selected = array_map('intval', array_values(array_filter($values['tag_ids'] ?? [])));
    $added = array_diff($selected, $this->tagIds);
    $removed = array_diff($this->tagIds, $selected);
    if ($removed) {
      $this->database->delete('campaign_tags')
        ->condition('campaign_id', $this->campaignId)
        ->condition('tag_id', array_values($removed), 'IN')
        ->execute();
    }
    foreach ($added as $tagId) {
      $this->database->query('
        INSERT INTO campaign_tags (campaign_id, tag_id)
        VALUES (:campaign_id, :tag_id)
        ON CONFLICT DO NOTHING
      ', [':campaign_id' => $this->campaignId, ':tag_id' => $tagId]);
    }
    return ($added || $removed) ? ['tags'] : [];
  }

  private function truthy(mixed $value): bool {
    return $value === TRUE || $value === 1 || $value === '1'
      || $value === 't' || $value === 'true';
  }

}
