<?php

declare(strict_types=1);

namespace Drupal\bfep\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\bfep\Admin\AdminFormat;
use Drupal\bfep\Admin\CampaignRemoval;

/**
 * Asks for a reason, then removes a campaign from the site.
 *
 * The campaign is hidden, not deleted: it can be restored from the removed
 * campaigns list with its fundraiser, tags and notes intact.
 */
final class CampaignRemoveForm extends CampaignVisibilityFormBase {

  public function getFormId(): string {
    return 'bfep_campaign_remove_form';
  }

  public function getQuestion(): TranslatableMarkup {
    return $this->t('Remove @campaign from the site?', ['@campaign' => $this->label($this->campaign)]);
  }

  public function getDescription(): TranslatableMarkup {
    return $this->t('The campaign disappears from the public site, the campaign lists, data checks and the nightly fundraiser sync. Nothing is deleted: you can bring it back from Removed campaigns.');
  }

  public function getConfirmText(): TranslatableMarkup {
    return $this->t('Remove campaign');
  }

  public function getCancelUrl(): Url {
    return Url::fromRoute('bfep.admin_campaign_edit', ['campaign_id' => $this->campaignId], ['query' => $this->backQuery()]);
  }

  public function buildForm(array $form, FormStateInterface $form_state, $campaign_id = NULL): array {
    $this->loadCampaign($campaign_id, FALSE);
    $form['#attached']['library'][] = 'bfep/admin';
    $form['summary'] = $this->summary();

    $sharing = $this->campaignsSharingFundraiser();
    if ($sharing) {
      $form['sharing'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['bfep-admin-notice']],
        '#weight' => -40,
        'text' => ['#markup' => '<p><strong>' . $this->t('The same fundraiser is listed on:') . '</strong></p>'],
        'list' => $this->campaignLinks($sharing),
        'hint' => ['#markup' => '<p>' . $this->t('If this is a duplicate, check that the campaign you keep has the better name, description and goal before removing this one.') . '</p>'],
      ];
    }

    $warnings = [];
    if (AdminFormat::truthy($this->campaign->featured_by_bfep)) {
      $warnings[] = $this->t('It is featured on the site.');
    }
    if (AdminFormat::truthy($this->campaign->urgent_medical_needs)) {
      $warnings[] = $this->t('It is marked as urgent medical needs.');
    }
    $pending = (int) $this->database->query(
      'SELECT COUNT(*) FROM info_change_requests WHERE campaign_id = :id AND processed = false',
      [':id' => $this->campaignId],
    )->fetchField();
    if ($pending > 0) {
      $warnings[] = $this->formatPlural($pending, 'It has a pending change request.', 'It has @count pending change requests.');
    }
    if ($warnings) {
      $form['warnings'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('Before you remove it'),
        '#items' => $warnings,
        '#weight' => -30,
      ];
    }

    $form['reason'] = [
      '#type' => 'radios',
      '#title' => $this->t('Why is it being removed?'),
      '#options' => CampaignRemoval::REASONS,
      '#default_value' => $sharing ? 'duplicate' : NULL,
      '#required' => TRUE,
    ];
    $form['details'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Details'),
      '#maxlength' => 300,
      '#description' => $this->t('For a duplicate, say which campaign is kept, for example “Duplicate of line 1112”. Saved in the campaign’s internal notes.'),
      '#default_value' => $sharing ? 'Duplicate of ' . (!empty($sharing[0]->line_number) ? 'line ' . $sharing[0]->line_number : 'campaign #' . $sharing[0]->id) : '',
      '#states' => [
        'required' => [':input[name="reason"]' => ['value' => 'other']],
      ],
    ];
    $form['loaded_updated_at'] = $this->versionField();

    $form = parent::buildForm($form, $form_state);
    $form['actions']['submit']['#button_type'] = 'danger';
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if ($form_state->getValue('reason') === 'other' && trim((string) $form_state->getValue('details')) === '') {
      $form_state->setErrorByName('details', $this->t('Say why the campaign is being removed.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $reason = (string) $form_state->getValue('reason');
    $note = CampaignRemoval::removedNote(
      $reason,
      (string) $form_state->getValue('details'),
      $this->currentUser()->getAccountName(),
      date('Y-m-d'),
    );
    if (!$this->setRemoved($form_state, TRUE, $note)) {
      return;
    }
    $this->auditLogger->record('removed', 'campaign', $this->campaignId, ['deleted_at', 'reason: ' . $reason]);
    $this->messenger()->addStatus($this->t('@campaign was removed from the site. <a href=":restore">Undo</a>', [
      '@campaign' => $this->label($this->campaign),
      ':restore' => Url::fromRoute('bfep.admin_campaign_restore', ['campaign_id' => $this->campaignId])->toString(),
    ]));
    $form_state->setRedirectUrl($this->listUrl('bfep.admin_campaigns'));
  }

}
