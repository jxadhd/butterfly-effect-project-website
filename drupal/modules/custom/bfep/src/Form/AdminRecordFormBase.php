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
 * Provides read-only display items, safe external links, and the services
 * every staff form needs.
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
   * Adds the standard save and back actions.
   */
  protected function addActions(array &$form, string $saveLabel, string $backLabel, string $backRoute): void {
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $saveLabel,
      '#button_type' => 'primary',
    ];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $backLabel,
      '#url' => Url::fromRoute($backRoute),
      '#attributes' => ['class' => ['button']],
    ];
  }

  /**
   * Redirects back to the saved record.
   */
  protected function redirectAfterSave(FormStateInterface $form_state, int $currentId, string $listRoute): void {
    [$route, $parameters] = $this->reviewRoute($currentId);
    $form_state->setRedirect($route, $parameters);
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
