<?php

namespace Drupal\bfep\Plugin\Search;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\bfep\Admin\AdminFormat;
use Drupal\bfep\OptionalBfdb;
use Drupal\bfep\Service\BfepCacheInvalidator;
use Drupal\bfep\Service\BfepSettings;
use Drupal\search\Attribute\Search;
use Drupal\search\Plugin\SearchPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Searches the external BFEP PostgreSQL database through Drupal core Search.
 *
 * Public users only receive records that are already intended for public use.
 * Staff with the BFEP administration permission also receive private workflow
 * records such as referrals, volunteer applications, and change requests.
 */
#[Search(
  id: 'bfep_database',
  title: new TranslatableMarkup('Butterfly database'),
)]
final class BfepDatabaseSearch extends SearchPluginBase {

  /**
   * Maximum number of result rows pulled from each large source table.
   */
  protected const SOURCE_LIMIT = 60;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ?Connection $database,
    protected AccountProxyInterface $currentUser,
    protected BfepSettings $settings,
    protected LoggerChannelInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      // Menus check access to the search page on every page, which builds
      // this plugin, so it must not need bfdb just to exist.
      OptionalBfdb::get($container),
      $container->get('current_user'),
      $container->get('bfep.settings'),
      $container->get('logger.channel.bfep'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function execute(): array {
    if (!$this->isSearchExecutable()) {
      return [];
    }
    if ($this->database === NULL) {
      $this->logger->warning('BFEP database search skipped: bfdb is unavailable.');
      $this->mergeCacheMaxAge(0);
      return [];
    }

    $this->addCacheContexts(['user.permissions']);

    $phrase = trim((string) $this->getKeywords());
    $tokens = $this->tokens($phrase);

    if (!$tokens) {
      return [];
    }

    $is_staff = $this->currentUser->hasPermission('administer bfep external data');
    if ($is_staff) {
      // Private workflow results must never persist in shared render caches.
      $this->mergeCacheMaxAge(0);
    }
    else {
      $this->mergeCacheMaxAge($this->settings->searchCacheMaxAge());
      $this->addCacheTags([
        BfepCacheInvalidator::CAMPAIGNS,
        BfepCacheInvalidator::COUNTRIES,
        BfepCacheInvalidator::INITIATIVES,
      ]);
    }
    $results = [];

    $results = array_merge($results, $this->campaignResults($tokens, $phrase, $is_staff));
    $results = array_merge($results, $this->countryResults($tokens, $phrase));
    $results = array_merge($results, $this->initiativeResults($tokens, $phrase, $is_staff));

    if ($is_staff) {
      $results = array_merge($results, $this->referralResults($tokens, $phrase));
      $results = array_merge($results, $this->volunteerResults($tokens, $phrase));
      $results = array_merge($results, $this->changeRequestResults($tokens, $phrase));
    }

    usort($results, static function (array $a, array $b): int {
      $score = ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0);
      if ($score !== 0) {
        return $score;
      }
      return strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''));
    });

    // Keep the global results page useful without overwhelming it.
    $results = array_slice($results, 0, 120);

    foreach ($results as &$result) {
      unset($result['_score']);
    }

    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function getHelp(): array {
    return [
      '#markup' => '<p>' . $this->t('Searches Butterfly campaign, country, and initiative data. Authorised BFEP staff also see matching referrals, volunteer applications, and change requests.') . '</p>',
    ];
  }

  /**
   * Converts a user query into a short list of ANDed search terms.
   */
  protected function tokens(string $keywords): array {
    $parts = preg_split('/[\s,;]+/u', trim($keywords)) ?: [];
    $parts = array_values(array_unique(array_filter(array_map(static function ($value): string {
      return trim((string) $value, " \t\n\r\0\x0B\"'");
    }, $parts), static fn(string $value): bool => $value !== '')));

    return array_slice($parts, 0, 8);
  }

  /**
   * Builds an AND-of-OR ILIKE clause for a fixed set of SQL expressions.
   */
  protected function buildWhere(array $expressions, array $tokens, string $prefix, array &$params): string {
    $groups = [];

    foreach ($tokens as $token_index => $token) {
      $parts = [];
      foreach ($expressions as $column_index => $expression) {
        $key = ':' . $prefix . '_' . $token_index . '_' . $column_index;
        $parts[] = $expression . ' ILIKE ' . $key;
        $params[$key] = '%' . $this->database->escapeLike($token) . '%';
      }
      $groups[] = '(' . implode(' OR ', $parts) . ')';
    }

    return $groups ? '(' . implode(' AND ', $groups) . ')' : 'FALSE';
  }

  /**
   * Executes an external query without allowing one source failure to break search.
   */
  protected function rows(string $sql, array $params, string $source): array {
    try {
      return $this->database->query($sql, $params)->fetchAll();
    }
    catch (\Throwable $e) {
      $this->logger->warning('BFEP database search source @source failed: @message', [
        '@source' => $source,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Produces a readable search snippet.
   */
  protected function snippet(array $parts): array|string {
    $text = trim(implode(' · ', array_values(array_filter(array_map(static function ($value): string {
      return trim(strip_tags((string) ($value ?? '')));
    }, $parts), static fn(string $value): bool => $value !== ''))));

    if ($text === '') {
      return '';
    }

    // Core Search's excerpt helper highlights the matched terms.
    if (function_exists('search_excerpt')) {
      return search_excerpt($this->getKeywords(), $text, 'en');
    }

    return Unicode::truncate($text, 280, TRUE, TRUE);
  }

  /**
   * Simple relevance score used to merge records from several tables.
   */
  protected function score(string $phrase, string $title, string $text, int $base = 0): int {
    $needle = mb_strtolower(trim($phrase));
    $title_l = mb_strtolower($title);
    $text_l = mb_strtolower($text);
    $score = $base;

    if ($needle !== '') {
      if ($title_l === $needle) {
        $score += 100;
      }
      elseif (str_starts_with($title_l, $needle)) {
        $score += 80;
      }
      elseif (str_contains($title_l, $needle)) {
        $score += 60;
      }
      elseif (str_contains($text_l, $needle)) {
        $score += 25;
      }
    }

    foreach ($this->tokens($phrase) as $token) {
      $token = mb_strtolower($token);
      if (str_contains($title_l, $token)) {
        $score += 8;
      }
      elseif (str_contains($text_l, $token)) {
        $score += 2;
      }
    }

    return $score;
  }

  protected function campaignResults(array $tokens, string $phrase, bool $is_staff): array {
    $params = [];
    $where = $this->buildWhere([
      "COALESCE(v.contact_name, '')",
      "COALESCE(v.country, '')",
      "COALESCE(v.global_region, '')",
      "COALESCE(v.description, '')",
      "COALESCE(v.career, '')",
      "COALESCE(v.platform, '')",
      "COALESCE(array_to_string(v.tags, ' '), '')",
      "COALESCE(CAST(v.line_number AS TEXT), '')",
      "COALESCE(v.fundraiser_url, '')",
    ], $tokens, 'campaign', $params);

    $rows = $this->rows("\n      SELECT v.id, v.line_number, v.contact_name, v.country, v.global_region, v.description, v.career, v.platform, v.fundraiser_url, v.tags, v.updated_at\n      FROM v_campaigns v\n      INNER JOIN campaigns source ON source.id = v.id AND source.deleted_at IS NULL\n      WHERE {$where}\n      ORDER BY v.updated_at DESC NULLS LAST, v.line_number DESC NULLS LAST\n      LIMIT " . self::SOURCE_LIMIT, $params, 'campaigns');

    $results = [];
    foreach ($rows as $row) {
      $title = trim((string) ($row->contact_name ?: 'Campaign #' . $row->id));
      $text = implode(' ', [
        $row->line_number,
        $row->country,
        $row->global_region,
        $row->description,
        $row->career,
        $row->platform,
        $row->fundraiser_url,
        is_array($row->tags ?? NULL) ? implode(' ', $row->tags) : (string) ($row->tags ?? ''),
      ]);

      $url = $is_staff
        ? Url::fromRoute('bfep.admin_campaign_edit', ['campaign_id' => $row->id], ['absolute' => TRUE])
        : Url::fromRoute('bfep.campaign_detail', ['campaign_id' => $row->id], ['absolute' => TRUE]);

      $results[] = [
        'title' => $title,
        'link' => $url->toString(),
        'type' => $this->t('Campaign'),
        'snippet' => $this->snippet([
          $row->line_number ? 'Line #' . $row->line_number : NULL,
          $row->country,
          $row->global_region,
          $row->platform,
          $row->description,
        ]),
        '_score' => $this->score($phrase, $title, $text, 30),
      ];
    }

    return $results;
  }

  protected function countryResults(array $tokens, string $phrase): array {
    $params = [];
    $where = $this->buildWhere([
      "COALESCE(v.country, '')",
      "COALESCE(v.global_region, '')",
    ], $tokens, 'country', $params);

    $rows = $this->rows("\n      SELECT v.country, v.global_region, COUNT(*) AS campaign_count\n      FROM v_campaigns v\n      INNER JOIN campaigns source ON source.id = v.id AND source.deleted_at IS NULL\n      WHERE v.country IS NOT NULL AND v.country <> '' AND {$where}\n      GROUP BY v.country, v.global_region\n      ORDER BY campaign_count DESC, v.country ASC\n      LIMIT 30", $params, 'countries');

    $results = [];
    foreach ($rows as $row) {
      $title = (string) $row->country;
      $text = trim((string) $row->global_region . ' ' . (string) $row->campaign_count);
      $results[] = [
        'title' => $title,
        'link' => Url::fromRoute('bfep.country_detail', ['country' => $row->country], ['absolute' => TRUE])->toString(),
        'type' => $this->t('Country'),
        'snippet' => $this->snippet([
          $row->global_region,
          (int) $row->campaign_count . ' campaigns',
        ]),
        '_score' => $this->score($phrase, $title, $text, 20),
      ];
    }

    return $results;
  }

  protected function initiativeResults(array $tokens, string $phrase, bool $is_staff): array {
    $params = [];
    $where = $this->buildWhere([
      "COALESCE(contact_name, '')",
      "COALESCE(country_raw, '')",
      "COALESCE(project_description, '')",
      "COALESCE(initiative_type, '')",
    ], $tokens, 'initiative', $params);

    $visibility = $is_staff ? 'TRUE' : 'featured_by_bfep = true';
    $rows = $this->rows("\n      SELECT id, contact_name, country_raw, project_description, initiative_type, featured_by_bfep, created_at\n      FROM ground_initiatives\n      WHERE {$visibility} AND {$where}\n      ORDER BY featured_by_bfep DESC, created_at DESC NULLS LAST, id DESC\n      LIMIT 40", $params, 'ground initiatives');

    $results = [];
    foreach ($rows as $row) {
      $title = trim((string) ($row->contact_name ?: 'Ground initiative #' . $row->id));
      $text = implode(' ', [$row->country_raw, $row->project_description, $row->initiative_type]);
      $results[] = [
        'title' => $title,
        'link' => Url::fromRoute('bfep.initiatives', [], [
          'absolute' => TRUE,
          'fragment' => 'initiative-' . $row->id,
        ])->toString(),
        'type' => $this->t('Ground initiative'),
        'snippet' => $this->snippet([$row->country_raw, $row->initiative_type, $row->project_description]),
        '_score' => $this->score($phrase, $title, $text, 15),
      ];
    }

    return $results;
  }

  protected function referralResults(array $tokens, string $phrase): array {
    $params = [];
    $where = $this->buildWhere([
      "COALESCE(full_name, '')",
      "COALESCE(email, '')",
      "COALESCE(fundraiser_url, '')",
      "COALESCE(social_media_usernames, '')",
      "COALESCE(city_country, '')",
      "COALESCE(family_description, '')",
      "COALESCE(verification_status, '')",
    ], $tokens, 'referral', $params);

    $rows = $this->rows("\n      SELECT id, full_name, email, fundraiser_url, city_country, family_description, verification_status, created_at\n      FROM referral_submissions\n      WHERE {$where}\n      ORDER BY created_at DESC NULLS LAST, id DESC\n      LIMIT 40", $params, 'referrals');

    $results = [];
    foreach ($rows as $row) {
      $title = trim((string) ($row->full_name ?: 'Referral #' . $row->id));
      $text = implode(' ', [$row->email, $row->fundraiser_url, $row->city_country, $row->family_description, $row->verification_status]);
      $results[] = [
        'title' => $title,
        'link' => Url::fromRoute('bfep.admin_referral_review', ['referral_id' => $row->id], ['absolute' => TRUE])->toString(),
        'type' => $this->t('Staff · Referral'),
        'snippet' => $this->snippet([AdminFormat::referralStatusLabel($row->verification_status), $row->email, $row->city_country, $row->family_description]),
        '_score' => $this->score($phrase, $title, $text, 10),
      ];
    }

    return $results;
  }

  protected function volunteerResults(array $tokens, string $phrase): array {
    $params = [];
    $where = $this->buildWhere([
      "COALESCE(full_name, '')",
      "COALESCE(email, '')",
      "COALESCE(hours_per_week, '')",
      "COALESCE(skills_experience, '')",
      "COALESCE(vouched_for_by, '')",
    ], $tokens, 'volunteer', $params);

    $rows = $this->rows("\n      SELECT id, full_name, email, hours_per_week, skills_experience, vouched_for_by, accepted, contacted, onboarded, created_at\n      FROM volunteers\n      WHERE {$where}\n      ORDER BY created_at DESC NULLS LAST, id DESC\n      LIMIT 40", $params, 'volunteers');

    $results = [];
    foreach ($rows as $row) {
      $status = $row->accepted === NULL ? 'Pending' : (!empty($row->accepted) ? 'Accepted' : 'Not accepted');
      if (!empty($row->onboarded)) {
        $status .= ' · Onboarded';
      }
      $title = trim((string) ($row->full_name ?: 'Volunteer #' . $row->id));
      $text = implode(' ', [$row->email, $row->hours_per_week, $row->skills_experience, $row->vouched_for_by, $status]);
      $results[] = [
        'title' => $title,
        'link' => Url::fromRoute('bfep.admin_volunteer_review', ['volunteer_id' => $row->id], ['absolute' => TRUE])->toString(),
        'type' => $this->t('Staff · Volunteer'),
        'snippet' => $this->snippet([$status, $row->email, $row->hours_per_week, $row->skills_experience]),
        '_score' => $this->score($phrase, $title, $text, 10),
      ];
    }

    return $results;
  }

  protected function changeRequestResults(array $tokens, string $phrase): array {
    $params = [];
    $where = $this->buildWhere([
      "COALESCE(submitter_email, '')",
      "COALESCE(submitter_name, '')",
      "COALESCE(submitter_type, '')",
      "COALESCE(family_line_number_raw, '')",
      "COALESCE(CAST(campaign_id AS TEXT), '')",
      "COALESCE(fundraiser_url, '')",
      "COALESCE(fields_to_change, '')",
      "COALESCE(change_description, '')",
      "COALESCE(new_email, '')",
      "COALESCE(new_social_media, '')",
    ], $tokens, 'change', $params);

    $rows = $this->rows("\n      SELECT id, submitter_email, submitter_name, submitter_type, family_line_number_raw, campaign_id, fields_to_change, change_description, processed, created_at\n      FROM info_change_requests\n      WHERE {$where}\n      ORDER BY processed ASC, created_at DESC NULLS LAST, id DESC\n      LIMIT 40", $params, 'change requests');

    $results = [];
    foreach ($rows as $row) {
      $title = trim((string) ($row->submitter_name ?: $row->submitter_email ?: 'Change request #' . $row->id));
      $status = !empty($row->processed) ? 'Processed' : 'Pending';
      $text = implode(' ', [$row->submitter_email, $row->submitter_type, $row->family_line_number_raw, $row->campaign_id, $row->fields_to_change, $row->change_description, $status]);
      $results[] = [
        'title' => $title,
        'link' => Url::fromRoute('bfep.admin_change_review', ['request_id' => $row->id], ['absolute' => TRUE])->toString(),
        'type' => $this->t('Staff · Change request'),
        'snippet' => $this->snippet([$status, $row->family_line_number_raw ? 'Line ' . $row->family_line_number_raw : NULL, $row->fields_to_change, $row->change_description]),
        '_score' => $this->score($phrase, $title, $text, 10),
      ];
    }

    return $results;
  }

}
