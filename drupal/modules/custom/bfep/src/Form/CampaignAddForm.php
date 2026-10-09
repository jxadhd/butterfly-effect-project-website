<?php

namespace Drupal\bfep\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\bfep\Service\AuditLogger;
use Drupal\bfep\Service\BfepCacheInvalidator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates a complete BFEP campaign directly in the external bfdb database.
 *
 * No Drupal node/content entity is created.
 */
final class CampaignAddForm extends FormBase {

  private const LINE_LOCK_ID = 42633701;

  public function __construct(
    protected Connection $database,
    protected BfepCacheInvalidator $cacheInvalidator,
    protected AuditLogger $auditLogger,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bfep.database'),
      $container->get('bfep.cache_invalidator'),
      $container->get('bfep.audit_logger'),
    );
  }

  public function getFormId(): string {
    return 'bfep_campaign_add_form';
  }

  protected function bfdb(): Connection {
    return $this->database;
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $db = $this->bfdb();

    $country_options = [];
    foreach ($db->query("
      SELECT id, name, global_region
      FROM countries
      ORDER BY name
    ")->fetchAll() as $row) {
      $label = (string) $row->name;
      if (!empty($row->global_region)) {
        $label .= ' — ' . $row->global_region;
      }
      $country_options[(int) $row->id] = $label;
    }

    $platform_options = ['' => $this->t('- No fundraiser yet -')];
    foreach ($db->query("
      SELECT id, name
      FROM fundraising_platforms
      ORDER BY name
    ")->fetchAll() as $row) {
      $platform_options[(int) $row->id] = (string) $row->name;
    }

    $tag_options = [];
    foreach ($db->query("
      SELECT id, name
      FROM tags
      ORDER BY name
    ")->fetchAll() as $row) {
      $tag_options[(int) $row->id] = (string) $row->name;
    }

    $form['#attached']['library'][] = 'bfep/admin';
    $form['#attributes']['class'][] = 'bfep-admin-editor';

    $form['intro'] = [
      '#markup' => '<p class="bfep-admin-lead">Create the campaign and its related BFEP database records in one transaction. This does not create Drupal content.</p>',
    ];

    $form['identity'] = [
      '#type' => 'details',
      '#title' => $this->t('Campaign'),
      '#open' => TRUE,
    ];

    $form['identity']['contact_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Contact / campaign name'),
      '#required' => TRUE,
      '#maxlength' => 500,
    ];

    $form['identity']['country_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Country'),
      '#options' => $country_options,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select country -'),
    ];

    $form['identity']['career'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Career / background'),
      '#maxlength' => 500,
    ];

    $form['identity']['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Public description'),
      '#rows' => 8,
      '#maxlength' => 5000,
      '#description' => $this->t('Public-facing summary only. Put private working information in Internal notes.'),
    ];

    $form['identity']['featured_by_bfep'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Featured by BFEP'),
    ];

    $form['identity']['urgent_medical_needs'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Urgent medical needs'),
    ];

    $form['fundraiser'] = [
      '#type' => 'details',
      '#title' => $this->t('Fundraiser'),
      '#open' => TRUE,
    ];

    $form['fundraiser']['platform_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Fundraising platform'),
      '#options' => $platform_options,
      '#description' => $this->t('Leave as “No fundraiser yet” only when this campaign does not currently have a fundraiser.'),
    ];

    $form['fundraiser']['fundraiser_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Fundraiser URL'),
      '#maxlength' => 2000,
    ];

    $form['fundraiser']['currency_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Currency code'),
      '#maxlength' => 3,
      '#size' => 6,
      '#placeholder' => 'USD',
      '#description' => $this->t('Three-letter currency code, for example USD, EUR, AUD or NZD.'),
    ];

    $form['fundraiser']['goal_amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Goal amount'),
      '#min' => 0,
      '#step' => '0.01',
    ];

    $form['fundraiser']['donated_amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Amount donated / raised'),
      '#min' => 0,
      '#step' => '0.01',
    ];

    $form['tags_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Tags'),
      '#open' => TRUE,
    ];

    $form['tags_section']['tag_ids'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Campaign tags'),
      '#options' => $tag_options,
    ];

    $form['social'] = [
      '#type' => 'details',
      '#title' => $this->t('Social media'),
      '#open' => FALSE,
      '#description' => $this->t('Enter a handle or URL only for platforms that should be attached to this campaign.'),
    ];

    $social_labels = [
      'instagram' => 'Instagram',
      'facebook' => 'Facebook',
      'tiktok' => 'TikTok',
      'telegram' => 'Telegram',
      'twitter' => 'Twitter / X',
      'youtube' => 'YouTube',
      'discord' => 'Discord',
      'website' => 'Website',
      'other' => 'Other',
    ];

    foreach ($social_labels as $platform => $label) {
      $form['social']['social_' . $platform] = [
        '#type' => 'textfield',
        '#title' => $this->t($label),
        '#maxlength' => 2000,
      ];
    }

    $form['amp'] = [
      '#type' => 'details',
      '#title' => $this->t('AMP / amplification'),
      '#open' => FALSE,
      '#description' => $this->t('Optional internal amplification records.'),
    ];

    foreach (['weekly' => 'Weekly', 'global' => 'Global'] as $type => $label) {
      $form['amp'][$type . '_enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Create @type AMP record', ['@type' => $label]),
      ];

      $form['amp'][$type . '_sequence_number'] = [
        '#type' => 'number',
        '#title' => $this->t('@type sequence number', ['@type' => $label]),
        '#min' => 1,
        '#step' => 1,
        '#states' => [
          'visible' => [
            ':input[name="' . $type . '_enabled"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['amp'][$type . '_featured_by_bfep'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('@type AMP featured by BFEP', ['@type' => $label]),
        '#states' => [
          'visible' => [
            ':input[name="' . $type . '_enabled"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['amp'][$type . '_video_made'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('@type video made', ['@type' => $label]),
        '#states' => [
          'visible' => [
            ':input[name="' . $type . '_enabled"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['amp'][$type . '_notes'] = [
        '#type' => 'textarea',
        '#title' => $this->t('@type AMP notes', ['@type' => $label]),
        '#rows' => 3,
        '#states' => [
          'visible' => [
            ':input[name="' . $type . '_enabled"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    $form['review'] = [
      '#type' => 'details',
      '#title' => $this->t('Internal review'),
      '#open' => FALSE,
    ];

    $form['review']['vetted_by_trusted_group'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Vetted by trusted group'),
      '#maxlength' => 500,
    ];

    $form['review']['featured_self_selected'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Featured self-selected'),
      '#maxlength' => 500,
    ];

    $form['review']['no_feature_response'] = [
      '#type' => 'textfield',
      '#title' => $this->t('No-feature response'),
      '#maxlength' => 500,
    ];

    $form['review']['internal_notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Internal notes'),
      '#rows' => 6,
    ];

    $form['actions'] = ['#type' => 'actions'];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add campaign'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('bfep.admin_campaigns'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $name = trim((string) $form_state->getValue('contact_name'));
    if ($name === '') {
      $form_state->setErrorByName('contact_name', $this->t('A campaign/contact name is required.'));
    }

    $platform_id = (int) $form_state->getValue('platform_id');
    $url = trim((string) $form_state->getValue('fundraiser_url'));
    $currency = strtoupper(trim((string) $form_state->getValue('currency_code')));
    $goal = $form_state->getValue('goal_amount');
    $donated = $form_state->getValue('donated_amount');

    $has_fundraiser_data = $platform_id > 0
      || $url !== ''
      || $currency !== ''
      || ($goal !== '' && $goal !== NULL)
      || ($donated !== '' && $donated !== NULL);

    if ($has_fundraiser_data && $platform_id < 1) {
      $form_state->setErrorByName('platform_id', $this->t('Select a fundraising platform when entering fundraiser information.'));
    }

    if ($platform_id > 0 && $url === '') {
      $form_state->setErrorByName('fundraiser_url', $this->t('Enter the fundraiser URL.'));
    }

    if ($url !== '' && !in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], TRUE)) {
      $form_state->setErrorByName('fundraiser_url', $this->t('Use a valid public http:// or https:// fundraiser URL.'));
    }

    if ($currency !== '' && !preg_match('/^[A-Z]{3}$/', $currency)) {
      $form_state->setErrorByName('currency_code', $this->t('Currency must be a three-letter code such as USD.'));
    }

    if (($goal !== '' && $goal !== NULL) && (float) $goal < 0) {
      $form_state->setErrorByName('goal_amount', $this->t('Goal amount cannot be negative.'));
    }

    if (($donated !== '' && $donated !== NULL) && (float) $donated < 0) {
      $form_state->setErrorByName('donated_amount', $this->t('Donated amount cannot be negative.'));
    }

    foreach (['weekly', 'global'] as $type) {
      if ($form_state->getValue($type . '_enabled')) {
        $sequence = $form_state->getValue($type . '_sequence_number');
        if ($sequence !== '' && $sequence !== NULL && (int) $sequence < 1) {
          $form_state->setErrorByName(
            $type . '_sequence_number',
            $this->t('AMP sequence numbers must be positive integers.')
          );
        }
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $db = $this->bfdb();
    $transaction = $db->startTransaction();

    try {
      // Prevent concurrent staff submissions from assigning the same gap.
      $db->query('SELECT pg_advisory_xact_lock(:lock_id)', [
        ':lock_id' => self::LINE_LOCK_ID,
      ]);

      $line_number = (int) $db->query("
        SELECT COALESCE(
          (
            SELECT gs
            FROM generate_series(
              1,
              GREATEST(
                COALESCE(
                  (SELECT MAX(line_number)
                   FROM campaigns
                   WHERE deleted_at IS NULL),
                  0
                ) + 1,
                1
              )
            ) AS gs
            LEFT JOIN campaigns c
              ON c.line_number = gs
             AND c.deleted_at IS NULL
            WHERE c.id IS NULL
            ORDER BY gs
            LIMIT 1
          ),
          1
        )
      ")->fetchField();

      $country_id = (int) $form_state->getValue('country_id');
      $country_name = $db->query("
        SELECT name
        FROM countries
        WHERE id = :id
      ", [':id' => $country_id])->fetchField();

      if ($country_name === FALSE) {
        throw new \RuntimeException('Selected BFEP country does not exist.');
      }

      $campaign_id = (int) $db->query("
        INSERT INTO campaigns (
          line_number,
          contact_name,
          country_id,
          country_raw,
          description,
          featured_by_bfep,
          urgent_medical_needs,
          career,
          vetted_by_trusted_group,
          featured_self_selected,
          no_feature_response,
          internal_notes
        )
        VALUES (
          :line_number,
          :contact_name,
          :country_id,
          :country_raw,
          :description,
          CAST(:featured_by_bfep AS boolean),
          CAST(:urgent_medical_needs AS boolean),
          :career,
          :vetted_by_trusted_group,
          :featured_self_selected,
          :no_feature_response,
          :internal_notes
        )
        RETURNING id
      ", [
        ':line_number' => $line_number,
        ':contact_name' => trim((string) $form_state->getValue('contact_name')),
        ':country_id' => $country_id,
        ':country_raw' => (string) $country_name,
        ':description' => $this->nullableText($form_state->getValue('description')),
        ':featured_by_bfep' => $this->pgBool($form_state->getValue('featured_by_bfep')),
        ':urgent_medical_needs' => $this->pgBool($form_state->getValue('urgent_medical_needs')),
        ':career' => $this->nullableText($form_state->getValue('career')),
        ':vetted_by_trusted_group' => $this->nullableText($form_state->getValue('vetted_by_trusted_group')),
        ':featured_self_selected' => $this->nullableText($form_state->getValue('featured_self_selected')),
        ':no_feature_response' => $this->nullableText($form_state->getValue('no_feature_response')),
        ':internal_notes' => $this->nullableText($form_state->getValue('internal_notes')),
      ])->fetchField();

      if ($campaign_id < 1) {
        throw new \RuntimeException('Campaign insert did not return a valid ID.');
      }

      // Create the active fundraiser only when a platform was selected.
      $platform_id = (int) $form_state->getValue('platform_id');
      if ($platform_id > 0) {
        $platform_exists = (int) $db->query("
          SELECT CASE WHEN EXISTS (
            SELECT 1
            FROM fundraising_platforms
            WHERE id = :id
          ) THEN 1 ELSE 0 END
        ", [':id' => $platform_id])->fetchField();

        if (!$platform_exists) {
          throw new \RuntimeException('Selected fundraising platform does not exist.');
        }

        $currency = strtoupper(trim((string) $form_state->getValue('currency_code')));

        $db->query("
          INSERT INTO campaign_fundraisers (
            campaign_id,
            platform_id,
            url,
            currency_code,
            goal_amount,
            donated_amount,
            is_active
          )
          VALUES (
            :campaign_id,
            :platform_id,
            :url,
            :currency_code,
            :goal_amount,
            :donated_amount,
            TRUE
          )
        ", [
          ':campaign_id' => $campaign_id,
          ':platform_id' => $platform_id,
          ':url' => $this->nullableText($form_state->getValue('fundraiser_url')),
          ':currency_code' => $currency === '' ? NULL : $currency,
          ':goal_amount' => $this->nullableNumber($form_state->getValue('goal_amount')),
          ':donated_amount' => $this->nullableNumber($form_state->getValue('donated_amount')),
        ]);
      }

      // Attach selected existing tags.
      $selected_tags = array_values(array_filter(
        $form_state->getValue('tag_ids') ?: [],
        static fn($value) => (int) $value > 0
      ));

      foreach ($selected_tags as $tag_id) {
        $tag_id = (int) $tag_id;

        $tag_exists = (int) $db->query("
          SELECT CASE WHEN EXISTS (
            SELECT 1
            FROM tags
            WHERE id = :id
          ) THEN 1 ELSE 0 END
        ", [':id' => $tag_id])->fetchField();

        if (!$tag_exists) {
          throw new \RuntimeException("Selected tag {$tag_id} does not exist.");
        }

        $db->query("
          INSERT INTO campaign_tags (campaign_id, tag_id)
          VALUES (:campaign_id, :tag_id)
          ON CONFLICT DO NOTHING
        ", [
          ':campaign_id' => $campaign_id,
          ':tag_id' => $tag_id,
        ]);
      }

      // Add social records only for populated fields.
      $social_platforms = [
        'instagram',
        'facebook',
        'tiktok',
        'telegram',
        'twitter',
        'youtube',
        'discord',
        'website',
        'other',
      ];

      foreach ($social_platforms as $platform) {
        $value = trim((string) $form_state->getValue('social_' . $platform));
        if ($value === '') {
          continue;
        }

        $db->query("
          INSERT INTO campaign_social_media (
            campaign_id,
            platform,
            handle_or_url,
            is_active
          )
          VALUES (
            :campaign_id,
            CAST(:platform AS social_platform),
            :handle_or_url,
            TRUE
          )
        ", [
          ':campaign_id' => $campaign_id,
          ':platform' => $platform,
          ':handle_or_url' => $value,
        ]);
      }

      // Optional internal AMP records.
      foreach (['weekly', 'global'] as $amp_type) {
        if (!$form_state->getValue($amp_type . '_enabled')) {
          continue;
        }

        $sequence = $form_state->getValue($amp_type . '_sequence_number');

        $db->query("
          INSERT INTO amps (
            campaign_id,
            amp_type,
            sequence_number,
            featured_by_bfep,
            notes,
            video_made
          )
          VALUES (
            :campaign_id,
            CAST(:amp_type AS amp_type),
            :sequence_number,
            CAST(:featured AS boolean),
            :notes,
            CAST(:video_made AS boolean)
          )
        ", [
          ':campaign_id' => $campaign_id,
          ':amp_type' => $amp_type,
          ':sequence_number' => ($sequence === '' || $sequence === NULL) ? NULL : (int) $sequence,
          ':featured' => $this->pgBool($form_state->getValue($amp_type . '_featured_by_bfep')),
          ':notes' => $this->nullableText($form_state->getValue($amp_type . '_notes')),
          ':video_made' => $this->pgBool($form_state->getValue($amp_type . '_video_made')),
        ]);
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }

    // Commit before invalidating, so a concurrent request cannot repopulate the
    // caches from the pre-insert snapshot.
    unset($transaction);
    $this->cacheInvalidator->invalidateCampaign($campaign_id);
    $this->auditLogger->record('created', 'campaign', $campaign_id, ['line_number']);

    $this->messenger()->addStatus($this->t(
      'Campaign @id added to BFEP as line @line with its related database records.',
      [
        '@id' => $campaign_id,
        '@line' => $line_number,
      ]
    ));

    $form_state->setRedirect('bfep.admin_campaign_edit', [
      'campaign_id' => $campaign_id,
    ]);
  }

  private function nullableText(mixed $value): ?string {
    $value = trim((string) ($value ?? ''));
    return $value === '' ? NULL : $value;
  }

  private function nullableNumber(mixed $value): ?string {
    if ($value === '' || $value === NULL) {
      return NULL;
    }
    return (string) $value;
  }

  private function pgBool(mixed $value): string {
    return !empty($value) ? 'true' : 'false';
  }

}
