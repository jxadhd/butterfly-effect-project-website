<?php

declare(strict_types=1);

namespace Drupal\bfep_i18n\StackMiddleware;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Sends visitors to the language they read in.
 *
 * English pages have no path prefix and other languages have one, such as
 * /ar. On an English public page this middleware redirects to the same page
 * in another language when:
 * - the visitor chose that language with the language switch (remembered in
 *   the bfep_lang cookie), or
 * - they have not chosen yet and their browser's Accept-Language puts that
 *   language before English.
 *
 * The switch's links carry ?lang=xx. A request with it stores the choice and
 * redirects to the clean URL, so choosing English on an Arabic page sticks
 * even though English URLs have no prefix.
 *
 * Staff pages (/admin, /user and so on) are always English: their prefixed
 * form redirects to the unprefixed one, and they are never redirected to
 * another language.
 *
 * Search engines and link-preview bots are never redirected, so each URL
 * shows them one language and hreflang links tie the versions together.
 */
final class LanguageRedirect implements HttpKernelInterface {

  /**
   * The cookie holding the visitor's chosen language.
   */
  public const COOKIE = 'bfep_lang';

  /**
   * The query argument the language switch adds to its links.
   */
  public const QUERY = 'lang';

  /**
   * Path prefixes that are staff-only or not pages, so never redirected.
   */
  private const EXCLUDED = [
    '/admin', '/user', '/batch', '/system', '/core', '/sites', '/modules',
    '/themes', '/profiles', '/libraries', '/vendor', '/cron', '/update.php',
    '/install.php', '/oidc', '/openid-connect', '/contextual', '/editor',
    '/filter', '/ckeditor5', '/history', '/big_pipe', '/session', '/jsonapi',
    '/rest', '/media', '/node/add', '/sitemap', '/robots.txt',
    '/campaigns/feed', '/directory-unavailable', '/.well-known', '/config_translation',
  ];

  /**
   * Paths that are staff pages: always English, even with a prefix.
   */
  private const STAFF = ['/admin', '/user', '/batch', '/node/add'];

  /**
   * User agents that must see each URL in its own language.
   */
  private const BOTS = '/bot|crawl|spider|slurp|facebookexternalhit|embedly|preview|whatsapp|telegram|discord|lighthouse|validator|monitor|curl|wget/i';

  public function __construct(
    private readonly HttpKernelInterface $httpKernel,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
    if ($type !== self::MAIN_REQUEST || !$request->isMethodSafe()) {
      return $this->httpKernel->handle($request, $type, $catch);
    }

    $prefixes = $this->prefixes();
    if (count($prefixes) < 2) {
      return $this->httpKernel->handle($request, $type, $catch);
    }
    [$langcode, $path] = $this->split($request->getPathInfo(), $prefixes);

    // A choice made with the language switch: remember it, then show the
    // clean URL in that language.
    $chosen = $request->query->all()[self::QUERY] ?? NULL;
    if (is_string($chosen) && isset($prefixes[$chosen])) {
      $query = $request->query->all();
      unset($query[self::QUERY]);
      $target = $this->staff($path) ? $path : $this->localize($path, $prefixes[$chosen]);
      $response = $this->redirect($request, $target, $query);
      $response->headers->setCookie(Cookie::create(
        self::COOKIE,
        $chosen,
        strtotime('+1 year'),
        '/',
        NULL,
        $request->isSecure(),
        FALSE,
        FALSE,
        Cookie::SAMESITE_LAX,
      ));
      return $response;
    }

    // Staff pages are English only.
    if ($langcode !== NULL && $prefixes[$langcode] !== '' && $this->staff($path)) {
      return $this->redirect($request, $path, $request->query->all());
    }

    $isDefault = $langcode === NULL || $prefixes[$langcode] === '';
    $negotiable = $isDefault && $this->isPage($request, $path);
    if ($negotiable && ($preferred = $this->preferred($request, $prefixes)) !== NULL && $prefixes[$preferred] !== '') {
      $response = $this->redirect($request, $this->localize($path, $prefixes[$preferred]), $request->query->all());
      $response->setVary(['Accept-Language', 'Cookie'], FALSE);
      return $response;
    }

    $response = $this->httpKernel->handle($request, $type, $catch);
    if ($negotiable) {
      // The same English URL redirects some visitors, so shared caches must
      // not hand one visitor's answer to another.
      $response->setVary(['Accept-Language', 'Cookie'], FALSE);
    }
    return $response;
  }

  /**
   * The language a visitor wants, if they have chosen or their browser says.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   * @param array<string, string> $prefixes
   *   Path prefixes keyed by langcode.
   *
   * @return string|null
   *   A langcode with a prefix entry, or NULL to leave the page as it is.
   */
  private function preferred(Request $request, array $prefixes): ?string {
    $cookie = $request->cookies->get(self::COOKIE);
    if (is_string($cookie) && isset($prefixes[$cookie])) {
      return $cookie;
    }
    if (preg_match(self::BOTS, (string) $request->headers->get('User-Agent', ''))) {
      return NULL;
    }
    // Browsers list languages best first, e.g. ar-EG, ar, en-GB, en.
    foreach ($request->getLanguages() as $language) {
      $primary = strtolower(strtok(str_replace('_', '-', $language), '-'));
      if (isset($prefixes[$primary])) {
        return $primary;
      }
    }
    return NULL;
  }

  /**
   * Whether the request is for a public HTML page that may be redirected.
   */
  private function isPage(Request $request, string $path): bool {
    if ($request->isXmlHttpRequest() || $request->query->has('_wrapper_format')) {
      return FALSE;
    }
    $accept = (string) $request->headers->get('Accept', '');
    if ($accept !== '' && !str_contains($accept, 'text/html') && !str_contains($accept, '*/*')) {
      return FALSE;
    }
    // Files such as /robots.txt or /sitemap.xml.
    if (preg_match('/\.[a-z0-9]{2,5}$/i', $path)) {
      return FALSE;
    }
    foreach (self::EXCLUDED as $excluded) {
      if ($path === $excluded || str_starts_with($path, $excluded . '/')) {
        return FALSE;
      }
    }
    return TRUE;
  }

  private function staff(string $path): bool {
    foreach (self::STAFF as $staff) {
      if ($path === $staff || str_starts_with($path, $staff . '/')) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Splits a path into its language and the path without the prefix.
   *
   * @return array{0: string|null, 1: string}
   *   The langcode whose non-empty prefix starts the path (or NULL when none
   *   does), and the path without it.
   */
  private function split(string $path, array $prefixes): array {
    $first = explode('/', ltrim($path, '/'), 2);
    foreach ($prefixes as $langcode => $prefix) {
      if ($prefix !== '' && $first[0] === $prefix) {
        return [$langcode, '/' . ($first[1] ?? '')];
      }
    }
    return [NULL, $path];
  }

  private function localize(string $path, string $prefix): string {
    if ($prefix === '') {
      return $path;
    }
    return '/' . $prefix . ($path === '/' ? '' : $path);
  }

  private function redirect(Request $request, string $path, array $query): RedirectResponse {
    $url = $request->getBasePath() . $path;
    if ($query) {
      $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    $response = new RedirectResponse($url, 302);
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');
    return $response;
  }

  /**
   * Path prefixes keyed by langcode, from the language module's settings.
   *
   * @return array<string, string>
   *   For example ['en' => '', 'ar' => 'ar'].
   */
  private function prefixes(): array {
    $config = $this->configFactory->get('language.negotiation');
    if ($config->get('url.source') !== 'path_prefix') {
      return [];
    }
    return array_map('strval', (array) $config->get('url.prefixes'));
  }

}
