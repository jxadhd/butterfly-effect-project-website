<?php

declare(strict_types=1);

namespace Drupal\bfep\Form;

use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Public campaign-update, safety, and privacy request form.
 */
final class ChangeRequestForm extends ProtectedExternalFormBase {

  public function getFormId(): string {
    return 'bfep_change_request_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?Request $request = NULL): array {
    $request ??= $this->getRequest();
    if (!$request instanceof Request) {
      throw new \LogicException('The BFEP request form requires an active request.');
    }
    $campaignId = $request->query->getInt('campaign_id');
    $form['request_kind'] = [
      '#type' => 'select', '#title' => $this->t('What kind of request is this?'), '#required' => TRUE,
      '#options' => [
        'campaign_update' => $this->t('Correct or update a campaign'),
        'remove_information' => $this->t('Remove public information'),
        'privacy_access' => $this->t('Access my personal information'),
        'privacy_correction' => $this->t('Correct my personal information'),
        'safety_concern' => $this->t('Report a safety concern'),
        'other' => $this->t('Other'),
      ],
    ];
    $form['submitter_email'] = [
      '#type' => 'email', '#title' => $this->t('Your email'), '#required' => TRUE,
      '#maxlength' => 254, '#autocomplete' => 'email',
    ];
    $form['submitter_name'] = [
      '#type' => 'textfield', '#title' => $this->t('Your name'), '#maxlength' => 255, '#autocomplete' => 'name',
    ];
    $form['submitter_type'] = [
      '#type' => 'select', '#title' => $this->t('Your relationship to the record'), '#required' => TRUE,
      '#options' => [
        'family_member' => $this->t('Family member'),
        'family_friend' => $this->t('Family friend'),
        'campaign_organiser' => $this->t('Campaign organiser'),
        'observer' => $this->t('Observer'),
        'other' => $this->t('Other'),
      ],
    ];
    $form['campaign_id'] = [
      '#type' => 'number', '#title' => $this->t('Directory campaign ID, if known'),
      '#default_value' => $campaignId > 0 ? $campaignId : NULL, '#min' => 1, '#step' => 1,
    ];
    $form['family_line_number_raw'] = [
      '#type' => 'textfield', '#title' => $this->t('Family/campaign line number, if known'), '#maxlength' => 100,
    ];
    $form['fundraiser_url'] = [
      '#type' => 'url', '#title' => $this->t('Fundraiser URL, if relevant'), '#maxlength' => 2048,
    ];
    $form['fields_to_change'] = [
      '#type' => 'textfield', '#title' => $this->t('What information or issue does this concern?'),
      '#description' => $this->t('For example: fundraiser link, country, description, name, removal, privacy access, or safety.'),
      '#required' => TRUE, '#maxlength' => 450,
    ];
    $form['change_description'] = [
      '#type' => 'textarea', '#title' => $this->t('Describe the request'), '#required' => TRUE,
      '#maxlength' => 5000, '#rows' => 8,
      '#description' => $this->t('Include enough detail to identify and assess the request, but do not send passwords, payment details, or identity documents.'),
    ];
    $form['confirmed_existing_family'] = [
      '#type' => 'checkbox', '#title' => $this->t('This request concerns a family/campaign already listed.'),
    ];
    $form['new_email'] = [
      '#type' => 'email', '#title' => $this->t('Corrected contact email, if relevant'), '#maxlength' => 254,
    ];
    $form['new_social_media'] = [
      '#type' => 'textarea', '#title' => $this->t('Corrected public social-media details, if relevant'),
      '#maxlength' => 2000, '#rows' => 4,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit', '#value' => $this->t('Submit request'), '#button_type' => 'primary',
    ];
    $this->prepareForm($form);
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $campaignId = (int) $form_state->getValue('campaign_id');
    if ($campaignId > 0 && !(int) $this->database->query(
      'SELECT CASE WHEN EXISTS (SELECT 1 FROM campaigns WHERE id = :id AND deleted_at IS NULL) THEN 1 ELSE 0 END',
      [':id' => $campaignId],
    )->fetchField()) {
      $form_state->setErrorByName('campaign_id', $this->t('That public campaign ID was not found.'));
    }
    $url = $this->clean($form_state->getValue('fundraiser_url'));
    if ($url !== '' && !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], TRUE)) {
      $form_state->setErrorByName('fundraiser_url', $this->t('Enter a valid public http:// or https:// URL.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $kindLabels = [
      'campaign_update' => 'Campaign update', 'remove_information' => 'Remove information',
      'privacy_access' => 'Privacy access', 'privacy_correction' => 'Privacy correction',
      'safety_concern' => 'Safety concern', 'other' => 'Other',
    ];
    $fields = '[' . ($kindLabels[$values['request_kind']] ?? 'Other') . '] ' . $this->clean($values['fields_to_change']);
    $campaignId = (int) ($values['campaign_id'] ?? 0);
    $this->database->insert('info_change_requests')->fields([
      'submitted_at' => date('c'),
      'submitter_email' => $this->clean($values['submitter_email']),
      'submitter_type' => $values['submitter_type'],
      'submitter_name' => $this->clean($values['submitter_name']),
      'family_line_number_raw' => $this->clean($values['family_line_number_raw']),
      'campaign_id' => $campaignId > 0 ? $campaignId : NULL,
      'fundraiser_url' => $this->clean($values['fundraiser_url']),
      'fields_to_change' => $fields,
      'change_description' => $this->clean($values['change_description']),
      'confirmed_existing_family' => (bool) $values['confirmed_existing_family'],
      'new_email' => $this->clean($values['new_email']),
      'new_social_media' => $this->clean($values['new_social_media']),
      'processed' => FALSE,
    ])->execute();
    $this->registerSubmission();
    $this->messenger()->addStatus($this->t('Thank you. Your request has been submitted for review.'));
    $form_state->setRedirect('bfep.change_request');
  }

}
