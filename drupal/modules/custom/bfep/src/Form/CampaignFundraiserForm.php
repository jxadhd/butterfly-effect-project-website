<?php

declare(strict_types=1);

namespace Drupal\bfep\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\bfep\Admin\AdminFormat;
use Drupal\bfep\Admin\SyncPlatforms;
use Drupal\bfep\Service\AuditLogger;
use Drupal\bfep\Service\BfepCacheInvalidator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Adds a fundraiser to a campaign, or edits one of its fundraisers.
 *
 * A campaign (a campaigns row) can raise money on several fundraisers
 * (campaign_fundraisers rows). When a campaign moves to a new
 * platform, staff add the new fundraiser here and end the old one, which
 * stays in the campaign's history instead of having its link overwritten.
 */
final class CampaignFundraiserForm extends FormBase {

  use ReturnToListTrait;
  use SaveFailureTrait;

  protected int $campaignId = 0;
  protected object $campaign;

  /**
   * The fundraiser being edited, or NULL when adding one.
   */
  protected ?object $fundraiser = NULL;

  /**
   * The campaign's other active fundraisers, keyed by ID.
   *
   * @var object[]
   */
  protected array $otherActive = [];

  /**
   * Whether bfdb has the sync service columns.
   */
  protected bool $hasSyncColumns = FALSE;

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
    return 'bfep_campaign_fundraiser_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $campaign_id = NULL, $fundraiser_id = NULL): array {
    $this->load((int) $campaign_id, $fundraiser_id === NULL ? NULL : (int) $fundraiser_id);
    $editing = $this->fundraiser !== NULL;

    $platforms = [];
    foreach ($this->database->query('SELECT id, name FROM fundraising_platforms ORDER BY name')->fetchAll() as $row) {
      $platforms[(int) $row->id] = (string) $row->name;
    }

    $form['#attached']['library'][] = 'bfep/admin';
    $name = trim((string) ($this->campaign->contact_name ?? '')) ?: 'Campaign #' . $this->campaignId;
    $form['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['bfep-admin-edit-summary']],
      'text' => [
        '#markup' => '<strong>' . Html::escape($name) . '</strong>'
        . (!empty($this->campaign->line_number) ? ' · Line ' . Html::escape((string) $this->campaign->line_number) : '')
        . ($editing ? ' · ' . $this->t('Fundraiser #@id', ['@id' => (int) $this->fundraiser->id]) : ''),
      ],
    ];
    if (!$editing) {
      $form['intro'] = [
        '#markup' => '<p>' . $this->t('Add a fundraiser when the campaign starts raising money on another page, for example after moving from GoFundMe to Chuffed. The old fundraiser is kept in the history instead of having its link replaced.') . '</p>',
      ];
    }

    $form['platform_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Fundraising platform'),
      '#options' => $platforms,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select platform -'),
      '#default_value' => $editing ? (int) $this->fundraiser->platform_id : '',
    ];
    $form['fundraiser_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Fundraiser URL'),
      '#required' => TRUE,
      '#maxlength' => 2000,
      '#default_value' => $this->fundraiser->url ?? '',
      '#description' => $editing
        ? $this->t('Change the link only to correct it. If the campaign moved to a different fundraiser, <a href=":add">add the new fundraiser</a> instead, so this one stays in the history.', [
          ':add' => Url::fromRoute('bfep.admin_fundraiser_add', ['campaign_id' => $this->campaignId], ['query' => $this->backQuery()])->toString(),
        ]) . ' ' . SyncPlatforms::help()
        : SyncPlatforms::help(),
    ];
    $form['allow_duplicate_url'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Save anyway if another campaign already uses this fundraiser URL'),
      '#description' => $this->t('Leave unchecked to be warned about likely duplicates.'),
    ];
    $form['currency_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Currency code'),
      '#maxlength' => 3,
      '#size' => 6,
      '#placeholder' => 'USD',
      '#default_value' => trim((string) ($this->fundraiser->currency_code ?? '')),
      '#description' => $this->t('Three-letter currency code, for example USD, EUR, AUD or NZD. The public page adds up fundraisers only when they use the same currency.'),
    ];
    $form['goal_amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Goal amount'),
      '#min' => 0,
      '#step' => '0.01',
      '#default_value' => $this->fundraiser->goal_amount ?? '',
    ];
    $form['donated_amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Amount donated / raised'),
      '#min' => 0,
      '#step' => '0.01',
      '#default_value' => $this->fundraiser->donated_amount ?? '',
    ];

    if ($editing) {
      $form['is_active'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Active'),
        '#default_value' => AdminFormat::truthy($this->fundraiser->is_active),
        '#description' => $this->t('Active fundraisers are shown on the public campaign page and updated by the sync. Untick when the fundraiser has closed or the campaign has moved to another one; it stays in the history and can be made active again.'),
      ];
    }

    if ($this->hasSyncColumns) {
      $this->buildSync($form);
    }

    if (!$editing && $this->otherActive) {
      $options = [];
      foreach ($this->otherActive as $id => $row) {
        $options[$id] = $this->fundraiserLabel($row);
      }
      $form['end_ids'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('End these fundraisers'),
        '#options' => $options,
        '#default_value' => array_keys($options),
        '#description' => $this->t('Ticked fundraisers are marked inactive and kept in the history. Untick one to keep it running alongside the new fundraiser.'),
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $editing ? $this->t('Save fundraiser') : $this->t('Add fundraiser'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to campaign'),
      '#url' => $this->campaignUrl(),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  /**
   * Adds the automatic update checkbox and the last sync result.
   */
  private function buildSync(array &$form): void {
    $editing = $this->fundraiser !== NULL;
    if ($editing && !SyncPlatforms::supports($this->fundraiser->url ?? NULL)) {
      $form['sync_unavailable'] = [
        '#type' => 'item',
        '#title' => $this->t('Automatic updates'),
        '#markup' => $this->t('Not available for this platform, so keep the amounts up to date by hand.'),
      ];
      return;
    }
    $status = $editing ? trim((string) ($this->fundraiser->sync_status ?? '')) : '';
    $autoSync = !$editing || AdminFormat::truthy($this->fundraiser->auto_sync ?? NULL);
    $turnedOff = $status === 'not_found' && !$autoSync;
    $form['auto_sync'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Update raised and goal amounts automatically'),
      '#default_value' => $autoSync,
      '#description' => $turnedOff
        ? $this->t('Turned off by the sync because the fundraiser page was not found 3 nights in a row. Fix the URL, or tick this to try the page again.')
        : $this->t('The fundraiser sync service refreshes these figures from the platform each night, on the platforms listed above. Untick to keep manually entered amounts.'),
    ];
    if ($editing) {
      $form['sync_state'] = [
        '#type' => 'item',
        '#title' => $this->t('Last sync'),
        '#markup' => $status === ''
          ? $this->t('Not checked yet.')
          : $this->t('@status · checked @checked · last success @success · @failures consecutive failures', [
            '@status' => $status,
            '@checked' => $this->syncDate($this->fundraiser->last_checked_at ?? NULL),
            '@success' => $this->syncDate($this->fundraiser->last_success_at ?? NULL),
            '@failures' => (int) ($this->fundraiser->consecutive_failures ?? 0),
          ]),
      ];
    }
  }

  /**
   * Loads the campaign, the fundraiser being edited and the other active ones.
   */
  private function load(int $campaignId, ?int $fundraiserId): void {
    $this->campaignId = $campaignId;
    $this->campaign = $this->database->query(
      'SELECT id, line_number, contact_name FROM campaigns WHERE id = :id AND deleted_at IS NULL',
      [':id' => $campaignId],
    )->fetchObject() ?: throw new NotFoundHttpException();

    $rows = self::fundraiserRows($this->database, $campaignId, $this->hasSyncColumns);
    if ($fundraiserId !== NULL) {
      $this->fundraiser = $rows[$fundraiserId] ?? throw new NotFoundHttpException();
    }
    $this->otherActive = array_filter(
      $rows,
      fn(object $row): bool => AdminFormat::truthy($row->is_active) && (int) $row->id !== $fundraiserId,
    );
  }

  /**
   * All fundraisers of a campaign, active first, then newest first.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The bfdb connection.
   * @param int $campaignId
   *   The campaign.
   * @param bool|null $hasSyncColumns
   *   Set to whether the sync service columns were read.
   *
   * @return object[]
   *   Rows keyed by fundraiser ID, with platform (the platform name), and
   *   the sync columns when bfdb has them.
   */
  public static function fundraiserRows(Connection $database, int $campaignId, ?bool &$hasSyncColumns = NULL): array {
    $sql = '
      SELECT cf.id, cf.platform_id, fp.name AS platform, cf.url, cf.currency_code,
        cf.goal_amount, cf.donated_amount, cf.is_active%s
      FROM campaign_fundraisers cf
      LEFT JOIN fundraising_platforms fp ON fp.id = cf.platform_id
      WHERE cf.campaign_id = :id
      ORDER BY cf.is_active DESC, cf.id DESC';
    try {
      $rows = $database->query(
        sprintf($sql, ', cf.auto_sync, cf.sync_status, cf.last_checked_at, cf.last_success_at, cf.consecutive_failures'),
        [':id' => $campaignId],
      )->fetchAllAssoc('id');
      $hasSyncColumns = TRUE;
    }
    catch (\Throwable) {
      // An environment without the sync service.
      $rows = $database->query(sprintf($sql, ''), [':id' => $campaignId])->fetchAllAssoc('id');
      $hasSyncColumns = FALSE;
    }
    return $rows;
  }

  /**
   * A one-line description of a fundraiser, such as "GoFundMe: https://…".
   */
  private function fundraiserLabel(object $row): string {
    return trim((string) ($row->platform ?? '') ?: 'Fundraiser #' . $row->id) . ': ' . trim((string) ($row->url ?? ''));
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $url = trim((string) $form_state->getValue('fundraiser_url'));
    $errors = AdminFormat::fundraiserErrors(
      (int) $form_state->getValue('platform_id'),
      $url,
      strtoupper(trim((string) $form_state->getValue('currency_code'))),
      $form_state->getValue('goal_amount'),
      $form_state->getValue('donated_amount'),
    );
    foreach ($errors as $name => $message) {
      $form_state->setErrorByName($name, $this->t($message));
    }
    $platformId = (int) $form_state->getValue('platform_id');
    if ($platformId > 0 && !(int) $this->database->query('SELECT CASE WHEN EXISTS (SELECT 1 FROM fundraising_platforms WHERE id = :id) THEN 1 ELSE 0 END', [':id' => $platformId])->fetchField()) {
      $form_state->setErrorByName('platform_id', $this->t('Select a valid platform.'));
    }
    if ($url === '' || isset($errors['fundraiser_url'])) {
      return;
    }

    // An inactive fundraiser may share a link with anything: it is history.
    if ($this->fundraiser !== NULL && !$form_state->getValue('is_active')) {
      return;
    }
    $key = AdminFormat::urlMatchKey($url);
    $ending = $this->endIds($form_state);
    foreach ($this->otherActive as $id => $row) {
      if (!in_array($id, $ending, TRUE) && AdminFormat::urlMatchKey($row->url ?? '') === $key) {
        $form_state->setErrorByName('fundraiser_url', $this->t('This campaign already has an active fundraiser with this link (fundraiser #@id).', ['@id' => $id]));
        return;
      }
    }
    $unchanged = $this->fundraiser !== NULL && AdminFormat::urlMatchKey($this->fundraiser->url ?? '') === $key;
    if (!$unchanged && !$form_state->getValue('allow_duplicate_url')) {
      $links = [];
      foreach ($this->otherCampaignsUsing($key) as $row) {
        $label = trim((string) ($row->contact_name ?: 'Campaign #' . $row->id)) . (!empty($row->line_number) ? ' (line ' . $row->line_number . ')' : '');
        $links[] = '<a href="' . Url::fromRoute('bfep.admin_campaign_edit', ['campaign_id' => (int) $row->id])->toString() . '">' . Html::escape($label) . '</a>';
      }
      if ($links) {
        $form_state->setErrorByName('fundraiser_url', $this->t('This fundraiser URL is already used by: @campaigns. Check that this is the right campaign, or tick “Save anyway”.', [
          '@campaigns' => Markup::create(implode(', ', $links)),
        ]));
      }
    }
  }

  /**
   * Other live campaigns with an active fundraiser matching the URL key.
   */
  private function otherCampaignsUsing(string $key): array {
    if ($key === '') {
      return [];
    }
    return $this->database->query('
      SELECT DISTINCT c.id, c.line_number, c.contact_name
      FROM campaign_fundraisers cf
      INNER JOIN campaigns c ON c.id = cf.campaign_id AND c.deleted_at IS NULL
      WHERE cf.is_active AND c.id <> :id AND ' . AdminFormat::urlKeySql('cf.url') . ' = :key
      ORDER BY c.id
      LIMIT 10
    ', [':id' => $this->campaignId, ':key' => $key])->fetchAll();
  }

  /**
   * IDs of the other active fundraisers the editor chose to end.
   *
   * @return int[]
   *   Fundraiser IDs; only ever IDs of this campaign's active fundraisers.
   */
  private function endIds(FormStateInterface $form_state): array {
    $chosen = array_map('intval', array_values(array_filter((array) ($form_state->getValue('end_ids') ?? []))));
    return array_values(array_intersect(array_keys($this->otherActive), $chosen));
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $currency = strtoupper(trim((string) ($values['currency_code'] ?? '')));
    $row = [
      'platform_id' => (int) $values['platform_id'],
      'url' => trim((string) $values['fundraiser_url']),
      'currency_code' => $currency === '' ? NULL : $currency,
      'goal_amount' => AdminFormat::isBlank($values['goal_amount'] ?? NULL) ? NULL : (string) $values['goal_amount'],
      'donated_amount' => AdminFormat::isBlank($values['donated_amount'] ?? NULL) ? NULL : (string) $values['donated_amount'],
    ];
    if ($this->fundraiser !== NULL) {
      $row['is_active'] = !empty($values['is_active']) ? 'true' : 'false';
    }
    if (array_key_exists('auto_sync', $values)) {
      $wanted = !empty($values['auto_sync']);
      $row['auto_sync'] = $wanted ? 'true' : 'false';
      if ($wanted && $this->fundraiser !== NULL && !AdminFormat::truthy($this->fundraiser->auto_sync ?? NULL)) {
        // Turning sync back on gives a dead link another 3 nights before the
        // sync turns it off again.
        $row['consecutive_failures'] = 0;
      }
    }

    $ending = $this->endIds($form_state);
    $transaction = $this->database->startTransaction();
    try {
      if ($this->fundraiser === NULL) {
        $fields = ['campaign_id' => $this->campaignId, 'is_active' => 'true'] + $row;
        $placeholders = [];
        foreach ($fields as $column => $value) {
          $placeholders[':' . $column] = $value;
        }
        $fundraiserId = (int) $this->database->query(
          'INSERT INTO campaign_fundraisers (' . implode(', ', array_keys($fields)) . ') VALUES (' . implode(', ', array_keys($placeholders)) . ') RETURNING id',
          $placeholders,
        )->fetchField();
        $changed = ['fundraiser ' . $fundraiserId . ' (added)'];
      }
      else {
        $fundraiserId = (int) $this->fundraiser->id;
        $changed = array_map(
          static fn(string $key): string => 'fundraiser ' . $fundraiserId . '.' . $key,
          AdminFormat::changedKeys((array) $this->fundraiser, array_diff_key($row, ['consecutive_failures' => TRUE])),
        );
        $this->database->update('campaign_fundraisers')
          ->fields($row)
          ->condition('id', $fundraiserId)
          ->condition('campaign_id', $this->campaignId)
          ->execute();
      }
      if ($ending) {
        $this->database->update('campaign_fundraisers')
          ->fields(['is_active' => 'false'])
          ->condition('campaign_id', $this->campaignId)
          ->condition('id', $ending, 'IN')
          ->execute();
        foreach ($ending as $id) {
          $changed[] = 'fundraiser ' . $id . ' (ended)';
        }
      }
      if ($changed) {
        $this->database->update('campaigns')
          ->fields(['updated_at' => date('c')])
          ->condition('id', $this->campaignId)
          ->execute();
      }
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      $this->reportSaveFailure($form_state, $exception, 'fundraiser for campaign ' . $this->campaignId);
      return;
    }
    // Commit before invalidating caches.
    unset($transaction);

    if ($changed) {
      $this->auditLogger->record('updated', 'campaign', $this->campaignId, $changed);
      $this->cacheInvalidator->invalidateCampaign($this->campaignId);
    }
    $this->messenger()->addStatus(match (TRUE) {
      $this->fundraiser === NULL && (bool) $ending => $this->t('Fundraiser added, and @count ended. They stay in the campaign history.', ['@count' => count($ending)]),
      $this->fundraiser === NULL => $this->t('Fundraiser added.'),
      (bool) $changed => $this->t('Fundraiser saved.'),
      default => $this->t('No changes to save.'),
    });
    $form_state->setRedirectUrl($this->campaignUrl());
  }

  /**
   * The campaign's Fundraisers table, the list a fundraiser returns to.
   *
   * Passes on ?back= so saving the campaign afterwards still returns to the
   * campaign list view it was opened from.
   */
  private function campaignUrl(): Url {
    return Url::fromRoute('bfep.admin_campaign_edit', ['campaign_id' => $this->campaignId], [
      'fragment' => 'edit-fundraisers',
      'query' => $this->backQuery(),
    ]);
  }

  private function syncDate(mixed $value): string {
    $timestamp = !AdminFormat::isBlank($value) ? strtotime((string) $value) : FALSE;
    return $timestamp ? date('j M Y H:i', $timestamp) : (string) $this->t('never');
  }

}
