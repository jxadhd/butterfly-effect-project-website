<?php

declare(strict_types=1);

namespace Drupal\bfep\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\bfep\Admin\AdminFormat;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Staff workflow for one information, privacy, or safety request.
 */
final class ChangeRequestReviewForm extends AdminRecordFormBase {

  protected int $requestId = 0;
  protected object $changeRequest;

  public function getFormId(): string {
    return 'bfep_change_request_review_form';
  }

  protected function nextPendingId(int $currentId): ?int {
    $id = $this->bfdb()->query("
      SELECT id FROM info_change_requests
      WHERE processed = false AND id <> :id
      ORDER BY created_at ASC NULLS LAST, id ASC
      LIMIT 1
    ", [':id' => $currentId])->fetchField();
    return $id === FALSE ? NULL : (int) $id;
  }

  protected function reviewRoute(int $id): array {
    return ['bfep.admin_change_review', ['request_id' => $id]];
  }

  public function buildForm(array $form, FormStateInterface $form_state, $request_id = NULL): array {
    $this->requestId = (int) $request_id;
    $this->changeRequest = $this->bfdb()->select('info_change_requests', 'r')->fields('r')->condition('id', $this->requestId)->execute()->fetchObject();
    if (!$this->changeRequest) {
      throw new NotFoundHttpException();
    }

    $form['#attached']['library'][] = 'bfep/admin';
    $form['request'] = ['#type' => 'details', '#title' => $this->t('Requested change'), '#open' => TRUE];
    $form['request']['created'] = $this->dateItem('Submitted', $this->changeRequest->submitted_at ?? $this->changeRequest->created_at ?? NULL);
    $form['request']['submitter'] = $this->item('Submitter', trim((string) ($this->changeRequest->submitter_name ?? '')) . (!empty($this->changeRequest->submitter_email) ? ' <' . $this->changeRequest->submitter_email . '>' : ''));
    $form['request']['type'] = $this->item('Submitter type', $this->changeRequest->submitter_type ?? NULL);
    $form['request']['line'] = $this->item('Family / line reference', $this->changeRequest->family_line_number_raw ?? NULL);
    $form['request']['fields'] = $this->item('Fields to change', $this->changeRequest->fields_to_change ?? NULL);
    $form['request']['description'] = $this->item('Change description', $this->changeRequest->change_description ?? NULL);
    $form['request']['new_email'] = $this->item('New email', $this->changeRequest->new_email ?? NULL);
    $form['request']['new_social'] = $this->item('New social media', $this->changeRequest->new_social_media ?? NULL);
    $form['request']['existing'] = $this->item('Confirmed existing family', !empty($this->changeRequest->confirmed_existing_family) ? 'Yes' : 'No');

    $form['request']['fundraiser_url'] = $this->item('Submitted fundraiser URL', $this->changeRequest->fundraiser_url ?? NULL);
    if ($link = $this->externalLink($this->changeRequest->fundraiser_url ?? NULL, (string) $this->t('Open submitted fundraiser'))) {
      $form['request']['fundraiser'] = $link;
    }
    if (!empty($this->changeRequest->campaign_id)) {
      $form['request']['campaign'] = ['#type' => 'link', '#title' => $this->t('Edit linked campaign'), '#url' => Url::fromRoute('bfep.admin_campaign_edit', ['campaign_id' => $this->changeRequest->campaign_id]), '#attributes' => ['class' => ['button']]];
    }

    $form['workflow'] = ['#type' => 'details', '#title' => $this->t('Workflow'), '#open' => TRUE];
    $form['workflow']['processed'] = ['#type' => 'checkbox', '#title' => $this->t('Mark this request processed'), '#default_value' => !empty($this->changeRequest->processed)];
    if (!empty($this->changeRequest->processed_at)) {
      $form['workflow']['processed_at'] = $this->dateItem('Processed at', $this->changeRequest->processed_at);
    }
    if (!empty($this->changeRequest->processed_by)) {
      $form['workflow']['processed_by'] = $this->item('Processed by', $this->changeRequest->processed_by);
    }

    $this->addActions($form, (string) $this->t('Save workflow'), (string) $this->t('Back to change requests'), 'bfep.admin_changes');
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $processed = (bool) $form_state->getValue('processed');
    $fields = ['processed' => $processed];
    if ($processed && empty($this->changeRequest->processed_at)) {
      $fields['processed_at'] = date('c');
    }
    elseif (!$processed) {
      $fields['processed_at'] = NULL;
    }

    $this->bfdb()->update('info_change_requests')->fields($fields)->condition('id', $this->requestId)->execute();
    $this->auditLogger->record('updated', 'change request', $this->requestId, AdminFormat::changedKeys((array) $this->changeRequest, $fields));
    $this->messenger()->addStatus($this->t('Change request workflow saved.'));
    $this->redirectAfterSave($form_state, $this->requestId, 'bfep.admin_changes');
  }

}
