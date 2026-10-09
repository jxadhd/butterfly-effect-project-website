<?php

declare(strict_types=1);

namespace Drupal\bfep\Service;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Url;
use Drupal\bfep\Repository\CampaignRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Builds canonical, cacheable campaign listing pages without Form API noise.
 */
final class CampaignListBuilder {

  private const DEFAULT_SORT = 'line_desc';
  private const DEFAULT_PER_PAGE = 20;
  private const PER_PAGE_OPTIONS = [20, 50, 100, 500];
  private const SORT_OPTIONS = [
    'line_desc' => 'Newest line number',
    'updated_desc' => 'Recently updated',
    'created_desc' => 'Recently added',
    'name_asc' => 'Name A–Z',
    'country_asc' => 'Country A–Z',
  ];

  public function __construct(
    private readonly CampaignRepository $campaigns,
    private readonly CampaignPresenter $presenter,
    private readonly BfepSettings $settings,
  ) {}

  /**
   * Returns a redirect for a non-canonical query or the listing render array.
   */
  public function build(Request $request, ?string $fixedCountry = NULL): array|RedirectResponse {
    $normalized = $this->normalize($request, $fixedCountry);
    $routeName = $fixedCountry === NULL ? 'bfep.campaigns' : 'bfep.country_detail';
    $routeParameters = $fixedCountry === NULL ? [] : ['country' => $fixedCountry];

    if ($normalized['redirect']) {
      return new RedirectResponse(Url::fromRoute($routeName, $routeParameters, [
        'query' => $normalized['query'],
      ])->toString(), 301);
    }

    $filters = $normalized['filters'];
    $result = $this->campaigns->list($filters);
    $total = (int) $result['total'];
    $totalPages = max(1, (int) ceil($total / $filters['per_page']));
    if ($filters['page'] > $totalPages) {
      throw new NotFoundHttpException();
    }

    $cards = [];
    foreach ($result['rows'] as $row) {
      $cards[] = $this->presenter->card(
        $row,
        Url::fromRoute('bfep.campaign_detail', ['campaign_id' => (int) $row->id])->toString(),
      );
    }

    $pageUrl = function (int $page) use ($routeName, $routeParameters, $normalized): string {
      $query = $normalized['query'];
      if ($page > 1) {
        $query['p'] = $page;
      }
      else {
        unset($query['p']);
      }
      return Url::fromRoute($routeName, $routeParameters, ['query' => $query])->toString();
    };

    $options = $this->campaigns->filterOptions();
    $activeFilters = $filters['q'] !== ''
      || ($fixedCountry === NULL && $filters['country'] !== '')
      || $filters['region'] !== ''
      || $filters['platform'] !== ''
      || $filters['tag'] !== ''
      || $filters['featured']
      || $filters['urgent']
      || $filters['sort'] !== self::DEFAULT_SORT
      || $filters['per_page'] !== self::DEFAULT_PER_PAGE;

    return [
      '#theme' => 'bfep_campaign_list',
      '#attached' => ['library' => ['bfep/public']],
      '#campaigns' => $cards,
      '#filters' => $filters,
      '#options' => [
        'countries' => $options['country'] ?? [],
        'regions' => $options['global_region'] ?? [],
        'platforms' => $options['platform'] ?? [],
        'tags' => $options['tag'] ?? [],
        'sorts' => self::SORT_OPTIONS,
        'per_page' => array_combine(self::PER_PAGE_OPTIONS, self::PER_PAGE_OPTIONS),
      ],
      '#fixed_country' => $fixedCountry,
      '#action_url' => Url::fromRoute($routeName, $routeParameters)->toString(),
      '#clear_url' => Url::fromRoute($routeName, $routeParameters)->toString(),
      '#total' => $total,
      '#total_pages' => $totalPages,
      '#previous_url' => $filters['page'] > 1 ? $pageUrl($filters['page'] - 1) : NULL,
      '#next_url' => $filters['page'] < $totalPages ? $pageUrl($filters['page'] + 1) : NULL,
      '#active_filters' => $activeFilters,
      '#cache' => [
        'contexts' => ['url.path', 'url.query_args'],
        'tags' => [BfepCacheInvalidator::CAMPAIGNS, BfepCacheInvalidator::FORM_OPTIONS],
        'max-age' => $this->settings->listingCacheMaxAge(),
      ],
    ];
  }

  /**
   * Normalizes the public GET interface and strips Form API/tracking arguments.
   */
  private function normalize(Request $request, ?string $fixedCountry): array {
    $input = $request->query->all();
    $text = static function (array $values, string $key, int $length = 120): string {
      $value = $values[$key] ?? '';
      if (!is_scalar($value)) {
        return '';
      }
      return Unicode::truncate(trim((string) $value), $length, TRUE, FALSE);
    };

    $pageRaw = $text($input, 'p', 12);
    $page = preg_match('/^[1-9][0-9]*$/', $pageRaw) ? (int) $pageRaw : 1;
    $page = min($page, 100000);

    $perPageRaw = (int) $text($input, 'per_page', 3);
    $perPage = in_array($perPageRaw, self::PER_PAGE_OPTIONS, TRUE)
      ? $perPageRaw
      : self::DEFAULT_PER_PAGE;

    $sort = $text($input, 'sort', 32);
    if (!array_key_exists($sort, self::SORT_OPTIONS)) {
      $sort = self::DEFAULT_SORT;
    }

    $filters = [
      'q' => $text($input, 'q'),
      'country' => $fixedCountry ?? $text($input, 'country', 100),
      'region' => $text($input, 'region', 100),
      'platform' => $text($input, 'platform', 100),
      'tag' => $text($input, 'tag', 100),
      'featured' => $text($input, 'featured', 1) === '1',
      'urgent' => $text($input, 'urgent', 1) === '1',
      'sort' => $sort,
      'page' => $page,
      'per_page' => $perPage,
    ];

    $query = [];
    foreach (['q', 'region', 'platform', 'tag'] as $key) {
      if ($filters[$key] !== '') {
        $query[$key] = $filters[$key];
      }
    }
    if ($fixedCountry === NULL && $filters['country'] !== '') {
      $query['country'] = $filters['country'];
    }
    foreach (['featured', 'urgent'] as $key) {
      if ($filters[$key]) {
        $query[$key] = '1';
      }
    }
    if ($sort !== self::DEFAULT_SORT) {
      $query['sort'] = $sort;
    }
    if ($perPage !== self::DEFAULT_PER_PAGE) {
      $query['per_page'] = (string) $perPage;
    }
    if ($page > 1) {
      $query['p'] = (string) $page;
    }

    $current = $input;
    $canonical = $query;
    ksort($current);
    ksort($canonical);

    return [
      'filters' => $filters,
      'query' => $query,
      'redirect' => $current !== $canonical,
    ];

  }

}
