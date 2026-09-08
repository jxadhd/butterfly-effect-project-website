<?php

namespace Drupal\bfep\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class VolunteerReviewForm extends FormBase {

  protected int $volunteerId = 0;
  protected object $volunteer;

  public function __construct(
    protected Connection $database,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('bfep.database'));
  }

  public function getFormId(): string {
    return 'bfep_volunteer_review_form';
  }

  protected function bfdb(): Connection {
    return $this->database;
  }

  protected function item(string $title, $value): array {
    $text = trim((string) ($value ?? ''));
    return ['#type' => 'item', '#title' => $title, '#markup' => $text === '' ? '<em>Not provided</em>' : nl2br(Html::escape($text))];
  }

  public function buildForm(array $form, FormStateInterface $form_state, $volunteer_id = NULL): array {
    $this->volunteerId = (int) $volunteer_id;
    $db = $this->bfdb();
    $this->volunteer = $db->select('volunteers', 'v')->fields('v')->condition('id', $this->volunteerId)->execute()->fetchObject();
    if (!$this->volunteer) {
      throw new NotFoundHttpException();
    }

    $interest_rows = $db->query("\n      SELECT i.name\n      FROM volunteer_interests vi\n      INNER JOIN interest_areas i ON i.id = vi.interest_area_id\n      WHERE vi.volunteer_id = :id\n      ORDER BY i.name\n    ", [':id' => $this->volunteerId])->fetchCol();

    $form['#attached']['library'][] = 'bfep/admin';
    $form['application'] = ['#type' => 'details', '#title' => $this->t('Application'), '#open' => TRUE];
    $form['application']['name'] = $this->item('Name', $this->volunteer->full_name ?? NULL);
    $form['application']['email'] = $this->item('Email', $this->volunteer->email ?? NULL);
    $form['application']['hours'] = $this->item('Hours per week', $this->volunteer->hours_per_week ?? NULL);
    $form['application']['interests'] = $this->item('Interest areas', implode(', ', $interest_rows));
    $form['application']['skills'] = $this->item('Skills and experience', $this->volunteer->skills_experience ?? NULL);
    $form['application']['vouched'] = $this->item('Vouched for by', $this->volunteer->vouched_for_by ?? NULL);

    $accepted = $this->volunteer->accepted === NULL ? 'pending' : (!empty($this->volunteer->accepted) ? 'accepted' : 'not_accepted');
    $form['workflow'] = ['#type' => 'details', '#title' => $this->t('Volunteer workflow'), '#open' => TRUE];
    $form['workflow']['accepted'] = [
      '#type' => 'radios',
      '#title' => $this->t('Decision'),
      '#options' => ['pending' => $this->t('Pending'), 'accepted' => $this->t('Accepted'), 'not_accepted' => $this->t('Not accepted')],
      '#default_value' => $accepted,
    ];
    $form['workflow']['contacted'] = ['#type' => 'checkbox', '#title' => $this->t('Contacted'), '#default_value' => !empty($this->volunteer->contacted)];
    $form['workflow']['onboarded'] = ['#type' => 'checkbox', '#title' => $this->t('Onboarded'), '#default_value' => !empty($this->volunteer->onboarded)];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Save volunteer'), '#button_type' => 'primary'];
    $form['actions']['cancel'] = ['#type' => 'link', '#title' => $this->t('Back to volunteers'), '#url' => Url::fromRoute('bfep.admin_volunteers'), '#attributes' => ['class' => ['button']]];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($form_state->getValue('onboarded') && $form_state->getValue('accepted') !== 'accepted') {
      $form_state->setErrorByName('onboarded', $this->t('A volunteer must be accepted before they can be marked onboarded.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $accepted_value = match ($form_state->getValue('accepted')) {
      'accepted' => TRUE,
      'not_accepted' => FALSE,
      default => NULL,
    };
    $this->bfdb()->update('volunteers')
      ->fields([
        'accepted' => $accepted_value,
        'contacted' => (bool) $form_state->getValue('contacted'),
        'onboarded' => (bool) $form_state->getValue('onboarded'),
      ])
      ->condition('id', $this->volunteerId)
      ->execute();
    $this->messenger()->addStatus($this->t('Volunteer workflow saved.'));
    $form_state->setRedirect('bfep.admin_volunteer_review', ['volunteer_id' => $this->volunteerId]);
  }

}
