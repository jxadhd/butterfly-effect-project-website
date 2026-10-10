<?php

declare(strict_types=1);

namespace Drupal\bfep\Service;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\bfep\Repository\CampaignRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves route-specific titles, canonical URLs, robots rules, and JSON-LD.
 */
final class SeoManager {

  private bool $resolved = FALSE;
  private ?array $context = NULL;

  public function __construct(
    private readonly CurrentRouteMatch $routeMatch,
    private readonly RequestStack $requestStack,
    private readonly CampaignRepository $campaigns,
    private readonly BfepSettings $settings,
    private readonly LoggerChannelInterface $logger,
  ) {}

  public function context(): ?array {
    if ($this->resolved) {
      return $this->context;
    }
    $this->resolved = TRUE;

    $route = (string) $this->routeMatch->getRouteName();
    $request = $this->requestStack->getCurrentRequest();
    if ($request === NULL) {
      return NULL;
    }

    $index = 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1';
    $noindex = 'noindex, follow';
    $base = $this->settings->baseUrl();
    $static = [
      'bfep.home' => [$this->settings->string('home_title'), $this->settings->string('tagline'), $this->settings->string('home_description'), '/', $index],
      'bfep.about' => ['About', 'About ' . $this->settings->organizationName(), 'Learn how The Butterfly Effect Project organises and maintains a public directory of humanitarian fundraiser information.', '/about', $index],
      'bfep.countries' => ['Campaigns by Country', 'Campaigns by Country', 'Browse humanitarian fundraising campaigns by country, including featured campaigns and urgent medical cases.', '/countries', $index],
      'bfep.initiatives' => ['Ground Initiatives', 'Ground Initiatives', 'Explore humanitarian ground initiatives listed by The Butterfly Effect Project.', '/initiatives', $this->settings->boolean('index_initiatives_page') ? $index : $noindex],
      'bfep.refer' => ['Submit a Referral', 'Submit a Referral', 'Refer a humanitarian fundraising campaign to The Butterfly Effect Project for review.', '/refer', $this->settings->boolean('index_referral_page') ? $index : $noindex],
      'bfep.volunteer' => ['Volunteer', 'Volunteer with The Butterfly Effect Project', 'Apply to help review, organise, translate, and maintain humanitarian fundraiser information.', '/volunteer', $this->settings->boolean('index_volunteer_page') ? $index : $noindex],
      'bfep.change_request' => ['Information or Privacy Request', 'Request an Information Update', 'Request a correction, removal, privacy response, or other update to information held by The Butterfly Effect Project.', '/change-request', $noindex],
    ];

    if (isset($static[$route])) {
      [$title, $heading, $description, $path, $robots] = $static[$route];
      $this->context = $this->baseContext($title, $heading, $description, $this->settings->absoluteUrl($path), $robots);
    }
    elseif ($route === 'bfep.campaigns') {
      $page = $this->page();
      $filtered = $this->hasNonPageFilters();
      $title = $filtered ? 'Search Humanitarian Fundraisers' : 'Humanitarian Fundraising Campaigns';
      if (!$filtered && $page > 1) {
        $title .= ' – Page ' . $page;
      }
      $this->context = $this->baseContext(
        $title,
        $title,
        $filtered
          ? 'Search and filter humanitarian fundraising campaigns by country, urgency, platform, tags, and featured status.'
          : 'Browse humanitarian fundraising campaigns, urgent medical cases, and public fundraiser information maintained by The Butterfly Effect Project.',
        $base . '/campaigns' . (!$filtered && $page > 1 ? '?p=' . $page : ''),
        $filtered ? $noindex : $index,
      );
      $this->context['cache_tags'][] = BfepCacheInvalidator::CAMPAIGNS;
    }
    elseif ($route === 'bfep.campaign_detail') {
      $this->context = $this->campaignContext($index);
    }
    elseif ($route === 'bfep.country_detail') {
      $this->context = $this->countryContext($index, $noindex);
    }
    elseif (str_starts_with($route, 'bfep.trust_')) {
      $this->context = $this->trustContext(
        (string) $this->routeMatch->getParameter('page'),
        $this->settings->boolean('index_transparency_pages') ? $index : $noindex,
      );
    }
    elseif (str_starts_with($route, 'search.view_')) {
      $this->context = $this->baseContext(
        'Search',
        'Search',
        'Search the public Butterfly Effect Project campaign and initiative directory.',
        $base . '/search/database',
        $noindex,
      );
    }

    if ($this->context === NULL) {
      return NULL;
    }
    $this->context['cache_tags'] = array_values(array_unique([
      ...$this->context['cache_tags'],
      'config:bfep.settings',
    ]));
    return $this->context;
  }

  private function campaignContext(string $robots): ?array {
    $campaignId = (int) $this->routeMatch->getParameter('campaign_id');
    try {
      $row = $this->campaigns->find($campaignId);
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Could not build campaign metadata for @id: @message', [
        '@id' => $campaignId,
        '@message' => $exception->getMessage(),
      ]);
      return NULL;
    }
    if ($row === NULL) {
      return NULL;
    }

    $name = trim((string) ($row->contact_name ?: 'Campaign #' . $campaignId));
    $country = trim((string) ($row->country ?? ''));
    $title = strtr($this->settings->string('campaign_title_pattern'), [
      '[campaign:name]' => $name,
      '[campaign:country]' => $country,
    ]);
    $title = trim((string) preg_replace('/\s+[–—-]\s*$/u', '', $title));
    $description = $this->cleanDescription((string) ($row->description ?? ''));
    if ($description === '') {
      $description = 'View ' . $name . "'s humanitarian fundraising campaign"
        . ($country !== '' ? ' in ' . $country : '')
        . ', listed by The Butterfly Effect Project.';
    }
    $canonical = $this->settings->absoluteUrl('/campaigns/' . $campaignId);
    $context = $this->baseContext($title, $title, $description, $canonical, $robots);
    // Dates tell search engines and link previews how fresh the record is.
    $published = $this->isoDate($row->created_at ?? NULL);
    $modified = $this->isoDate($row->updated_at ?? NULL) ?? $published;
    $context['og_type'] = 'article';
    $context['published'] = $published;
    $context['modified'] = $modified;
    $context['json_ld'][] = array_filter([
      '@context' => 'https://schema.org',
      '@type' => 'WebPage',
      '@id' => $canonical,
      'url' => $canonical,
      'name' => $title,
      'description' => $description,
      'inLanguage' => 'en-NZ',
      'datePublished' => $published,
      'dateModified' => $modified,
      'isPartOf' => ['@id' => $this->settings->absoluteUrl('/#website')],
    ], static fn($value): bool => $value !== NULL);
    $context['json_ld'][] = $this->breadcrumbs([
      ['name' => 'Home', 'url' => $this->settings->absoluteUrl('/')],
      ['name' => 'Campaigns', 'url' => $this->settings->absoluteUrl('/campaigns')],
      ['name' => $name, 'url' => $canonical],
    ]);
    $context['cache_tags'][] = BfepCacheInvalidator::CAMPAIGNS;
    $context['cache_tags'][] = 'bfep:campaign:' . $campaignId;
    return $context;
  }

  private function countryContext(string $index, string $noindex): ?array {
    $country = trim((string) $this->routeMatch->getParameter('country'));
    try {
      $canonicalCountry = $this->campaigns->canonicalCountry($country);
      if ($canonicalCountry === NULL) {
        return NULL;
      }
      $count = $this->campaigns->countryCount($canonicalCountry);
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Could not build country metadata for @country: @message', [
        '@country' => $country,
        '@message' => $exception->getMessage(),
      ]);
      return NULL;
    }

    $filtered = $this->hasNonPageFilters();
    $page = $this->page();
    $title = strtr($this->settings->string('country_title_pattern'), [
      '[country:name]' => $canonicalCountry,
    ]);
    if (!$filtered && $page > 1) {
      $title .= ' – Page ' . $page;
    }
    $path = '/countries/' . rawurlencode($canonicalCountry);
    $canonical = $this->settings->absoluteUrl($path) . (!$filtered && $page > 1 ? '?p=' . $page : '');
    $context = $this->baseContext(
      $title,
      $title,
      'Browse ' . $count . ' humanitarian fundraising ' . ($count === 1 ? 'campaign' : 'campaigns') . ' in ' . $canonicalCountry . ', including featured and urgent cases.',
      $canonical,
      $filtered ? $noindex : $index,
    );
    $context['json_ld'][] = $this->breadcrumbs([
      ['name' => 'Home', 'url' => $this->settings->absoluteUrl('/')],
      ['name' => 'Countries', 'url' => $this->settings->absoluteUrl('/countries')],
      ['name' => $canonicalCountry, 'url' => $this->settings->absoluteUrl($path)],
    ]);
    $context['cache_tags'][] = BfepCacheInvalidator::COUNTRIES;
    $context['cache_tags'][] = BfepCacheInvalidator::CAMPAIGNS;
    return $context;
  }

  private function trustContext(string $page, string $robots): ?array {
    $definitions = [
      'transparency' => ['Transparency', 'Transparency', 'Understand what The Butterfly Effect Project does, what its directory labels mean, and the limits of fundraiser review.'],
      'how-we-verify' => ['How We Verify Fundraisers', 'How We Verify Fundraisers', 'Learn what BFEP verification means: ongoing direct contact with the beneficiary and confirmation that they receive funds from the fundraiser organiser.'],
      'team' => ['Our Team', 'Our Team', 'Learn who operates The Butterfly Effect Project and how volunteer and technical responsibilities are separated.'],
      'editorial-policy' => ['Editorial Policy', 'Editorial Policy', 'Read the standards for public descriptions, featured labels, corrections, removals, and conflicts of interest.'],
      'privacy' => ['Privacy Notice', 'Privacy Notice', 'Learn what information The Butterfly Effect Project collects, why it is used, and how to request access or correction.'],
      'safeguarding' => ['Safeguarding', 'Safeguarding', 'Read the privacy, dignity, and safety principles applied to humanitarian campaign information.'],
      'contact-us' => ['Contact', 'Contact', 'Contact The Butterfly Effect Project about a referral, correction, privacy concern, or volunteering.'],
    ];
    if (!isset($definitions[$page])) {
      return NULL;
    }
    [$title, $heading, $description] = $definitions[$page];
    $context = $this->baseContext($title, $heading, $description, $this->settings->absoluteUrl('/' . $page), $robots);
    $context['json_ld'][] = $this->breadcrumbs([
      ['name' => 'Home', 'url' => $this->settings->absoluteUrl('/')],
      ['name' => $heading, 'url' => $this->settings->absoluteUrl('/' . $page)],
    ]);
    return $context;
  }

  private function baseContext(string $title, string $heading, string $description, string $canonical, string $robots): array {
    return [
      'title' => $title,
      'heading' => $heading,
      'description' => $this->cleanDescription($description, 180),
      'canonical' => $canonical,
      'robots' => $robots,
      'og_type' => 'website',
      'json_ld' => [],
      'cache_tags' => [],
    ];
  }

  private function isoDate(mixed $value): ?string {
    if ($value === NULL || trim((string) $value) === '') {
      return NULL;
    }
    try {
      return (new \DateTimeImmutable((string) $value))->format(DATE_ATOM);
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  private function page(): int {
    $value = $this->requestStack->getCurrentRequest()?->query->all()['p'] ?? 1;
    return is_scalar($value) && preg_match('/^[1-9][0-9]*$/', (string) $value) ? min((int) $value, 100000) : 1;
  }

  private function hasNonPageFilters(): bool {
    $query = $this->requestStack->getCurrentRequest()?->query->all() ?? [];
    foreach (['q', 'country', 'region', 'platform', 'tag', 'featured', 'urgent', 'sort', 'per_page'] as $key) {
      $value = $query[$key] ?? NULL;
      if (is_scalar($value) && trim((string) $value) !== ''
        && !($key === 'sort' && $value === 'line_desc')
        && !($key === 'per_page' && (string) $value === '20')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  private function cleanDescription(string $description, int $length = 160): string {
    $description = trim((string) preg_replace('/\s+/u', ' ', strip_tags($description)));
    if (mb_strlen($description, 'UTF-8') > $length) {
      $description = mb_strimwidth($description, 0, $length - 1, '…', 'UTF-8');
    }
    return $description;
  }

  private function breadcrumbs(array $items): array {
    $elements = [];
    foreach (array_values($items) as $index => $item) {
      $elements[] = [
        '@type' => 'ListItem',
        'position' => $index + 1,
        'name' => $item['name'],
        'item' => $item['url'],
      ];
    }
    return [
      '@context' => 'https://schema.org',
      '@type' => 'BreadcrumbList',
      'itemListElement' => $elements,
    ];
  }

}
