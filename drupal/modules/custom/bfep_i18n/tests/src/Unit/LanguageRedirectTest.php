<?php

declare(strict_types=1);

namespace Drupal\Tests\bfep_i18n\Unit;

use Drupal\Core\Session\SessionConfigurationInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\bfep_i18n\LanguageSettings;
use Drupal\bfep_i18n\StackMiddleware\LanguageRedirect;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @coversDefaultClass \Drupal\bfep_i18n\StackMiddleware\LanguageRedirect
 *
 * @group bfep_i18n
 */
final class LanguageRedirectTest extends UnitTestCase {

  private const ARABIC_BROWSER = 'ar-EG,ar;q=0.9,en;q=0.8';
  private const ENGLISH_BROWSER = 'en-NZ,en;q=0.9,ar;q=0.5';
  private const CHROME = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36';

  /**
   * Requests that reach the site, as "METHOD PATH".
   *
   * @var string[]
   */
  private array $handled = [];

  /**
   * @covers ::handle
   * @dataProvider redirectProvider
   */
  public function testRedirects(string $uri, array $headers, array $cookies, ?string $location): void {
    $response = $this->handle($uri, $headers, $cookies);
    if ($location === NULL) {
      $this->assertSame(200, $response->getStatusCode(), 'The page is shown.');
      $this->assertCount(1, $this->handled);
      return;
    }
    $this->assertSame(302, $response->getStatusCode());
    $this->assertSame($location, $response->headers->get('Location'));
    $this->assertSame([], $this->handled, 'A redirect does not build the page.');
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
  }

  public static function redirectProvider(): array {
    $arabic = ['Accept-Language' => self::ARABIC_BROWSER, 'User-Agent' => self::CHROME];
    $english = ['Accept-Language' => self::ENGLISH_BROWSER, 'User-Agent' => self::CHROME];
    return [
      'Arabic browser, first visit' => ['/campaigns?country=Palestine', $arabic, [], '/ar/campaigns?country=Palestine'],
      'Arabic browser, front page' => ['/', $arabic, [], '/ar'],
      'English browser stays' => ['/campaigns', $english, [], NULL],
      'Arabic page is not redirected' => ['/ar/campaigns', $english, [], NULL],
      'chose English' => ['/campaigns', $arabic, ['bfep_lang' => 'en'], NULL],
      'chose Arabic' => ['/countries', $english, ['bfep_lang' => 'ar'], '/ar/countries'],
      'unknown cookie falls back to the browser' => ['/countries', $arabic, ['bfep_lang' => 'fr'], '/ar/countries'],
      'search engine' => ['/campaigns', ['Accept-Language' => 'ar', 'User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)'], [], NULL],
      'link preview' => ['/campaigns/12', ['Accept-Language' => 'ar', 'User-Agent' => 'WhatsApp/2.23'], [], NULL],
      'staff page' => ['/admin/bfep', $arabic, [], NULL],
      'login' => ['/user/login', $arabic, ['bfep_lang' => 'ar'], NULL],
      'prefixed staff page' => ['/ar/admin/bfep?x=1', $arabic, [], '/admin/bfep?x=1'],
      'feed' => ['/campaigns/feed', $arabic, [], NULL],
      'file' => ['/robots.txt', $arabic, [], NULL],
      'switch to English' => ['/ar/campaigns?lang=en&country=Palestine', $arabic, [], '/campaigns?country=Palestine'],
      'switch to Arabic' => ['/campaigns/12?lang=ar', $english, ['bfep_lang' => 'en'], '/ar/campaigns/12'],
      'switch on a staff page' => ['/ar/user/login?lang=ar', $arabic, [], '/user/login'],
      'unknown switch value' => ['/campaigns?lang=xx', $english, [], NULL],
    ];
  }

  /**
   * The Languages settings: who can see Arabic, and the automatic choice.
   *
   * @covers ::handle
   * @dataProvider settingsProvider
   */
  public function testSettings(array $settings, bool $session, string $uri, array $headers, array $cookies, ?string $location): void {
    $this->handled = [];
    $request = Request::create($uri, cookies: $cookies, server: $this->server($headers));
    $response = $this->middleware($settings, $session)->handle($request);
    if ($location === NULL) {
      $this->assertSame(200, $response->getStatusCode(), 'The page is shown.');
      return;
    }
    $this->assertSame(302, $response->getStatusCode());
    $this->assertSame($location, $response->headers->get('Location'));
    $this->assertSame([], $response->headers->getCookies(), 'The choice is not stored.');
  }

  public static function settingsProvider(): array {
    $arabic = ['Accept-Language' => self::ARABIC_BROWSER, 'User-Agent' => self::CHROME];
    $staff = ['visibility' => ['ar' => 'staff']];
    $off = ['visibility' => ['ar' => 'off']];
    $manual = ['automatic_choice' => FALSE];
    return [
      'staff only: visitor stays on English' => [$staff, FALSE, '/campaigns', $arabic, [], NULL],
      'staff only: visitor who chose Arabic stays on English' => [$staff, FALSE, '/campaigns', $arabic, ['bfep_lang' => 'ar'], NULL],
      'staff only: Arabic page sends visitors to English' => [$staff, FALSE, '/ar/campaigns?page=2', $arabic, [], '/campaigns?page=2'],
      'staff only: switch link sends visitors to English' => [$staff, FALSE, '/ar/campaigns?lang=ar', $arabic, [], '/campaigns'],
      'staff only: staff see Arabic pages' => [$staff, TRUE, '/ar/campaigns', $arabic, [], NULL],
      'staff only: staff get the automatic choice' => [$staff, TRUE, '/campaigns', $arabic, [], '/ar/campaigns'],
      'off: staff are sent to English too' => [$off, TRUE, '/ar', $arabic, [], '/'],
      'off: no automatic choice' => [$off, TRUE, '/campaigns', $arabic, ['bfep_lang' => 'ar'], NULL],
      'automatic choice off: browser is ignored' => [$manual, FALSE, '/campaigns', $arabic, [], NULL],
      'automatic choice off: stored choice is ignored' => [$manual, FALSE, '/countries', $arabic, ['bfep_lang' => 'ar'], NULL],
      'automatic choice off: Arabic page stays' => [$manual, FALSE, '/ar/countries', $arabic, [], NULL],
    ];
  }

  /**
   * @covers ::handle
   */
  public function testSwitchWorksWithoutAutomaticChoice(): void {
    $request = Request::create('/campaigns?lang=ar', server: $this->server(['Accept-Language' => self::ENGLISH_BROWSER, 'User-Agent' => self::CHROME]));
    $response = $this->middleware(['automatic_choice' => FALSE])->handle($request);
    $this->assertSame('/ar/campaigns', $response->headers->get('Location'));
    $this->assertSame('ar', $response->headers->getCookies()[0]->getValue());

    $response = $this->middleware(['automatic_choice' => FALSE])->handle(Request::create('/campaigns', server: $this->server(['Accept-Language' => self::ARABIC_BROWSER, 'User-Agent' => self::CHROME])));
    $this->assertSame([], $response->getVary(), 'Nothing depends on the browser language.');
  }

  /**
   * @covers ::handle
   */
  public function testSwitchStoresTheChoice(): void {
    $response = $this->handle('/ar/campaigns?lang=en', ['Accept-Language' => self::ARABIC_BROWSER, 'User-Agent' => self::CHROME]);
    $cookies = $response->headers->getCookies();
    $this->assertCount(1, $cookies);
    $this->assertSame(LanguageRedirect::COOKIE, $cookies[0]->getName());
    $this->assertSame('en', $cookies[0]->getValue());
    $this->assertSame('lax', $cookies[0]->getSameSite());
    $this->assertGreaterThan(time() + 300 * 86400, $cookies[0]->getExpiresTime());
  }

  /**
   * @covers ::handle
   */
  public function testEnglishPagesVary(): void {
    $response = $this->handle('/campaigns', ['Accept-Language' => self::ENGLISH_BROWSER, 'User-Agent' => self::CHROME]);
    $this->assertSame(['Accept-Language', 'Cookie'], $response->getVary());

    $response = $this->handle('/campaigns', ['Accept-Language' => self::ARABIC_BROWSER, 'User-Agent' => self::CHROME]);
    $this->assertSame(['Accept-Language', 'Cookie'], $response->getVary());
  }

  /**
   * @covers ::handle
   */
  public function testOnlyPageRequestsAreRedirected(): void {
    $headers = ['Accept-Language' => self::ARABIC_BROWSER, 'User-Agent' => self::CHROME];

    $post = Request::create('/campaigns', 'POST', server: $this->server($headers));
    $this->middleware()->handle($post);
    $ajax = Request::create('/campaigns', server: $this->server($headers + ['X-Requested-With' => 'XMLHttpRequest']));
    $this->middleware()->handle($ajax);
    $json = Request::create('/campaigns', server: $this->server($headers + ['Accept' => 'application/json']));
    $this->middleware()->handle($json);
    $sub = Request::create('/campaigns', server: $this->server($headers));
    $this->middleware()->handle($sub, HttpKernelInterface::SUB_REQUEST);

    $this->assertSame(['POST /campaigns', 'GET /campaigns', 'GET /campaigns', 'GET /campaigns'], $this->handled);
  }

  /**
   * @covers ::handle
   */
  public function testEnglishOnlySiteIsUntouched(): void {
    $middleware = new LanguageRedirect($this->kernel(), new LanguageSettings($this->getConfigFactoryStub([
      'language.negotiation' => ['url' => ['source' => 'path_prefix', 'prefixes' => ['en' => '']]],
    ])), $this->createMock(SessionConfigurationInterface::class));
    $request = Request::create('/campaigns?lang=ar', server: $this->server(['Accept-Language' => 'ar', 'User-Agent' => self::CHROME]));
    $response = $middleware->handle($request);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame([], $response->getVary());
  }

  /**
   * Sends a GET request through the middleware.
   */
  private function handle(string $uri, array $headers, array $cookies = []): Response {
    $this->handled = [];
    return $this->middleware()->handle(Request::create($uri, cookies: $cookies, server: $this->server($headers)));
  }

  /**
   * The middleware for a site with English and Arabic.
   *
   * @param array $settings
   *   The bfep_i18n.settings values. Arabic is public by default.
   * @param bool $session
   *   Whether requests have a session, as signed-in staff do.
   */
  private function middleware(array $settings = [], bool $session = FALSE): LanguageRedirect {
    $sessionConfiguration = $this->createMock(SessionConfigurationInterface::class);
    $sessionConfiguration->method('hasSession')->willReturn($session);
    return new LanguageRedirect($this->kernel(), new LanguageSettings($this->getConfigFactoryStub([
      'language.negotiation' => ['url' => ['source' => 'path_prefix', 'prefixes' => ['en' => '', 'ar' => 'ar']]],
      'bfep_i18n.settings' => $settings,
      'system.site' => ['default_langcode' => 'en'],
    ])), $sessionConfiguration);
  }

  /**
   * The site behind the middleware: records each request it receives.
   */
  private function kernel(): HttpKernelInterface {
    $kernel = $this->createMock(HttpKernelInterface::class);
    $kernel->method('handle')->willReturnCallback(function (Request $request): Response {
      $this->handled[] = $request->getMethod() . ' ' . $request->getPathInfo();
      return new Response('page');
    });
    return $kernel;
  }

  /**
   * Request headers in the form Request::create() takes them.
   */
  private function server(array $headers): array {
    $server = [];
    foreach ($headers as $name => $value) {
      $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    return $server;
  }

}
