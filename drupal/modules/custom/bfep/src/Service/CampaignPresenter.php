<?php

declare(strict_types=1);

namespace Drupal\bfep\Service;

use Drupal\Component\Utility\Unicode;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Datetime\DateFormatterInterface;

/**
 * Converts database records into safe, template-ready public view models.
 */
final class CampaignPresenter {

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public function card(object $row, string $detailUrl): array {
    return [
      'id' => (int) $row->id,
      'name' => trim((string) ($row->contact_name ?: 'Campaign #' . $row->id)),
      'detail_url' => $detailUrl,
      'line_number' => trim((string) ($row->line_number ?? '')),
      'badges' => $this->badges($row),
      'description' => $this->excerpt((string) ($row->description ?? '')),
      'amounts' => $this->amounts($row),
      'progress' => $this->progress($row),
      'fundraiser_url' => $this->externalUrl($row->fundraiser_url ?? NULL),
      'tags' => $this->tags($row->tags ?? NULL),
      'updated' => $this->date($row->updated_at ?? $row->created_at ?? NULL),
    ];
  }

  public function detail(object $row): array {
    $metadata = [];
    foreach ([
      'Line number' => $row->line_number ?? NULL,
      'Country' => $row->country ?? NULL,
      'Region' => $row->global_region ?? NULL,
      'Platform' => $row->platform ?? NULL,
      'Career/background' => $row->career ?? NULL,
    ] as $label => $value) {
      if (trim((string) ($value ?? '')) !== '') {
        $metadata[] = ['label' => $label, 'value' => trim((string) $value)];
      }
    }
    if ($this->truthy($row->featured_by_bfep ?? NULL)) {
      $metadata[] = ['label' => 'Status', 'value' => 'Featured by BFEP'];
    }
    if ($this->truthy($row->urgent_medical_needs ?? NULL)) {
      $metadata[] = ['label' => 'Needs', 'value' => 'Urgent medical needs'];
    }

    return [
      'id' => (int) $row->id,
      'name' => trim((string) ($row->contact_name ?: 'Campaign #' . $row->id)),
      'country' => trim((string) ($row->country ?? '')),
      'metadata' => $metadata,
      'amounts' => $this->amounts($row),
      'progress' => $this->progress($row),
      'tags' => $this->tags($row->tags ?? NULL),
      'fundraiser_url' => $this->externalUrl($row->fundraiser_url ?? NULL),
      'description' => trim((string) ($row->description ?? '')),
      'paragraphs' => $this->paragraphs((string) ($row->description ?? '')),
      'updated' => $this->date($row->updated_at ?? $row->created_at ?? NULL),
    ];
  }

  public function cleanDescription(string $description, int $length = 160): string {
    $description = trim((string) preg_replace('/\s+/u', ' ', strip_tags($description)));
    return Unicode::truncate($description, $length, TRUE, TRUE);
  }

  /**
   * Splits plain text into paragraphs on blank lines.
   *
   * Single line breaks stay inside a paragraph; the template shows them.
   *
   * @return string[]
   *   Non-empty paragraphs, in order.
   */
  public function paragraphs(string $text): array {
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    if ($text === '') {
      return [];
    }
    $paragraphs = preg_split('/\n[ \t]*\n\s*/u', $text) ?: [];
    return array_values(array_filter(array_map(
      static fn(string $paragraph): string => trim((string) preg_replace('/[ \t]+\n/u', "\n", $paragraph)),
      $paragraphs,
    ), static fn(string $paragraph): bool => $paragraph !== ''));
  }

  private function excerpt(string $description): string {
    return $this->cleanDescription($description, 260);
  }

  private function badges(object $row): array {
    $badges = [];
    if (trim((string) ($row->country ?? '')) !== '') {
      $badges[] = (string) $row->country;
    }
    if ($this->truthy($row->featured_by_bfep ?? NULL)) {
      $badges[] = 'Featured';
    }
    if ($this->truthy($row->urgent_medical_needs ?? NULL)) {
      $badges[] = 'Urgent medical needs';
    }
    if (trim((string) ($row->platform ?? '')) !== '') {
      $badges[] = (string) $row->platform;
    }
    return $badges;
  }

  private function amounts(object $row): array {
    $currency = trim((string) ($row->currency_code ?? ''));
    $amounts = [];
    if (($row->donated_amount ?? NULL) !== NULL) {
      $amounts[] = [
        'label' => 'Raised',
        'value' => trim($currency . ' ' . number_format((float) $row->donated_amount)),
      ];
    }
    if (($row->goal_amount ?? NULL) !== NULL) {
      $amounts[] = [
        'label' => 'Goal',
        'value' => trim($currency . ' ' . number_format((float) $row->goal_amount)),
      ];
    }
    if (($row->pct_goal_achieved ?? NULL) !== NULL) {
      $amounts[] = [
        'label' => 'Progress',
        'value' => round((float) $row->pct_goal_achieved, 1) . '% funded',
      ];
    }
    return $amounts;
  }

  /**
   * Funding progress for the progress bar, capped at 100.
   *
   * @return float|null
   *   Percent funded (0 to 100), or NULL when it is unknown.
   */
  public function progress(object $row): ?float {
    $percent = $row->pct_goal_achieved ?? NULL;
    if ($percent === NULL || !is_numeric($percent)) {
      $goal = $row->goal_amount ?? NULL;
      $raised = $row->donated_amount ?? NULL;
      if (!is_numeric($goal) || !is_numeric($raised) || (float) $goal <= 0) {
        return NULL;
      }
      $percent = (float) $raised / (float) $goal * 100;
    }
    return round(max(0.0, min(100.0, (float) $percent)), 1);
  }

  private function externalUrl(mixed $value): ?string {
    $url = trim((string) ($value ?? ''));
    if ($url === '') {
      return NULL;
    }
    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) && !preg_match('@^https?://@i', $url)) {
      return NULL;
    }
    if (!preg_match('@^https?://@i', $url)) {
      $url = 'https://' . ltrim($url, '/');
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    $host = (string) parse_url($url, PHP_URL_HOST);
    if (!in_array($scheme, ['http', 'https'], TRUE) || $host === '' || !UrlHelper::isValid($url, TRUE)) {
      return NULL;
    }
    return $url;
  }

  private function tags(mixed $tags): array {
    if (is_array($tags)) {
      return array_values(array_filter(array_map('trim', $tags)));
    }
    $tags = trim((string) ($tags ?? ''), '{}');
    if ($tags === '') {
      return [];
    }
    return array_values(array_filter(array_map(
      static fn(string $tag): string => trim($tag, "\" "),
      str_getcsv($tags, ',', '"', '\\'),
    )));
  }

  private function date(mixed $value): string {
    $timestamp = $value ? strtotime((string) $value) : FALSE;
    return $timestamp ? $this->dateFormatter->format($timestamp, 'custom', 'j F Y') : '';
  }

  private function truthy(mixed $value): bool {
    return $value === TRUE || $value === 1 || $value === '1'
      || $value === 't' || $value === 'true';
  }

}
