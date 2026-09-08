<?php

declare(strict_types=1);

namespace Drupal\bfep\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * Public volunteer application form.
 */
final class VolunteerForm extends ProtectedExternalFormBase {

  public function getFormId(): string {
    return 'bfep_volunteer_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $options = [];
    foreach ($this->database->select('interest_areas', 'i')->fields('i', ['id', 'name'])->orderBy('id')->execute()->fetchAll() as $row) {
      $options[(int) $row->id] = (string) $row->name;
    }
    $form['full_name'] = [
      '#type' => 'textfield', '#title' => $this->t('Full name'), '#required' => TRUE,
      '#maxlength' => 255, '#autocomplete' => 'name',
    ];
    $form['email'] = [
      '#type' => 'email', '#title' => $this->t('Email'), '#required' => TRUE,
      '#maxlength' => 254, '#autocomplete' => 'email',
    ];
    $form['hours_per_week'] = [
      '#type' => 'textfield', '#title' => $this->t('How many hours per week could you help?'), '#maxlength' => 100,
    ];
    $form['interests'] = [
      '#type' => 'checkboxes', '#title' => $this->t('Which areas interest you?'),
      '#description' => $this->t('Select all that apply.'), '#options' => $options, '#required' => TRUE,
    ];
    $form['skills_experience'] = [
      '#type' => 'textarea', '#title' => $this->t('Skills and experience'), '#required' => TRUE,
      '#maxlength' => 5000, '#rows' => 8,
      '#description' => $this->t('Tell us about relevant skills and how you hope to help. Do not include identity documents.'),
    ];
    $form['vouched_for_by'] = [
      '#type' => 'textfield', '#title' => $this->t('Can anyone vouch for you?'), '#maxlength' => 500,
      '#description' => $this->t('Optional. Name a person, group, or community connection only with their permission.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit', '#value' => $this->t('Submit volunteer application'), '#button_type' => 'primary',
    ];
    $this->prepareForm($form);
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if (!array_filter($form_state->getValue('interests') ?: [])) {
      $form_state->setErrorByName('interests', $this->t('Select at least one area of interest.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $transaction = $this->database->startTransaction();
    try {
      $volunteerId = (int) $this->database->insert('volunteers')->fields([
        'submitted_at' => date('c'),
        'email' => $this->clean($values['email']),
        'full_name' => $this->clean($values['full_name']),
        'hours_per_week' => $this->clean($values['hours_per_week']),
        'skills_experience' => $this->clean($values['skills_experience']),
        'vouched_for_by' => $this->clean($values['vouched_for_by']),
        'contacted' => FALSE,
      ])->execute();
      foreach (array_filter($values['interests'] ?: []) as $interestId) {
        $this->database->insert('volunteer_interests')->fields([
          'volunteer_id' => $volunteerId,
          'interest_area_id' => (int) $interestId,
        ])->execute();
      }
    }
    catch (\Throwable $exception) {
      $transaction->rollBack();
      throw $exception;
    }
    unset($transaction);
    $this->registerSubmission();
    $this->messenger()->addStatus($this->t('Thank you. Your volunteer application has been submitted.'));
    $form_state->setRedirect('bfep.volunteer');
  }

}
