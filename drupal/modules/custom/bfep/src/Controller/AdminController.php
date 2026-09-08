<?php

namespace Drupal\bfep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

final class AdminController extends ControllerBase {

  public function __construct(
    protected Connection $database,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('bfep.database'));
  }

  protected function bfdb(): Connection {
    return $this->database;
  }

  protected function h($value): string {
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  protected function externalUrl(?string $value): ?Url {
    $value = trim((string) $value);
    if ($value === '' || !filter_var($value, FILTER_VALIDATE_URL)) {
      return NULL;
    }
    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
    if (!in_array($scheme, ['http', 'https'], TRUE)) {
      return NULL;
    }
    return Url::fromUri($value);
  }

  protected function pagination(Request $request, string $route, int $total, int $per_page): array {
    $page = max(1, (int) $request->query->get('p', 1));
    $total_pages = max(1, (int) ceil($total / $per_page));
    $page = min($page, $total_pages);
    $query = $request->query->all();
    unset($query['p']);

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['bfep-admin-pager']],
    ];

    if ($page > 1) {
      $previous = $query;
      $previous['p'] = $page - 1;
      $build['previous'] = [
        '#type' => 'link',
        '#title' => $this->t('Previous'),
        '#url' => Url::fromRoute($route, [], ['query' => $previous]),
        '#attributes' => ['class' => ['button']],
      ];
    }

    $build['status'] = [
      '#markup' => '<span>Page ' . $page . ' of ' . $total_pages . ' · ' . $total . ' records</span>',
    ];

    if ($page < $total_pages) {
      $next = $query;
      $next['p'] = $page + 1;
      $build['next'] = [
        '#type' => 'link',
        '#title' => $this->t('Next'),
        '#url' => Url::fromRoute($route, [], ['query' => $next]),
        '#attributes' => ['class' => ['button']],
      ];
    }

    return $build;
  }

  protected function pageSettings(Request $request): array {
    $per_page = (int) $request->query->get('per_page', 50);
    if (!in_array($per_page, [25, 50, 100], TRUE)) {
      $per_page = 50;
    }
    $page = max(1, (int) $request->query->get('p', 1));
    return [$page, $per_page, ($page - 1) * $per_page];
  }

  public function dashboard(): array {
    $started = microtime(TRUE);
    $db = $this->bfdb();

    $campaigns = (int) $db->query("SELECT COUNT(*) FROM campaigns WHERE deleted_at IS NULL")->fetchField();
    $referrals = (int) $db->query("SELECT COUNT(*) FROM referral_submissions")->fetchField();
    $volunteers = (int) $db->query("SELECT COUNT(*) FROM volunteers")->fetchField();
    $pending_volunteers = (int) $db->query("SELECT COUNT(*) FROM volunteers WHERE accepted IS NULL")->fetchField();
    $changes = (int) $db->query("SELECT COUNT(*) FROM info_change_requests WHERE processed = false")->fetchField();
    $latency = round((microtime(TRUE) - $started) * 1000, 1);

    $cards = [
      ['value' => $campaigns, 'label' => $this->t('Campaigns'), 'route' => 'bfep.admin_campaigns'],
      ['value' => $referrals, 'label' => $this->t('Referrals'), 'route' => 'bfep.admin_referrals'],
      ['value' => $pending_volunteers, 'label' => $this->t('Pending volunteers'), 'route' => 'bfep.admin_volunteers', 'query' => ['status' => 'pending']],
      ['value' => $changes, 'label' => $this->t('Pending changes'), 'route' => 'bfep.admin_changes', 'query' => ['status' => 'pending']],
    ];

    $stats = [
      '#type' => 'container',
      '#attributes' => ['class' => ['bfep-admin-stats']],
    ];
    foreach ($cards as $index => $card) {
      $stats['card_' . $index] = [
        '#type' => 'link',
        '#title' => [
          '#type' => 'inline_template',
          '#template' => '<span class="bfep-admin-stat-number">{{ value }}</span><span class="bfep-admin-stat-label">{{ label }}</span>',
          '#context' => ['value' => $card['value'], 'label' => $card['label']],
        ],
        '#url' => Url::fromRoute($card['route'], [], ['query' => $card['query'] ?? []]),
        '#attributes' => ['class' => ['bfep-admin-stat-card']],
      ];
    }

    return [
      '#attached' => ['library' => ['bfep/admin']],
      'intro' => [
        '#markup' => '<p class="bfep-admin-lead">Manage BFEP campaign data and staff review queues from one place.</p>',
      ],
      'stats' => $stats,
      'queues_title' => ['#markup' => '<h2>Work queues</h2>'],
      'queues' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['bfep-admin-queue-grid']],
        'campaigns' => $this->queueCard('Campaigns', $campaigns . ' active records', 'Edit public campaign information and internal flags.', 'bfep.admin_campaigns'),
        'referrals' => $this->queueCard('Referrals', $referrals . ' submissions', 'Review submitted fundraiser referrals and update verification status.', 'bfep.admin_referrals'),
        'volunteers' => $this->queueCard('Volunteers', $pending_volunteers . ' pending of ' . $volunteers, 'Accept, decline, contact, and onboard volunteer applicants.', 'bfep.admin_volunteers'),
        'changes' => $this->queueCard('Change requests', $changes . ' pending', 'Review requests to correct existing campaign information.', 'bfep.admin_changes'),
      ],
      'health' => [
        '#markup' => '<div class="bfep-admin-health"><strong>bfdb:</strong> connected · dashboard queries completed in ' . $latency . ' ms</div>',
      ],
    ];
  }

  protected function queueCard(string $title, string $meta, string $description, string $route): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['bfep-admin-queue-card']],
      'title' => ['#markup' => '<h3>' . $this->h($title) . '</h3>'],
      'meta' => ['#markup' => '<div class="bfep-admin-queue-meta">' . $this->h($meta) . '</div>'],
      'description' => ['#markup' => '<p>' . $this->h($description) . '</p>'],
      'link' => [
        '#type' => 'link',
        '#title' => $this->t('Open'),
        '#url' => Url::fromRoute($route),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
    ];
  }

  public function campaigns(Request $request): array {
    $db = $this->bfdb();
    [$page, $per_page, $offset] = $this->pageSettings($request);
    $q = trim((string) $request->query->get('q', ''));
    $featured = $request->query->get('featured') === '1';
    $urgent = $request->query->get('urgent') === '1';
    $where = ['deleted_at IS NULL'];
    $params = [];

    if ($q !== '') {
      $where[] = '(contact_name ILIKE :q OR country_raw ILIKE :q OR description ILIKE :q OR CAST(line_number AS TEXT) ILIKE :q)';
      $params[':q'] = '%' . $q . '%';
    }
    if ($featured) {
      $where[] = 'featured_by_bfep = true';
    }
    if ($urgent) {
      $where[] = 'urgent_medical_needs = true';
    }
    $where_sql = implode(' AND ', $where);

    $total = (int) $db->query("SELECT COUNT(*) FROM campaigns WHERE {$where_sql}", $params)->fetchField();
    $rows = $db->query("\n      SELECT id, line_number, contact_name, country_raw, featured_by_bfep, urgent_medical_needs, updated_at\n      FROM campaigns\n      WHERE {$where_sql}\n      ORDER BY updated_at DESC NULLS LAST, id DESC\n      LIMIT {$per_page} OFFSET {$offset}\n    ", $params)->fetchAll();

    $table_rows = [];
    foreach ($rows as $row) {
      $table_rows[] = [
        'line' => $row->line_number ?: '—',
        'name' => $row->contact_name ?: 'Campaign #' . $row->id,
        'country' => $row->country_raw ?: '—',
        'featured' => !empty($row->featured_by_bfep) ? 'Yes' : 'No',
        'urgent' => !empty($row->urgent_medical_needs) ? 'Yes' : 'No',
        'updated' => $row->updated_at ?: '—',
        'operations' => [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'edit' => [
                'title' => $this->t('Edit'),
                'url' => Url::fromRoute('bfep.admin_campaign_edit', ['campaign_id' => $row->id]),
              ],
              'view' => [
                'title' => $this->t('View public page'),
                'url' => Url::fromRoute('bfep.campaign_detail', ['campaign_id' => $row->id]),
                'attributes' => ['target' => '_blank', 'rel' => 'noopener'],
              ],
            ],
          ],
        ],
      ];
    }

    return $this->adminListBuild(
      $request,
      'campaigns',
      'bfep.admin_campaigns',
      $total,
      $per_page,
      ['Line', 'Name', 'Country', 'Featured', 'Urgent', 'Updated', 'Operations'],
      $table_rows,
      'No campaigns found.'
    );
  }

  public function referrals(Request $request): array {
    $db = $this->bfdb();
    [$page, $per_page, $offset] = $this->pageSettings($request);
    $q = trim((string) $request->query->get('q', ''));
    $status = trim((string) $request->query->get('status', ''));
    $where = ['id IS NOT NULL'];
    $params = [];

    if ($q !== '') {
      $where[] = '(full_name ILIKE :q OR email ILIKE :q OR fundraiser_url ILIKE :q)';
      $params[':q'] = '%' . $q . '%';
    }
    if ($status !== '') {
      $where[] = 'verification_status = :status';
      $params[':status'] = $status;
    }
    $where_sql = implode(' AND ', $where);

    $total = (int) $db->query("SELECT COUNT(*) FROM referral_submissions WHERE {$where_sql}", $params)->fetchField();
    $rows = $db->query("\n      SELECT id, verification_status, email, full_name, fundraiser_url, created_at\n      FROM referral_submissions\n      WHERE {$where_sql}\n      ORDER BY created_at DESC NULLS LAST, id DESC\n      LIMIT {$per_page} OFFSET {$offset}\n    ", $params)->fetchAll();

    $table_rows = [];
    foreach ($rows as $row) {
      $links = [
        'review' => [
          'title' => $this->t('Review'),
          'url' => Url::fromRoute('bfep.admin_referral_review', ['referral_id' => $row->id]),
        ],
      ];
      if ($url = $this->externalUrl($row->fundraiser_url)) {
        $links['fundraiser'] = [
          'title' => $this->t('Open fundraiser'),
          'url' => $url,
          'attributes' => ['target' => '_blank', 'rel' => 'noopener'],
        ];
      }
      $table_rows[] = [
        'status' => $row->verification_status ?: 'Pending',
        'name' => $row->full_name ?: '—',
        'email' => $row->email ?: '—',
        'created' => $row->created_at ?: '—',
        'operations' => ['data' => ['#type' => 'operations', '#links' => $links]],
      ];
    }

    return $this->adminListBuild(
      $request,
      'referrals',
      'bfep.admin_referrals',
      $total,
      $per_page,
      ['Status', 'Name', 'Email', 'Created', 'Operations'],
      $table_rows,
      'No referrals found.'
    );
  }

  public function volunteers(Request $request): array {
    $db = $this->bfdb();
    [$page, $per_page, $offset] = $this->pageSettings($request);
    $q = trim((string) $request->query->get('q', ''));
    $status = trim((string) $request->query->get('status', ''));
    $where = ['id IS NOT NULL'];
    $params = [];

    if ($q !== '') {
      $where[] = '(full_name ILIKE :q OR email ILIKE :q OR skills_experience ILIKE :q)';
      $params[':q'] = '%' . $q . '%';
    }
    $where[] = match ($status) {
      'pending' => 'accepted IS NULL',
      'accepted' => 'accepted = true',
      'not_accepted' => 'accepted = false',
      'onboarded' => 'onboarded = true',
      default => 'TRUE',
    };
    $where_sql = implode(' AND ', $where);

    $total = (int) $db->query("SELECT COUNT(*) FROM volunteers WHERE {$where_sql}", $params)->fetchField();
    $rows = $db->query("\n      SELECT id, full_name, email, hours_per_week, accepted, contacted, onboarded, created_at\n      FROM volunteers\n      WHERE {$where_sql}\n      ORDER BY created_at DESC NULLS LAST, id DESC\n      LIMIT {$per_page} OFFSET {$offset}\n    ", $params)->fetchAll();

    $table_rows = [];
    foreach ($rows as $row) {
      $status_label = $row->accepted === NULL ? 'Pending' : (!empty($row->accepted) ? 'Accepted' : 'Not accepted');
      if (!empty($row->onboarded)) {
        $status_label .= ' · Onboarded';
      }
      $table_rows[] = [
        'status' => $status_label,
        'name' => $row->full_name ?: '—',
        'email' => $row->email ?: '—',
        'hours' => $row->hours_per_week ?: '—',
        'contacted' => !empty($row->contacted) ? 'Yes' : 'No',
        'created' => $row->created_at ?: '—',
        'operations' => [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'review' => [
                'title' => $this->t('Review'),
                'url' => Url::fromRoute('bfep.admin_volunteer_review', ['volunteer_id' => $row->id]),
              ],
            ],
          ],
        ],
      ];
    }

    return $this->adminListBuild(
      $request,
      'volunteers',
      'bfep.admin_volunteers',
      $total,
      $per_page,
      ['Status', 'Name', 'Email', 'Hours', 'Contacted', 'Created', 'Operations'],
      $table_rows,
      'No volunteers found.'
    );
  }

  public function changes(Request $request): array {
    $db = $this->bfdb();
    [$page, $per_page, $offset] = $this->pageSettings($request);
    $q = trim((string) $request->query->get('q', ''));
    $status = trim((string) $request->query->get('status', ''));
    $where = ['id IS NOT NULL'];
    $params = [];

    if ($q !== '') {
      $where[] = '(submitter_email ILIKE :q OR submitter_name ILIKE :q OR family_line_number_raw ILIKE :q OR change_description ILIKE :q OR CAST(campaign_id AS TEXT) ILIKE :q)';
      $params[':q'] = '%' . $q . '%';
    }
    if ($status === 'pending') {
      $where[] = 'processed = false';
    }
    elseif ($status === 'processed') {
      $where[] = 'processed = true';
    }
    $where_sql = implode(' AND ', $where);

    $total = (int) $db->query("SELECT COUNT(*) FROM info_change_requests WHERE {$where_sql}", $params)->fetchField();
    $rows = $db->query("\n      SELECT id, submitter_email, submitter_type, submitter_name, family_line_number_raw, campaign_id, fields_to_change, processed, created_at\n      FROM info_change_requests\n      WHERE {$where_sql}\n      ORDER BY processed ASC, created_at DESC NULLS LAST, id DESC\n      LIMIT {$per_page} OFFSET {$offset}\n    ", $params)->fetchAll();

    $table_rows = [];
    foreach ($rows as $row) {
      $links = [
        'review' => [
          'title' => $this->t('Review'),
          'url' => Url::fromRoute('bfep.admin_change_review', ['request_id' => $row->id]),
        ],
      ];
      if (!empty($row->campaign_id)) {
        $links['campaign'] = [
          'title' => $this->t('Open campaign'),
          'url' => Url::fromRoute('bfep.admin_campaign_edit', ['campaign_id' => $row->campaign_id]),
        ];
      }
      $table_rows[] = [
        'status' => !empty($row->processed) ? 'Processed' : 'Pending',
        'submitter' => $row->submitter_name ?: $row->submitter_email ?: '—',
        'type' => $row->submitter_type ?: '—',
        'line' => $row->family_line_number_raw ?: '—',
        'campaign' => $row->campaign_id ?: '—',
        'fields' => $row->fields_to_change ?: '—',
        'created' => $row->created_at ?: '—',
        'operations' => ['data' => ['#type' => 'operations', '#links' => $links]],
      ];
    }

    $build = $this->adminListBuild(
      $request,
      'changes',
      'bfep.admin_changes',
      $total,
      $per_page,
      ['Status', 'Submitter', 'Type', 'Line ref', 'Campaign', 'Fields', 'Created', 'Operations'],
      $table_rows,
      'No change requests found.'
    );
    $build['privacy_notice'] = [
      '#weight' => -20,
      '#markup' => '<p class="bfep-admin-notice"><strong>Staff only:</strong> this page contains private submitter and workflow information.</p>',
    ];
    return $build;
  }

  protected function adminListBuild(Request $request, string $section, string $route, int $total, int $per_page, array $header, array $rows, string $empty): array {
    return [
      '#attached' => ['library' => ['bfep/admin']],
      'filters' => $this->formBuilder()->getForm('Drupal\\bfep\\Form\\AdminFilterForm', $section),
      'summary' => [
        '#markup' => '<p><strong>' . $total . '</strong> matching records.</p>',
      ],
      'pager_top' => $this->pagination($request, $route, $total, $per_page),
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $empty,
        '#attributes' => ['class' => ['bfep-admin-table']],
      ],
      'pager_bottom' => $this->pagination($request, $route, $total, $per_page),
    ];
  }

}
