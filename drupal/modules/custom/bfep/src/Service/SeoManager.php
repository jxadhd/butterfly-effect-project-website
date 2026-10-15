<?php

declare(strict_types=1);

namespace Drupal\bfep\Service;

use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\bfep\Repository\CampaignRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves route-specific titles, canonical URLs, robots rules, and JSON-LD.
 */
final class SeoManager {

  use StringTranslationTrait;

  private bool $resolved = FALSE;
  private ?array $context = NULL;

  public function __construct(
    private readonly CurrentRouteMatch $routeMatch,
    private readonly RequestStack $requestStack,
    private readonly CampaignRepository $campaigns,
    private readonly BfepSettings $settings,
    private readonly LoggerChannelInterface $logger,
    private readonly LanguageManagerInterface $languageManager,
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
    $organization = $this->settings->organizationName();
    $static = [
      'bfep.home' => [$this->settings->string('home_title'), $this->settings->string('tagline'), $this->settings->string('home_description'), '/', $index],
      'bfep.about' => [$this->t('About'), $this->t('About @organization', ['@organization' => $organization]), $this->t('Learn how The Butterfly Effect Project organises and maintains a public directory of humanitarian fundraiser information.'), '/about', $index],
      'bfep.countries' => [$this->t('Campaigns by Country'), $this->t('Campaigns by Country'), $this->t('Browse humanitarian fundraising campaigns by country, including featured campaigns and urgent medical cases.'), '/countries', $index],
      'bfep.initiatives' => [$this->t('Ground Initiatives'), $this->t('Ground Initiatives'), $this->t('Explore humanitarian ground initiatives listed by The Butterfly Effect Project.'), '/initiatives', $this->settings->boolean('index_initiatives_page') ? $index : $noindex],
      'bfep.refer' => [$this->t('Submit a Referral'), $this->t('Submit a Referral'), $this->t('Refer a humanitarian fundraising campaign to The Butterfly Effect Project for review.'), '/refer', $this->settings->boolean('index_referral_page') ? $index : $noindex],
      'bfep.volunteer' => [$this->t('Volunteer'), $this->t('Volunteer with The Butterfly Effect Project'), $this->t('Apply to help review, organise, translate, and maintain humanitarian fundraiser information.'), '/volunteer', $this->settings->boolean('index_volunteer_page') ? $index : $noindex],
      'bfep.change_request' => [$this->t('Information or Privacy Request'), $this->t('Request an Information Update'), $this->t('Request a correction, removal, privacy response, or other update to information held by The Butterfly Effect Project.'), '/change-request', $noindex],
    ];

    if (isset($static[$route])) {
      [$title, $heading, $description, $path, $robots] = $static[$route];
      $this->context = $this->baseContext((string) $title, (string) $heading, (string) $description, $path, $robots);
    }
    elseif ($route === 'bfep.campaigns') {
      $page = $this->page();
      $filtered = $this->hasNonPageFilters();
      $title = (string) ($filtered ? $this->t('Search Humanitarian Fundraisers') : $this->t('Humanitarian Fundraising Campaigns'));
      if (!$filtered && $page > 1) {
        $title = $this->pageTitle($title, $page);
      }
      $this->context = $this->baseContext(
        $title,
        $title,
        (string) ($filtered
          ? $this->t('Search and filter humanitarian fundraising campaigns by country, urgency, platform, tags, and featured status.')
          : $this->t('Browse humanitarian fundraising campaigns, urgent medical cases, and public fundraiser information maintained by The Butterfly Effect Project.')),
        '/campaigns' . (!$filtered && $page > 1 ? '?p=' . $page : ''),
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
        (string) $this->t('Search'),
        (string) $this->t('Search'),
        (string) $this->t('Search the public Butterfly Effect Project campaign and initiative directory.'),
        '/search/database',
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

    $name = trim((string) ($row->contact_name ?? '')) ?: (string) $this->t('Campaign #@id', ['@id' => $campaignId]);
    $country = $this->placeName($row->country ?? NULL);
    $title = strtr($this->settings->string('campaign_title_pattern'), [
      '[campaign:name]' => $name,
      '[campaign:country]' => $country,
    ]);
    $title = trim((string) preg_replace('/\s+[–—-]\s*$/u', '', $title));
    $description = $this->cleanDescription((string) ($row->description ?? ''));
    if ($description === '') {
      $description = (string) ($country !== ''
        ? $this->t("View @name's humanitarian fundraising campaign in @country, listed by The Butterfly Effect Project.", ['@name' => $name, '@country' => $country])
        : $this->t("View @name's humanitarian fundraising campaign, listed by The Butterfly Effect Project.", ['@name' => $name]));
    }
    $context = $this->baseContext($title, $title, $description, '/campaigns/' . $campaignId, $robots);
    $canonical = $context['canonical'];
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
      'inLanguage' => $this->languageTag(),
      'datePublished' => $published,
      'dateModified' => $modified,
      'isPartOf' => ['@id' => $this->settings->absoluteUrl('/#website')],
    ], static fn($value): bool => $value !== NULL);
    $context['json_ld'][] = $this->breadcrumbs([
      ['name' => (string) $this->t('Home'), 'url' => $this->settings->localizedUrl('/')],
      ['name' => (string) $this->t('Campaigns'), 'url' => $this->settings->localizedUrl('/campaigns')],
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
    $countryName = $this->placeName($canonicalCountry);
    $title = strtr($this->settings->string('country_title_pattern'), [
      '[country:name]' => $countryName,
    ]);
    if (!$filtered && $page > 1) {
      $title = $this->pageTitle($title, $page);
    }
    $path = '/countries/' . rawurlencode($canonicalCountry);
    $context = $this->baseContext(
      $title,
      $title,
      (string) $this->formatPlural($count, 'Browse 1 humanitarian fundraising campaign in @country, including featured and urgent cases.', 'Browse @count humanitarian fundraising campaigns in @country, including featured and urgent cases.', ['@country' => $countryName]),
      $path . (!$filtered && $page > 1 ? '?p=' . $page : ''),
      $filtered ? $noindex : $index,
    );
    $context['json_ld'][] = $this->breadcrumbs([
      ['name' => (string) $this->t('Home'), 'url' => $this->settings->localizedUrl('/')],
      ['name' => (string) $this->t('Countries'), 'url' => $this->settings->localizedUrl('/countries')],
      ['name' => $countryName, 'url' => $this->settings->localizedUrl($path)],
    ]);
    $context['cache_tags'][] = BfepCacheInvalidator::COUNTRIES;
    $context['cache_tags'][] = BfepCacheInvalidator::CAMPAIGNS;
    return $context;
  }

  private function trustContext(string $page, string $robots): ?array {
    $definitions = [
      'transparency' => [$this->t('Transparency'), $this->t('Understand what The Butterfly Effect Project does, what its directory labels mean, and the limits of fundraiser review.')],
      'how-we-verify' => [$this->t('How We Verify Fundraisers'), $this->t('Learn what BFEP verification means: ongoing direct contact with the beneficiary and confirmation that they receive funds from the fundraiser organiser.')],
      'team' => [$this->t('Our Team'), $this->t('Learn who operates The Butterfly Effect Project and how volunteer and technical responsibilities are separated.')],
      'editorial-policy' => [$this->t('Editorial Policy'), $this->t('Read the standards for public descriptions, featured labels, corrections, removals, and conflicts of interest.')],
      'privacy' => [$this->t('Privacy Notice'), $this->t('Learn what information The Butterfly Effect Project collects, why it is used, and how to request access or correction.')],
      'safeguarding' => [$this->t('Safeguarding'), $this->t('Read the privacy, dignity, and safety principles applied to humanitarian campaign information.')],
      'contact-us' => [$this->t('Contact'), $this->t('Contact The Butterfly Effect Project about a referral, correction, privacy concern, or volunteering.')],
    ];
    if (!isset($definitions[$page])) {
      return NULL;
    }
    $title = $heading = (string) $definitions[$page][0];
    $description = (string) $definitions[$page][1];
    $context = $this->baseContext($title, $heading, $description, '/' . $page, $robots);
    $context['json_ld'][] = $this->breadcrumbs([
      ['name' => (string) $this->t('Home'), 'url' => $this->settings->localizedUrl('/')],
      ['name' => $heading, 'url' => $this->settings->localizedUrl('/' . $page)],
    ]);
    return $context;
  }

  /**
   * Builds the shared metadata for a page.
   *
   * @param string $title
   *   The title for search results and link previews.
   * @param string $heading
   *   The page's visible heading.
   * @param string $description
   *   The meta description.
   * @param string $path
   *   The page's canonical path without a language prefix, with any query
   *   string (such as /campaigns?p=2). The canonical URL adds the prefix of
   *   the page's language; other languages' versions use the same path.
   * @param string $robots
   *   The robots meta value.
   */
  private function baseContext(string $title, string $heading, string $description, string $path, string $robots): array {
    return [
      'title' => $title,
      'heading' => $heading,
      'description' => $this->cleanDescription($description, 180),
      'path' => $path,
      'canonical' => $this->settings->localizedUrl($path),
      'robots' => $robots,
      'og_type' => 'website',
      'json_ld' => [],
      'cache_tags' => [],
    ];
  }

  /**
   * Adds the page number to a listing title, as in "Campaigns – Page 2".
   */
  private function pageTitle(string $title, int $page): string {
    return (string) $this->t('@title – Page @page', ['@title' => $title, '@page' => $page]);
  }

  /**
   * A country name from bfdb in the page's language (see CampaignPresenter).
   */
  private function placeName(mixed $name): string {
    $name = trim((string) ($name ?? ''));
    // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
    return $name === '' ? '' : (string) $this->t($name, [], ['context' => 'Country name']);
  }

  /**
   * The BCP 47 tag of the page's language for JSON-LD inLanguage.
   */
  public function languageTag(): string {
    $langcode = $this->languageManager->getCurrentLanguage()->getId();
    return $langcode === 'en' ? 'en-NZ' : $langcode;
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
