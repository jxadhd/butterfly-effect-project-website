<?php

declare(strict_types=1);

namespace Drupal\bfep\Service;

use Drupal\Component\Utility\Unicode;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Converts database records into safe, template-ready public view models.
 *
 * Fixed labels are translated into the page's language and returned as plain
 * strings. Names, descriptions and other text staff type into bfdb are passed
 * through as entered, except country and region names, which are looked up in
 * the interface translations (see placeName()).
 */
final class CampaignPresenter {

  use StringTranslationTrait;

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public function card(object $row, string $detailUrl): array {
    return [
      'id' => (int) $row->id,
      'name' => $this->campaignName($row),
      'detail_url' => $detailUrl,
      'line_number' => trim((string) ($row->line_number ?? '')),
      'badges' => $this->badges($row),
      'description' => $this->excerpt((string) ($row->description ?? '')),
      'amounts' => $this->amounts($row),
      'progress' => $this->progress($row),
      'fundraiser_url' => $this->externalUrl($row->fundraiser_url ?? NULL),
      'more_fundraisers' => max(0, (int) ($row->fundraiser_count ?? 0) - 1),
      'tags' => $this->tagNames($row->tags ?? NULL),
      'updated' => $this->date($row->updated_at ?? $row->created_at ?? NULL),
    ];
  }

  /**
   * Builds the campaign page view model.
   *
   * @param object $row
   *   A row from CampaignRepository::find().
   * @param string|null $figuresUpdatedAt
   *   When the sync service last refreshed the amounts, if it does.
   * @param object[] $fundraisers
   *   The campaign's active fundraisers from
   *   CampaignRepository::fundraisers(), newest first. Listed one by one
   *   when there is more than one.
   */
  public function detail(object $row, ?string $figuresUpdatedAt = NULL, array $fundraisers = []): array {
    $platforms = $this->platforms($row);
    $metadata = [];
    foreach ([
      [(string) $this->t('Line number'), $row->line_number ?? NULL],
      [(string) $this->t('Country'), $this->placeName($row->country ?? NULL, 'Country name')],
      [(string) $this->t('Region'), $this->placeName($row->global_region ?? NULL, 'World region')],
      [(string) (count($platforms) > 1 ? $this->t('Platforms') : $this->t('Platform')), implode(', ', $platforms)],
      [(string) $this->t('Career/background'), $row->career ?? NULL],
    ] as [$label, $value]) {
      if (trim((string) ($value ?? '')) !== '') {
        $metadata[] = ['label' => $label, 'value' => trim((string) $value)];
      }
    }
    if ($this->truthy($row->featured_by_bfep ?? NULL)) {
      $metadata[] = ['label' => (string) $this->t('Status'), 'value' => (string) $this->t('Featured by BFEP')];
    }
    if ($this->truthy($row->urgent_medical_needs ?? NULL)) {
      $metadata[] = ['label' => (string) $this->t('Needs'), 'value' => (string) $this->t('Urgent medical needs')];
    }

    return [
      'id' => (int) $row->id,
      'name' => $this->campaignName($row),
      'country' => $this->placeName($row->country ?? NULL, 'Country name'),
      'metadata' => $metadata,
      'amounts' => $this->amounts($row),
      'progress' => $this->progress($row),
      'tags' => $this->tagNames($row->tags ?? NULL),
      'fundraiser_url' => $this->externalUrl($row->fundraiser_url ?? NULL),
      'fundraisers' => count($fundraisers) > 1 ? $this->fundraiserList($fundraisers) : [],
      'description' => trim((string) ($row->description ?? '')),
      'paragraphs' => $this->paragraphs((string) ($row->description ?? '')),
      'updated' => $this->date($row->updated_at ?? $row->created_at ?? NULL),
      'figures_updated' => $this->date($figuresUpdatedAt),
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

  /**
   * The campaign's name, or a numbered placeholder when it has none.
   */
  public function campaignName(object $row): string {
    $name = trim((string) ($row->contact_name ?? ''));
    return $name !== '' ? $name : (string) $this->t('Campaign #@id', ['@id' => (int) $row->id]);
  }

  /**
   * A country, region, tag or type name from bfdb in the page's language.
   *
   * The bfdb database stores English names. They are looked up as interface strings
   * with a context, so a name nobody has translated yet stays in English.
   * The result is a plain string, so templates still escape it.
   *
   * @param mixed $name
   *   The English name as stored in bfdb.
   * @param string $context
   *   'Country name', 'World region', 'Campaign tag' or 'Initiative type'.
   */
  public function placeName(mixed $name, string $context = 'Country name'): string {
    $name = trim((string) ($name ?? ''));
    if ($name === '') {
      return '';
    }
    // The name is data, not a source string with placeholders, so it is
    // looked up without arguments and returned as plain text.
    // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
    return (string) $this->t($name, [], ['context' => $context]);
  }

  private function excerpt(string $description): string {
    return $this->cleanDescription($description, 260);
  }

  private function badges(object $row): array {
    $badges = [];
    if (($country = $this->placeName($row->country ?? NULL)) !== '') {
      $badges[] = $country;
    }
    if ($this->truthy($row->featured_by_bfep ?? NULL)) {
      $badges[] = (string) $this->t('Featured');
    }
    if ($this->truthy($row->urgent_medical_needs ?? NULL)) {
      $badges[] = (string) $this->t('Urgent medical needs');
    }
    foreach ($this->platforms($row) as $platform) {
      $badges[] = $platform;
    }
    return $badges;
  }

  /**
   * The platforms of the campaign's active fundraisers, newest first.
   *
   * @return string[]
   *   Platform names without duplicates.
   */
  private function platforms(object $row): array {
    $platforms = [trim((string) ($row->platform ?? ''))];
    $all = $row->platforms ?? NULL;
    $platforms = array_merge($platforms, is_array($all) ? array_map('trim', $all) : $this->tags($all));
    return array_values(array_unique(array_filter($platforms, static fn(string $name): bool => $name !== '')));
  }

  /**
   * View models for each of a campaign's active fundraisers.
   *
   * @param object[] $fundraisers
   *   Rows from CampaignRepository::fundraisers().
   *
   * @return array<int, array{platform: string, url: string|null, amounts: array, progress: float|null, figures_updated: string}>
   *   One entry per fundraiser, in the given order.
   */
  public function fundraiserList(array $fundraisers): array {
    $list = [];
    foreach ($fundraisers as $fundraiser) {
      $list[] = [
        'platform' => trim((string) ($fundraiser->platform ?? '')),
        'url' => $this->externalUrl($fundraiser->url ?? NULL),
        'amounts' => $this->amounts($fundraiser),
        'progress' => $this->progress($fundraiser),
        'figures_updated' => $this->date($fundraiser->figures_updated_at ?? NULL),
      ];
    }
    return $list;
  }

  private function amounts(object $row): array {
    $byCurrency = $this->currencyTotals($row->currency_totals ?? NULL);
    if ($byCurrency) {
      // Different currencies are listed side by side, never added up.
      $amounts = [];
      foreach (['raised' => (string) $this->t('Raised'), 'goal' => (string) $this->t('Goal')] as $key => $label) {
        $parts = [];
        foreach ($byCurrency as $total) {
          if (is_numeric($total[$key] ?? NULL)) {
            $parts[] = trim(($total['currency'] ?? '') . ' ' . number_format((float) $total[$key]));
          }
        }
        if ($parts) {
          $amounts[] = ['label' => $label, 'value' => implode(' + ', $parts)];
        }
      }
      return $amounts;
    }
    $currency = trim((string) ($row->currency_code ?? ''));
    $amounts = [];
    if (($row->donated_amount ?? NULL) !== NULL) {
      $amounts[] = [
        'label' => (string) $this->t('Raised'),
        'value' => trim($currency . ' ' . number_format((float) $row->donated_amount)),
      ];
    }
    if (($row->goal_amount ?? NULL) !== NULL) {
      $amounts[] = [
        'label' => (string) $this->t('Goal'),
        'value' => trim($currency . ' ' . number_format((float) $row->goal_amount)),
      ];
    }
    if (($row->pct_goal_achieved ?? NULL) !== NULL) {
      $amounts[] = [
        'label' => (string) $this->t('Progress'),
        'value' => (string) $this->t('@percent% funded', ['@percent' => round((float) $row->pct_goal_achieved, 1)]),
      ];
    }
    return $amounts;
  }

  /**
   * Decodes the per-currency totals of a campaign with mixed currencies.
   *
   * @return array<int, array{currency: string|null, raised: mixed, goal: mixed}>
   *   One entry per currency, or an empty array.
   */
  private function currencyTotals(mixed $json): array {
    if (is_string($json) && $json !== '') {
      $json = json_decode($json, TRUE);
    }
    if (!is_array($json) || count($json) < 2) {
      return [];
    }
    return array_values(array_filter($json, 'is_array'));
  }

  /**
   * Builds an RSS 2.0 document for recently added campaigns.
   *
   * @param object[] $rows
   *   Rows from CampaignRepository::recent().
   * @param string $title
   *   Channel title.
   * @param string $siteUrl
   *   Channel link (the campaigns page).
   * @param string $feedUrl
   *   The feed's own absolute URL.
   * @param string $description
   *   Channel description.
   * @param callable $campaignUrl
   *   Returns the absolute URL for a campaign ID.
   *
   * @return string
   *   The RSS XML.
   */
  public function rss(array $rows, string $title, string $siteUrl, string $feedUrl, string $description, callable $campaignUrl): string {
    $x = static fn(string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $items = '';
    $latest = NULL;
    foreach ($rows as $row) {
      $name = $this->campaignName($row);
      $country = trim((string) ($row->country ?? ''));
      $link = $campaignUrl((int) $row->id);
      $summary = [];
      if (($excerpt = $this->cleanDescription((string) ($row->description ?? ''), 400)) !== '') {
        $summary[] = $excerpt;
      }
      foreach ($this->amounts($row) as $amount) {
        $summary[] = $amount['label'] . ': ' . $amount['value'];
      }
      $categories = '';
      foreach (array_filter([$country, $this->truthy($row->urgent_medical_needs ?? NULL) ? 'Urgent medical needs' : '', $this->truthy($row->featured_by_bfep ?? NULL) ? 'Featured' : '']) as $category) {
        $categories .= '<category>' . $x($category) . '</category>';
      }
      $created = !empty($row->created_at) ? strtotime((string) $row->created_at) : FALSE;
      if ($created && ($latest === NULL || $created > $latest)) {
        $latest = $created;
      }
      $items .= '<item>'
        . '<title>' . $x($name . ($country !== '' ? ' – ' . $country : '')) . '</title>'
        . '<link>' . $x($link) . '</link>'
        . '<guid isPermaLink="true">' . $x($link) . '</guid>'
        . ($created ? '<pubDate>' . gmdate(DATE_RSS, $created) . '</pubDate>' : '')
        . $categories
        . '<description>' . $x(implode(' · ', $summary)) . '</description>'
        . '</item>' . "\n";
    }
    return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
      . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>' . "\n"
      . '<title>' . $x($title) . '</title>'
      . '<link>' . $x($siteUrl) . '</link>'
      . '<description>' . $x($description) . '</description>'
      . '<language>en</language>'
      . '<atom:link href="' . $x($feedUrl) . '" rel="self" type="application/rss+xml"/>'
      . ($latest !== NULL ? '<lastBuildDate>' . gmdate(DATE_RSS, $latest) . '</lastBuildDate>' : '') . "\n"
      . $items
      . '</channel></rss>' . "\n";
  }

  /**
   * Share links for a public campaign URL.
   *
   * Plain links to each service's own share page, so no third-party script
   * is loaded and nothing is sent anywhere until the visitor clicks.
   *
   * @return array<int, array{label: string, url: string}>
   *   Links in display order.
   */
  public function shareLinks(string $url, string $text): array {
    $u = rawurlencode($url);
    $t = rawurlencode($text);
    return [
      ['label' => 'WhatsApp', 'url' => 'https://wa.me/?text=' . rawurlencode($text . ' ' . $url)],
      ['label' => 'Telegram', 'url' => 'https://t.me/share/url?url=' . $u . '&text=' . $t],
      ['label' => 'Facebook', 'url' => 'https://www.facebook.com/sharer/sharer.php?u=' . $u],
      ['label' => 'X', 'url' => 'https://x.com/intent/post?url=' . $u . '&text=' . $t],
      ['label' => (string) $this->t('Email'), 'url' => 'mailto:?subject=' . $t . '&body=' . rawurlencode($text . "\n\n" . $url)],
    ];
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

  /**
   * A campaign's tags as visitors read them, in the page's language.
   *
   * @return string[]
   *   Tag names, translated where a translation exists.
   */
  private function tagNames(mixed $tags): array {
    return array_map(fn(string $tag): string => $this->placeName($tag, 'Campaign tag'), $this->tags($tags));
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
