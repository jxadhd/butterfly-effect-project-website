<?php

declare(strict_types=1);

namespace Drupal\bfep\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\bfep\Service\SubmissionGuard;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared privacy notice, database injection, and abuse controls for forms.
 */
abstract class ProtectedExternalFormBase extends FormBase {

  public function __construct(
    protected Connection $database,
    protected SubmissionGuard $submissionGuard,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bfep.database'),
      $container->get('bfep.submission_guard'),
    );
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $this->submissionGuard->validate($form_state, $this->getFormId());
  }

  protected function prepareForm(array &$form): void {
    $form['#attributes']['class'][] = 'bfep-public-form';
    $form['privacy_notice'] = [
      '#type' => 'container',
      '#weight' => -100,
      '#attributes' => ['class' => ['bfep-form-notice']],
      'copy' => [
        '#markup' => $this->t('Please provide only information needed for this request. See our <a href=":privacy">privacy notice</a>.', [
          ':privacy' => Url::fromRoute('bfep.trust_privacy')->toString(),
        ]),
      ],
    ];
    $form['consent'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I have read the privacy notice and consent to this information being used to handle my submission.'),
      '#required' => TRUE,
      '#weight' => 90,
    ];
    if (isset($form['actions'])) {
      $form['actions']['#weight'] = 100;
    }
  }

  protected function registerSubmission(): void {
    $this->submissionGuard->register($this->getFormId());
  }

  protected function clean(mixed $value): string {
    return trim((string) ($value ?? ''));
  }

}
