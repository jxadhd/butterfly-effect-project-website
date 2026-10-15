<?php

declare(strict_types=1);

namespace Drupal\bfep\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\bfep\Admin\AdminFormat;
use Drupal\bfep\Admin\BfdbError;
use Drupal\bfep\Service\AuditLogger;
use Drupal\bfep\Service\BfepCacheInvalidator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Shared plumbing for removing a campaign from the site and restoring it.
 *
 * Both forms only change campaigns.deleted_at (plus updated_at and a dated
 * line in internal_notes), so a removal can always be undone.
 */
abstract class CampaignVisibilityFormBase extends ConfirmFormBase {

  protected int $campaignId = 0;
  protected object $campaign;

  /**
   * The campaign's active fundraiser URLs, newest first.
   *
   * @var string[]
   */
  protected array $fundraiserUrls = [];

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

  /**
   * Loads the campaign, or responds 404 when it is not in the expected state.
   */
  protected function loadCampaign(mixed $campaignId, bool $removed): void {
    $this->campaignId = (int) $campaignId;
    $this->campaign = $this->database->query('
      SELECT id, line_number, contact_name, country_raw, featured_by_bfep, urgent_medical_needs, updated_at, deleted_at
      FROM campaigns
      WHERE id = :id AND deleted_at IS ' . ($removed ? 'NOT NULL' : 'NULL'), [':id' => $this->campaignId])->fetchObject()
      ?: throw new NotFoundHttpException();
    $this->fundraiserUrls = array_values(array_filter(array_map('trim', $this->database->query(
      'SELECT url FROM campaign_fundraisers WHERE campaign_id = :id AND is_active ORDER BY id DESC',
      [':id' => $this->campaignId],
    )->fetchCol())));
  }

  /**
   * The campaign's name and line number, such as "Asmaa (line 12)".
   */
  protected function label(object $row): string {
    $label = trim((string) ($row->contact_name ?? '')) ?: 'Campaign #' . $row->id;
    return empty($row->line_number) ? $label : $label . ' (line ' . $row->line_number . ')';
  }

  /**
   * Other live campaigns with an active fundraiser this campaign also has.
   *
   * @return object[]
   *   Rows with id, line_number and contact_name.
   */
  protected function campaignsSharingFundraiser(): array {
    $keys = array_values(array_unique(array_filter(array_map([AdminFormat::class, 'urlMatchKey'], $this->fundraiserUrls))));
    if (!$keys) {
      return [];
    }
    return $this->database->query('
      SELECT DISTINCT c.id, c.line_number, c.contact_name
      FROM campaign_fundraisers cf
      INNER JOIN campaigns c ON c.id = cf.campaign_id AND c.deleted_at IS NULL
      WHERE cf.is_active AND c.id <> :id AND ' . AdminFormat::urlKeySql('cf.url') . ' IN (:keys[])
      ORDER BY c.id
      LIMIT 10
    ', [':id' => $this->campaignId, ':keys[]' => $keys])->fetchAll();
  }

  /**
   * A list of links to the edit pages of $rows.
   */
  protected function campaignLinks(array $rows): array {
    $items = [];
    foreach ($rows as $row) {
      $items[] = [
        '#type' => 'link',
        '#title' => $this->label($row),
        '#url' => Url::fromRoute('bfep.admin_campaign_edit', ['campaign_id' => (int) $row->id]),
      ];
    }
    return ['#theme' => 'item_list', '#items' => $items];
  }

  /**
   * The read-only summary shown at the top of both forms.
   */
  protected function summary(): array {
    $rows = [
      [$this->t('Campaign'), $this->label($this->campaign)],
      [$this->t('Country'), (string) ($this->campaign->country_raw ?: '—')],
      [
        count($this->fundraiserUrls) > 1 ? $this->t('Fundraisers') : $this->t('Fundraiser'),
        $this->fundraiserUrls ? ['data' => ['#theme' => 'item_list', '#items' => $this->fundraiserUrls]] : $this->t('None'),
      ],
    ];
    return [
      '#type' => 'table',
      '#rows' => $rows,
      '#attributes' => ['class' => ['bfep-admin-table']],
      '#weight' => -50,
    ];
  }

  /**
   * Hidden field holding the version the editor started from.
   */
  protected function versionField(): array {
    return ['#type' => 'hidden', '#default_value' => (string) ($this->campaign->updated_at ?? '')];
  }

  /**
   * Refuses the change when someone else saved the campaign meanwhile.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $current = $this->database->query('SELECT updated_at FROM campaigns WHERE id = :id', [':id' => $this->campaignId])->fetchField();
    if ((string) ($current ?? '') !== (string) $form_state->getValue('loaded_updated_at')) {
      $form_state->setErrorByName('', $this->t('This campaign was changed by someone else after you opened this page. Reload it and check the campaign before trying again.'));
    }
  }

  /**
   * Sets or clears deleted_at and adds $note to the internal notes.
   *
   * @return bool
   *   TRUE when the campaign changed. On failure the reason is shown to the
   *   user and the form is rebuilt.
   */
  protected function setRemoved(FormStateInterface $form_state, bool $remove, string $note): bool {
    try {
      $query = $this->database->update('campaigns')
        ->expression('deleted_at', $remove ? 'NOW()' : 'NULL')
        ->expression('updated_at', 'NOW()')
        ->expression('internal_notes', "CONCAT_WS(:separator, NULLIF(TRIM(internal_notes), ''), CAST(:note AS TEXT))", [
          ':separator' => "\n\n",
          ':note' => $note,
        ])
        ->condition('id', $this->campaignId);
      if ($remove) {
        $query->isNull('deleted_at');
      }
      else {
        $query->isNotNull('deleted_at');
      }
      $changed = $query->execute();
    }
    catch (\Throwable $exception) {
      $explanation = BfdbError::explain($exception);
      $this->logger('bfep')->error('Could not @action campaign @id: @detail', [
        '@action' => $remove ? 'remove' : 'restore',
        '@id' => $this->campaignId,
        '@detail' => $explanation['detail'] ?? get_class($exception),
      ]);
      $this->messenger()->addError($explanation !== NULL
        ? $explanation['message'] . ' ' . $explanation['action']
        : $this->t('The campaign was not changed because of an unexpected error. Check Reports > Recent log messages.'));
      $form_state->setRebuild();
      return FALSE;
    }
    if ((int) $changed !== 1) {
      $this->messenger()->addWarning($remove
        ? $this->t('This campaign had already been removed.')
        : $this->t('This campaign had already been restored.'));
      return FALSE;
    }
    $this->cacheInvalidator->invalidateCampaign($this->campaignId);
    return TRUE;
  }

}
