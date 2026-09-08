<?php

namespace Drupal\bfep\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ChangeRequestReviewForm extends FormBase {

  protected int $requestId = 0;
  protected object $changeRequest;

  public function __construct(
    protected Connection $database,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('bfep.database'));
  }

  public function getFormId(): string {
    return 'bfep_change_request_review_form';
  }

  protected function bfdb(): Connection {
    return $this->database;
  }

  protected function item(string $title, $value): array {
    $text = trim((string) ($value ?? ''));
    return ['#type' => 'item', '#title' => $title, '#markup' => $text === '' ? '<em>Not provided</em>' : nl2br(Html::escape($text))];
  }

  public function buildForm(array $form, FormStateInterface $form_state, $request_id = NULL): array {
    $this->requestId = (int) $request_id;
    $this->changeRequest = $this->bfdb()->select('info_change_requests', 'r')->fields('r')->condition('id', $this->requestId)->execute()->fetchObject();
    if (!$this->changeRequest) {
      throw new NotFoundHttpException();
    }

    $form['#attached']['library'][] = 'bfep/admin';
    $form['request'] = ['#type' => 'details', '#title' => $this->t('Requested change'), '#open' => TRUE];
    $form['request']['submitter'] = $this->item('Submitter', trim((string) ($this->changeRequest->submitter_name ?? '')) . (!empty($this->changeRequest->submitter_email) ? ' <' . $this->changeRequest->submitter_email . '>' : ''));
    $form['request']['type'] = $this->item('Submitter type', $this->changeRequest->submitter_type ?? NULL);
    $form['request']['line'] = $this->item('Family / line reference', $this->changeRequest->family_line_number_raw ?? NULL);
    $form['request']['fields'] = $this->item('Fields to change', $this->changeRequest->fields_to_change ?? NULL);
    $form['request']['description'] = $this->item('Change description', $this->changeRequest->change_description ?? NULL);
    $form['request']['new_email'] = $this->item('New email', $this->changeRequest->new_email ?? NULL);
    $form['request']['new_social'] = $this->item('New social media', $this->changeRequest->new_social_media ?? NULL);
    $form['request']['existing'] = $this->item('Confirmed existing family', !empty($this->changeRequest->confirmed_existing_family) ? 'Yes' : 'No');

    if (!empty($this->changeRequest->fundraiser_url) && filter_var($this->changeRequest->fundraiser_url, FILTER_VALIDATE_URL)) {
      $form['request']['fundraiser'] = ['#type' => 'link', '#title' => $this->t('Open submitted fundraiser'), '#url' => Url::fromUri($this->changeRequest->fundraiser_url), '#attributes' => ['class' => ['button'], 'target' => '_blank', 'rel' => 'noopener']];
    }
    if (!empty($this->changeRequest->campaign_id)) {
      $form['request']['campaign'] = ['#type' => 'link', '#title' => $this->t('Edit linked campaign'), '#url' => Url::fromRoute('bfep.admin_campaign_edit', ['campaign_id' => $this->changeRequest->campaign_id]), '#attributes' => ['class' => ['button']]];
    }

    $form['workflow'] = ['#type' => 'details', '#title' => $this->t('Workflow'), '#open' => TRUE];
    $form['workflow']['processed'] = ['#type' => 'checkbox', '#title' => $this->t('Mark this request processed'), '#default_value' => !empty($this->changeRequest->processed)];
    if (!empty($this->changeRequest->processed_at)) {
      $form['workflow']['processed_at'] = $this->item('Processed at', $this->changeRequest->processed_at);
    }
    if (!empty($this->changeRequest->processed_by)) {
      $form['workflow']['processed_by'] = $this->item('Processed by', $this->changeRequest->processed_by);
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save workflow'), '#button_type' => 'primary'];
    $form['actions']['cancel'] = ['#type' => 'link', '#title' => $this->t('Back to change requests'), '#url' => Url::fromRoute('bfep.admin_changes'), '#attributes' => ['class' => ['button']]];
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
    $this->messenger()->addStatus($this->t('Change request workflow saved.'));
    $form_state->setRedirect('bfep.admin_change_review', ['request_id' => $this->requestId]);
  }

}
