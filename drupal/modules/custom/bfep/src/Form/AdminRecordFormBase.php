<?php

declare(strict_types=1);

namespace Drupal\bfep\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\bfep\Admin\AdminFormat;
use Drupal\bfep\Service\AuditLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shared plumbing for the staff review forms.
 *
 * Provides read-only display items, safe external links, the services every
 * staff form needs, and a "save and review next" action that walks the
 * pending queue oldest first.
 */
abstract class AdminRecordFormBase extends FormBase {

  public function __construct(
    protected Connection $database,
    protected AuditLogger $auditLogger,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bfep.database'),
      $container->get('bfep.audit_logger'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Returns the ID of the oldest other pending record, if any.
   */
  abstract protected function nextPendingId(int $currentId): ?int;

  /**
   * Returns the review route for one record of this form's type.
   *
   * @return array{0: string, 1: array}
   *   The route name and its parameters.
   */
  abstract protected function reviewRoute(int $id): array;

  protected function bfdb(): Connection {
    return $this->database;
  }

  /**
   * A read-only labelled value; plain text with line breaks preserved.
   */
  protected function item(string $title, mixed $value): array {
    $text = trim((string) ($value ?? ''));
    return [
      '#type' => 'item',
      '#title' => $title,
      '#markup' => $text === '' ? '<em>' . $this->t('Not provided') . '</em>' : nl2br(Html::escape($text)),
    ];
  }

  /**
   * A read-only date, formatted in the site timezone.
   */
  protected function dateItem(string $title, mixed $value): array {
    return $this->item($title, $this->formatDate($value));
  }

  protected function formatDate(mixed $value): string {
    $timestamp = !AdminFormat::isBlank($value) ? strtotime((string) $value) : FALSE;
    return $timestamp ? $this->dateFormatter->format($timestamp, 'custom', 'j M Y, H:i') : '';
  }

  /**
   * A button-styled link to a submitted URL, or NULL when it is not http(s).
   */
  protected function externalLink(mixed $url, string $title): ?array {
    $url = AdminFormat::externalUrl($url);
    if ($url === NULL) {
      return NULL;
    }
    return [
      '#type' => 'link',
      '#title' => $title,
      '#url' => Url::fromUri($url),
      '#attributes' => ['class' => ['button'], 'target' => '_blank', 'rel' => 'noopener noreferrer'],
    ];
  }

  /**
   * Adds the standard save, save-and-next and back actions.
   */
  protected function addActions(array &$form, string $saveLabel, string $backLabel, string $backRoute): void {
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $saveLabel,
      '#button_type' => 'primary',
    ];
    $form['actions']['submit_next'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save and review next pending'),
      '#bfep_next' => TRUE,
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $backLabel,
      '#url' => Url::fromRoute($backRoute),
      '#attributes' => ['class' => ['button']],
    ];
  }

  /**
   * Redirects to the next pending record or back to the saved one.
   */
  protected function redirectAfterSave(FormStateInterface $form_state, int $currentId, string $listRoute): void {
    $trigger = $form_state->getTriggeringElement();
    if (!empty($trigger['#bfep_next'])) {
      $next = $this->nextPendingId($currentId);
      if ($next !== NULL) {
        [$route, $parameters] = $this->reviewRoute($next);
        $form_state->setRedirect($route, $parameters);
        return;
      }
      $this->messenger()->addStatus($this->t('There are no other pending records in this queue.'));
      $form_state->setRedirect($listRoute);
      return;
    }
    [$route, $parameters] = $this->reviewRoute($currentId);
    $form_state->setRedirect($route, $parameters);
  }

  /**
   * SQL that reduces a URL column to host and path for duplicate matching.
   *
   * Drops the scheme, a leading "www.", any query or fragment and trailing
   * slashes, and lower-cases the rest. Mirrors AdminFormat::urlMatchKey().
   */
  protected function urlKeySql(string $column): string {
    return AdminFormat::urlKeySql($column);
  }

  /**
   * Lists active campaigns whose current fundraiser URL matches $url.
   *
   * @return array<int, array{id: int, label: string}>
   *   Matching campaigns keyed by ID.
   */
  protected function campaignsWithFundraiserUrl(mixed $url): array {
    $key = AdminFormat::urlMatchKey($url);
    if ($key === '') {
      return [];
    }
    $rows = $this->optionalRows("
      SELECT DISTINCT c.id, c.line_number, c.contact_name
      FROM campaign_fundraisers cf
      INNER JOIN campaigns c ON c.id = cf.campaign_id AND c.deleted_at IS NULL
      WHERE cf.is_active AND " . $this->urlKeySql('cf.url') . " = :key
      ORDER BY c.id
      LIMIT 10
    ", [':key' => $key]);
    $campaigns = [];
    foreach ($rows as $row) {
      $label = trim((string) ($row->contact_name ?: 'Campaign #' . $row->id));
      if (!empty($row->line_number)) {
        $label .= ' (line ' . $row->line_number . ')';
      }
      $campaigns[(int) $row->id] = ['id' => (int) $row->id, 'label' => $label];
    }
    return $campaigns;
  }

  /**
   * Renders a list of links to related records, or a note when there are none.
   *
   * @param string $title
   *   The list heading.
   * @param array<int, array{label: string, url: \Drupal\Core\Url}> $links
   *   The related records.
   * @param string $empty
   *   Text shown when $links is empty.
   */
  protected function relatedList(string $title, array $links, string $empty): array {
    $items = [];
    foreach ($links as $link) {
      $items[] = ['#type' => 'link', '#title' => $link['label'], '#url' => $link['url']];
    }
    return $items
      ? ['#theme' => 'item_list', '#title' => $title, '#items' => $items]
      : $this->item($title, $empty);
  }

  /**
   * Runs a lookup query, returning an empty list if the query fails.
   *
   * Context panels are helpful but optional; a missing column or a slow table
   * must not stop staff from reviewing the record itself.
   */
  protected function optionalRows(string $sql, array $params): array {
    try {
      return $this->bfdb()->query($sql, $params)->fetchAll();
    }
    catch (\Throwable $exception) {
      $this->logger('bfep')->warning('Optional BFEP review lookup failed: @message', ['@message' => $exception->getMessage()]);
      return [];
    }
  }

}
