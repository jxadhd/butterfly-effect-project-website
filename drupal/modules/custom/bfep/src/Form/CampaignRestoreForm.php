<?php

declare(strict_types=1);

namespace Drupal\bfep\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\bfep\Admin\CampaignRemoval;

/**
 * Puts a removed campaign back on the site.
 */
final class CampaignRestoreForm extends CampaignVisibilityFormBase {

  public function getFormId(): string {
    return 'bfep_campaign_restore_form';
  }

  public function getQuestion(): TranslatableMarkup {
    return $this->t('Restore @campaign to the site?', ['@campaign' => $this->label($this->campaign)]);
  }

  public function getDescription(): TranslatableMarkup {
    return $this->t('The campaign comes back exactly as it was when it was removed, with its fundraiser, tags and notes.');
  }

  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Restore campaign');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('bfep.admin_campaigns_removed');
  }

  public function buildForm(array $form, FormStateInterface $form_state, $campaign_id = NULL): array {
    $this->loadCampaign($campaign_id, TRUE);
    $form['#attached']['library'][] = 'bfep/admin';
    $form['summary'] = $this->summary();

    $reason = CampaignRemoval::lastReason($this->database->query(
      'SELECT internal_notes FROM campaigns WHERE id = :id',
      [':id' => $this->campaignId],
    )->fetchField());
    if ($reason !== '') {
      $form['summary']['#rows'][] = [$this->t('Removed because'), $reason];
    }

    // Restoring brings back whatever made it a duplicate, so say so.
    $warnings = [];
    if (!empty($this->campaign->line_number)) {
      $sameLine = $this->database->query(
        'SELECT id, line_number, contact_name FROM campaigns WHERE line_number = :line AND id <> :id AND deleted_at IS NULL ORDER BY id LIMIT 10',
        [':line' => $this->campaign->line_number, ':id' => $this->campaignId],
      )->fetchAll();
      if ($sameLine) {
        $warnings['line'] = [
          '#type' => 'container',
          'text' => ['#markup' => '<p>' . $this->t('Line @line is now used by:', ['@line' => $this->campaign->line_number]) . '</p>'],
          'list' => $this->campaignLinks($sameLine),
        ];
      }
    }
    $sharing = $this->campaignsSharingFundraiser();
    if ($sharing) {
      $warnings['fundraiser'] = [
        '#type' => 'container',
        'text' => ['#markup' => '<p>' . $this->t('Its fundraiser is listed on:') . '</p>'],
        'list' => $this->campaignLinks($sharing),
      ];
    }
    if ($warnings) {
      $form['warnings'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['bfep-admin-notice']],
        '#weight' => -40,
      ] + $warnings;
    }
    $form['loaded_updated_at'] = $this->versionField();
    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $note = CampaignRemoval::restoredNote($this->currentUser()->getAccountName(), date('Y-m-d'));
    if (!$this->setRemoved($form_state, FALSE, $note)) {
      return;
    }
    $this->auditLogger->record('restored', 'campaign', $this->campaignId, ['deleted_at']);
    $this->messenger()->addStatus($this->t('<a href=":url">@campaign</a> is back on the site.', [
      ':url' => Url::fromRoute('bfep.admin_campaign_edit', ['campaign_id' => $this->campaignId])->toString(),
      '@campaign' => $this->label($this->campaign),
    ]));
    $form_state->setRedirect('bfep.admin_campaigns_removed');
  }

}
