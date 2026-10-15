<?php

declare(strict_types=1);

namespace Drupal\bfep\Form;

use Drupal\Component\Utility\Bytes;
use Drupal\Component\Utility\Environment;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Drupal\bfep\Admin\AdminFormat;
use Drupal\bfep\Admin\PgEnum;
use Drupal\bfep\Repository\InitiativeMedia;
use Drupal\bfep\Service\AuditLogger;
use Drupal\bfep\Service\BfepCacheInvalidator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Adds or edits a ground initiative.
 *
 * Only featured initiatives are shown on the public Ground initiatives page.
 * The text fields live in bfdb's ground_initiatives; images and fundraising
 * links live in Drupal (see InitiativeMedia). Optional columns (country_id, internal_notes, updated_at) are edited only
 * when bfdb has them, so the form works against older schemas too.
 */
final class InitiativeForm extends FormBase {

  use ReturnToListTrait;
  use SaveFailureTrait;

  /**
   * Columns this form can edit when they exist in ground_initiatives.
   */
  private const OPTIONAL_COLUMNS = ['country_id', 'internal_notes', 'updated_at'];

  protected int $initiativeId = 0;
  protected ?object $initiative = NULL;

  /**
   * The optional columns present in bfdb, keyed by name.
   *
   * @var array<string, true>
   */
  protected array $columns = [];

  public function __construct(
    protected Connection $database,
    protected BfepCacheInvalidator $cacheInvalidator,
    protected AuditLogger $auditLogger,
    protected InitiativeMedia $media,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bfep.database'),
      $container->get('bfep.cache_invalidator'),
      $container->get('bfep.audit_logger'),
      $container->get('bfep.initiative_media'),
    );
  }

  public function getFormId(): string {
    return 'bfep_initiative_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $initiative_id = NULL): array {
    $this->initiativeId = (int) $initiative_id;
    $this->columns = $this->optionalColumns();
    if ($this->initiativeId > 0) {
      $this->initiative = $this->database->select('ground_initiatives', 'g')
        ->fields('g')
        ->condition('id', $this->initiativeId)
        ->execute()
        ->fetchObject() ?: throw new NotFoundHttpException();
    }
    $current = $this->initiative;
    $this->warnIfPostWasDropped();

    $form['#attached']['library'][] = 'bfep/admin';
    if ($current !== NULL) {
      $form['summary'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['bfep-admin-edit-summary']],
        'text' => ['#markup' => '<strong>' . $this->t('Ground initiative #@id', ['@id' => $this->initiativeId]) . '</strong>'],
      ];
      if (AdminFormat::truthy($current->featured_by_bfep ?? NULL)) {
        $form['summary']['preview'] = [
          '#type' => 'link',
          '#title' => $this->t('View on the public page'),
          '#url' => Url::fromRoute('bfep.initiatives', [], ['fragment' => 'initiative-' . $this->initiativeId]),
          '#attributes' => ['class' => ['button'], 'target' => '_blank', 'rel' => 'noopener'],
        ];
      }
    }

    $form['contact_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name'),
      '#description' => $this->t('The initiative or organiser name shown on the public page.'),
      '#default_value' => $current->contact_name ?? '',
      '#required' => TRUE,
      '#maxlength' => 500,
    ];
    $form['country'] = $this->countryElement($current);
    $form['initiative_type'] = $this->typeElement($current);
    $form['project_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('About the initiative'),
      '#description' => $this->t('A paragraph on what the initiative is and does, shown on the public page. Do not include private contact details or verification evidence.'),
      '#default_value' => $current->project_description ?? '',
      '#rows' => 8,
      '#maxlength' => 5000,
    ];
    if ($this->media->available()) {
      $form['images'] = $this->imagesElement();
      $form['links'] = $this->linksElement();
    }
    else {
      $form['media_missing'] = [
        '#markup' => '<p class="bfep-admin-notice">' . $this->t('Photos and fundraising links need two new bfdb tables. Ask the database owner to run sql/ground-initiative-media.sql from the bfep module, then reload this page.') . '</p>',
      ];
    }
    $form['featured_by_bfep'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Featured by BFEP'),
      '#description' => $this->t('Only featured initiatives are listed on the public Ground initiatives page.'),
      '#default_value' => $current !== NULL ? AdminFormat::truthy($current->featured_by_bfep ?? NULL) : FALSE,
    ];
    if (isset($this->columns['internal_notes'])) {
      $form['internal_notes'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Internal notes'),
        '#description' => $this->t('Internal only. Never shown on the public site.'),
        '#default_value' => $current->internal_notes ?? '',
        '#rows' => 5,
        '#maxlength' => 10000,
      ];
    }
    if ($current !== NULL && isset($this->columns['updated_at'])) {
      // Compared on save so a second editor's changes are not overwritten.
      $form['loaded_updated_at'] = [
        '#type' => 'hidden',
        '#default_value' => (string) ($current->updated_at ?? ''),
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $current !== NULL ? $this->t('Save initiative') : $this->t('Add initiative'),
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Back to initiatives'),
      '#url' => $this->listUrl('bfep.admin_initiatives'),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($this->initiative !== NULL && isset($this->columns['updated_at'])) {
      $current = $this->database->query('SELECT updated_at FROM ground_initiatives WHERE id = :id', [':id' => $this->initiativeId])->fetchField();
      if ((string) ($current ?? '') !== (string) $form_state->getValue('loaded_updated_at')) {
        $form_state->setErrorByName('', $this->t('This initiative was changed by someone else after you opened it. <a href=":url">Reload it</a> to see the latest version, then make your changes again.', [
          ':url' => $this->initiativeUrl($this->initiativeId),
        ]));
      }
    }
    $prepared = [];
    foreach ($this->uploadedFiles() as $file) {
      $result = match (TRUE) {
        $file->isValid() => InitiativeMedia::prepareImage($file->getRealPath()),
        in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], TRUE) => 'The file is larger than this server accepts (' . ByteSizeMarkup::create($this->maxFileSize()) . ').',
        default => 'The upload did not finish.',
      };
      if (is_string($result)) {
        $form_state->setErrorByName('image_upload', $this->t('@name: @problem', ['@name' => $file->getClientOriginalName(), '@problem' => $result]));
      }
      else {
        $prepared[] = $result;
      }
    }
    $form_state->set('prepared_images', $prepared);
    foreach ((array) $form_state->getValue('links') as $index => $link) {
      $url = trim((string) ($link['url'] ?? ''));
      if ($url === '' && trim((string) ($link['label'] ?? '')) !== '') {
        $form_state->setErrorByName('links][' . $index . '][url', $this->t('Add the fundraising link, or clear its label.'));
      }
      elseif ($url !== '' && AdminFormat::externalUrl($url) === NULL) {
        $form_state->setErrorByName('links][' . $index . '][url', $this->t('Fundraising links must start with http:// or https://.'));
      }
    }
    $type = (string) $form_state->getValue('initiative_type');
    $labels = PgEnum::labels($this->database, 'ground_initiatives', 'initiative_type');
    if ($type !== '' && $labels !== NULL && !in_array($type, $labels, TRUE)) {
      $form_state->setErrorByName('initiative_type', $this->t('Choose one of the listed categories.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    $fields = [
      'contact_name' => trim((string) $values['contact_name']),
      'project_description' => $this->nullable($values['project_description'] ?? ''),
      'initiative_type' => $this->nullable($values['initiative_type'] ?? ''),
      'featured_by_bfep' => !empty($values['featured_by_bfep']) ? 'true' : 'false',
    ];
    $country = trim((string) ($values['country'] ?? ''));
    if (isset($this->columns['country_id'])) {
      $countryId = ctype_digit($country) ? (int) $country : 0;
      $name = $countryId > 0 ? $this->database->query('SELECT name FROM countries WHERE id = :id', [':id' => $countryId])->fetchField() : FALSE;
      $fields['country_id'] = $name !== FALSE ? $countryId : NULL;
      $fields['country_raw'] = $name !== FALSE ? (string) $name : NULL;
    }
    else {
      $fields['country_raw'] = $this->nullable($country);
    }
    if (isset($this->columns['internal_notes'])) {
      $fields['internal_notes'] = $this->nullable($values['internal_notes'] ?? '');
    }

    $changed = $this->initiative !== NULL ? AdminFormat::changedKeys((array) $this->initiative, $fields) : array_keys($fields);
    if (isset($this->columns['updated_at'])) {
      $fields['updated_at'] = date('c');
    }
    try {
      if ($this->initiative === NULL) {
        $placeholders = [];
        foreach ($fields as $column => $value) {
          $placeholders[':' . $column] = $value;
        }
        $id = (int) $this->database->query(
          'INSERT INTO ground_initiatives (' . implode(', ', array_keys($fields)) . ') VALUES (' . implode(', ', array_keys($placeholders)) . ') RETURNING id',
          $placeholders,
        )->fetchField();
      }
      else {
        $id = $this->initiativeId;
        $this->database->update('ground_initiatives')->fields($fields)->condition('id', $id)->execute();
      }
    }
    catch (\Throwable $exception) {
      $this->reportSaveFailure($form_state, $exception, $this->initiative === NULL ? 'new ground initiative' : 'ground initiative ' . $this->initiativeId);
      return;
    }
    if ($this->media->available()) {
      try {
        $existing = [];
        foreach ((array) ($values['images']['existing'] ?? []) as $imageId => $row) {
          $existing[(int) $imageId] = ['alt' => (string) ($row['alt'] ?? ''), 'remove' => !empty($row['remove']), 'order' => (int) ($row['order'] ?? 0)];
        }
        $this->media->saveImages($id, $existing, (array) $form_state->get('prepared_images'), $fields['contact_name']);
        $this->media->saveLinks($id, $this->submittedLinks($form_state));
      }
      catch (\Throwable $exception) {
        // The initiative row is saved; reopen it so photos and links can be retried.
        $code = (string) ($exception->getPrevious()?->getCode() ?: $exception->getCode());
        $this->logger('bfep')->error('Could not save photos or links of ground initiative @id: @class (code @code).', ['@id' => $id, '@class' => get_class($exception), '@code' => $code]);
        $this->messenger()->addError($this->t('The initiative was saved, but its photos or fundraising links were not (database code @code). Add them again below.', ['@code' => $code]));
        $this->cacheInvalidator->invalidateInitiatives();
        $form_state->setRedirectUrl(Url::fromRoute('bfep.admin_initiative_edit', ['initiative_id' => $id], ['query' => $this->backQuery()]));
        return;
      }
      $changed[] = 'photos and links';
    }

    $this->auditLogger->record($this->initiative === NULL ? 'created' : 'updated', 'ground initiative', $id, $changed);
    $this->cacheInvalidator->invalidateInitiatives();
    $this->messenger()->addStatus($this->initiative === NULL
      ? $this->t('Ground initiative <a href=":url">@name</a> added.', [':url' => $this->initiativeUrl($id), '@name' => $fields['contact_name']])
      : $this->t('Ground initiative <a href=":url">@name</a> saved.', [':url' => $this->initiativeUrl($id), '@name' => $fields['contact_name']]));
    if ($fields['featured_by_bfep'] === 'false') {
      $this->messenger()->addStatus($this->t('It is not featured, so it is not shown on the public page.'));
    }
    $form_state->setRedirectUrl($this->listUrl('bfep.admin_initiatives'));
  }

  /**
   * Current photos (alt text, order, remove) and an upload field.
   */
  private function imagesElement(): array {
    $existing = $this->initiative !== NULL ? ($this->media->imagesFor([$this->initiativeId])[$this->initiativeId] ?? []) : [];
    $element = [
      '#type' => 'details',
      '#title' => $this->t('Photos'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    if ($existing) {
      $element['existing'] = [
        '#type' => 'table',
        '#header' => [$this->t('Photo'), $this->t('Description for screen readers'), $this->t('Order'), $this->t('Remove')],
      ];
      $order = 0;
      foreach ($existing as $id => $image) {
        $url = Url::fromRoute('bfep.initiative_image', ['image' => $id . '.' . $image['ext']])->toString();
        $element['existing'][$id] = [
          'preview' => ['#markup' => '<img src="' . htmlspecialchars($url, ENT_QUOTES) . '" alt="" width="120" loading="lazy">'],
          'alt' => [
            '#type' => 'textfield',
            '#title' => $this->t('Description of photo @number', ['@number' => $order + 1]),
            '#title_display' => 'invisible',
            '#default_value' => $image['alt'],
            '#maxlength' => 500,
          ],
          'order' => [
            '#type' => 'number',
            '#title' => $this->t('Order of photo @number', ['@number' => $order + 1]),
            '#title_display' => 'invisible',
            '#default_value' => ++$order,
            '#min' => 1,
            '#size' => 3,
          ],
          'remove' => [
            '#type' => 'checkbox',
            '#title' => $this->t('Remove photo @number', ['@number' => $order]),
            '#title_display' => 'invisible',
          ],
        ];
      }
    }
    $element['upload'] = [
      '#type' => 'file',
      // File elements are read by their first parent, so keep it top level.
      '#parents' => ['image_upload'],
      '#title' => $existing ? $this->t('Add photos') : $this->t('Photos'),
      '#multiple' => TRUE,
      '#attributes' => [
        'accept' => 'image/jpeg,image/png,image/webp',
        // Read by js/initiative-photos.js, which shrinks photos before upload.
        'data-bfep-max-file' => (string) $this->maxFileSize(),
        'data-bfep-max-post' => (string) Bytes::toNumber((string) ini_get('post_max_size')),
      ],
      '#attached' => ['library' => ['bfep/initiative-photos']],
      '#description' => $this->t('JPG, PNG or WebP. Large phone photos are made smaller before they are sent; each must end up under @limit. Photos are resized to @max pixels on the long side and their location data is removed. The first photo is the main one on the public page. Only upload photos of people who agreed to be shown.', [
        '@max' => InitiativeMedia::MAX_EDGE,
        '@limit' => ByteSizeMarkup::create($this->maxFileSize()),
      ]),
    ];
    return $element;
  }

  /**
   * Fundraising link rows: the saved links plus two empty rows.
   */
  private function linksElement(): array {
    $saved = $this->initiative !== NULL ? ($this->media->linksFor([$this->initiativeId])[$this->initiativeId] ?? []) : [];
    $rows = [...$saved, ['label' => '', 'url' => ''], ['label' => '', 'url' => '']];
    $element = [
      '#type' => 'details',
      '#title' => $this->t('Fundraising links'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#description' => $this->t('Shown as donate buttons on the public page. Clear a row to remove it. Save to get more empty rows.'),
    ];
    foreach ($rows as $index => $link) {
      $element[$index] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['container-inline', 'bfep-admin-link-row']],
        'label' => [
          '#type' => 'textfield',
          '#title' => $this->t('Button text @number', ['@number' => $index + 1]),
          '#default_value' => $link['label'],
          '#maxlength' => 255,
          '#size' => 30,
          '#placeholder' => $this->t('Donate on GoFundMe'),
        ],
        'url' => [
          '#type' => 'url',
          '#title' => $this->t('Link @number', ['@number' => $index + 1]),
          '#default_value' => $link['url'],
          '#maxlength' => 2048,
          '#size' => 50,
        ],
      ];
    }
    return $element;
  }

  /**
   * Explains a save that PHP threw away for being too large.
   *
   * When a request is bigger than post_max_size, PHP drops all of it, so the
   * form would otherwise reappear empty with no message. That happens when
   * photos are sent without the browser shrinking them first.
   */
  private function warnIfPostWasDropped(): void {
    $request = $this->getRequest();
    $limit = Bytes::toNumber((string) ini_get('post_max_size'));
    $length = (int) $request->server->get('CONTENT_LENGTH', 0);
    if ($request->isMethod('POST') && $limit > 0 && $length > $limit && !$request->request->count()) {
      $this->messenger()->addError($this->t('Nothing was saved: the photos added up to @size, more than this server accepts in one save (@limit). Add fewer or smaller photos at a time.', [
        '@size' => ByteSizeMarkup::create($length),
        '@limit' => ByteSizeMarkup::create((int) $limit),
      ]));
    }
  }

  /**
   * The largest photo this server accepts, in bytes.
   */
  private function maxFileSize(): int {
    return (int) min(InitiativeMedia::MAX_UPLOAD, Environment::getUploadMaxSize());
  }

  /**
   * The photos chosen in the upload field.
   *
   * @return \Symfony\Component\HttpFoundation\File\UploadedFile[]
   *   Uploaded files; empty when none were chosen.
   */
  private function uploadedFiles(): array {
    $files = $this->getRequest()->files->all()['files']['image_upload'] ?? [];
    return array_values(array_filter(is_array($files) ? $files : [$files], static fn($file): bool => $file instanceof UploadedFile));
  }

  /**
   * The non-empty fundraising links from the form.
   *
   * @return array<int, array{label: string, url: string}>
   *   Links in form order.
   */
  private function submittedLinks(FormStateInterface $form_state): array {
    $links = [];
    foreach ((array) $form_state->getValue('links') as $link) {
      $url = trim((string) ($link['url'] ?? ''));
      if ($url !== '') {
        $links[] = ['label' => trim((string) ($link['label'] ?? '')), 'url' => $url];
      }
    }
    return $links;
  }

  /**
   * The country select: countries from bfdb, plus a stored value not in it.
   */
  private function countryElement(?object $current): array {
    $byId = isset($this->columns['country_id']);
    $options = [];
    try {
      foreach ($this->database->query('SELECT id, name FROM countries ORDER BY name')->fetchAll() as $row) {
        $options[$byId ? (string) $row->id : (string) $row->name] = (string) $row->name;
      }
    }
    catch (\Throwable) {
      // No readable countries table; the stored value is still offered.
    }
    $raw = trim((string) ($current->country_raw ?? ''));
    $default = $byId ? (string) ($current->country_id ?? '') : $raw;
    if (!$byId && $raw !== '' && !isset($options[$raw])) {
      $options = [$raw => $raw] + $options;
    }
    return [
      '#type' => 'select',
      '#title' => $this->t('Country'),
      '#options' => $options,
      '#empty_option' => $this->t('- None -'),
      '#default_value' => isset($options[$default]) ? $default : '',
      '#description' => $byId && $raw !== '' && !isset($options[$default])
        ? $this->t('Currently stored as "@raw", which is not in the country list.', ['@raw' => $raw])
        : '',
    ];
  }

  /**
   * The type: a select of the enum labels, or free text when not an enum.
   */
  private function typeElement(?object $current): array {
    $value = trim((string) ($current->initiative_type ?? ''));
    $labels = PgEnum::labels($this->database, 'ground_initiatives', 'initiative_type');
    if ($labels !== NULL) {
      $options = array_combine($labels, $labels);
      return [
        '#type' => 'select',
        '#title' => $this->t('Category'),
        '#options' => $options,
        '#empty_option' => $this->t('- None -'),
        '#default_value' => isset($options[$value]) ? $value : '',
      ];
    }
    $existing = [];
    try {
      $existing = $this->database->query("
        SELECT DISTINCT CAST(initiative_type AS TEXT) FROM ground_initiatives
        WHERE initiative_type IS NOT NULL AND TRIM(CAST(initiative_type AS TEXT)) <> ''
        ORDER BY 1 LIMIT 30
      ")->fetchCol();
    }
    catch (\Throwable) {
      // Suggestions are optional.
    }
    return [
      '#type' => 'textfield',
      '#title' => $this->t('Category'),
      '#default_value' => $value,
      '#maxlength' => 255,
      '#description' => $existing ? $this->t('Categories already in use: @types. For example: Water, Street fundraising.', ['@types' => implode(', ', $existing)]) : '',
    ];
  }

  /**
   * Which optional columns bfdb's ground_initiatives has.
   *
   * @return array<string, true>
   *   Present columns keyed by name.
   */
  private function optionalColumns(): array {
    try {
      $names = $this->database->query("
        SELECT a.attname
        FROM pg_attribute a
        WHERE a.attrelid = to_regclass('ground_initiatives') AND a.attnum > 0 AND NOT a.attisdropped
          AND a.attname IN ('country_id', 'internal_notes', 'updated_at')
      ")->fetchCol();
    }
    catch (\Throwable) {
      $names = [];
    }
    return array_fill_keys(array_values(array_intersect(self::OPTIONAL_COLUMNS, $names)), TRUE);
  }

  private function initiativeUrl(int $id): string {
    return Url::fromRoute('bfep.admin_initiative_edit', ['initiative_id' => $id], ['query' => $this->backQuery()])->toString();
  }

  private function nullable(mixed $value): ?string {
    $value = trim((string) $value);
    return $value === '' ? NULL : $value;
  }

}
