<?php

namespace Drupal\bfep\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\bfep\Admin\AdminFormat;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class AdminFilterForm extends FormBase {

  protected const ROUTES = [
    'campaigns' => 'bfep.admin_campaigns',
    'referrals' => 'bfep.admin_referrals',
    'volunteers' => 'bfep.admin_volunteers',
    'changes' => 'bfep.admin_changes',
  ];

  public function __construct(
    protected Connection $database,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('bfep.database'));
  }

  public function getFormId(): string {
    return 'bfep_admin_filter_form';
  }

  protected function bfdb(): Connection {
    return $this->database;
  }

  protected function referralStatusOptions(): array {
    $options = ['' => '- Any status -', 'pending' => 'Pending (including no status)'];

    try {
      $rows = $this->bfdb()->query("\n        SELECT DISTINCT CAST(verification_status AS TEXT) AS value\n        FROM referral_submissions\n        WHERE verification_status IS NOT NULL AND CAST(verification_status AS TEXT) <> ''\n        ORDER BY value\n      ")->fetchAll();

      foreach ($rows as $row) {
        $key = strtolower(trim((string) $row->value));
        if ($key === '' || $key === 'pending' || isset($options[$key])) {
          continue;
        }
        $options[$key] = AdminFormat::referralStatusLabel((string) $row->value);
      }
    }
    catch (\Throwable) {
      // Keep the form usable even if the external database is temporarily unavailable.
    }

    return $options;
  }

  public function buildForm(array $form, FormStateInterface $form_state, $section = 'campaigns'): array {
    $section = isset(self::ROUTES[$section]) ? $section : 'campaigns';
    $request = $this->getRequest();

    $form_state->set('section', $section);
    $form['#method'] = 'get';
    $form['#action'] = Url::fromRoute(self::ROUTES[$section])->toString();
    $form['#attributes']['class'][] = 'bfep-admin-filter';

    $form['q'] = [
      '#type' => 'search',
      '#title' => $this->t('Search'),
      '#default_value' => $request->query->get('q', ''),
      '#placeholder' => $this->t('Search records…'),
    ];

    if ($section === 'campaigns') {
      $form['featured'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Featured'),
        '#return_value' => '1',
        '#default_value' => $request->query->get('featured') === '1',
      ];
      $form['urgent'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Urgent'),
        '#return_value' => '1',
        '#default_value' => $request->query->get('urgent') === '1',
      ];
      $form['sync'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Sync problems'),
        '#return_value' => 'problem',
        '#default_value' => $request->query->get('sync') === 'problem',
      ];
    }
    elseif ($section === 'referrals') {
      $form['status'] = [
        '#type' => 'select',
        '#title' => $this->t('Status'),
        '#options' => $this->referralStatusOptions(),
        '#default_value' => $request->query->get('status', ''),
      ];
    }
    elseif ($section === 'volunteers') {
      $form['status'] = [
        '#type' => 'select',
        '#title' => $this->t('Status'),
        '#options' => [
          '' => $this->t('- Any status -'),
          'pending' => $this->t('Pending'),
          'accepted' => $this->t('Accepted'),
          'not_accepted' => $this->t('Not accepted'),
          'onboarded' => $this->t('Onboarded'),
        ],
        '#default_value' => $request->query->get('status', ''),
      ];
    }
    elseif ($section === 'changes') {
      $form['status'] = [
        '#type' => 'select',
        '#title' => $this->t('Status'),
        '#options' => [
          '' => $this->t('- Any status -'),
          'pending' => $this->t('Pending'),
          'processed' => $this->t('Processed'),
        ],
        '#default_value' => $request->query->get('status', ''),
      ];
    }

    $form['per_page'] = [
      '#type' => 'select',
      '#title' => $this->t('Per page'),
      '#options' => [25 => '25', 50 => '50', 100 => '100'],
      '#default_value' => (int) $request->query->get('per_page', 50),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply'),
      '#button_type' => 'primary',
    ];
    $form['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Reset'),
      '#url' => Url::fromRoute(self::ROUTES[$section]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $section = (string) $form_state->get('section');
    $route = self::ROUTES[$section] ?? self::ROUTES['campaigns'];
    $values = $form_state->getValues();
    $query = [];

    foreach (['q', 'status', 'featured', 'urgent', 'sync', 'per_page'] as $key) {
      if (!array_key_exists($key, $values)) {
        continue;
      }
      $value = $values[$key];
      if ($value !== '' && $value !== NULL && $value !== 0 && $value !== FALSE) {
        $query[$key] = $value;
      }
    }

    $form_state->setRedirect($route, [], ['query' => $query]);
  }

}
