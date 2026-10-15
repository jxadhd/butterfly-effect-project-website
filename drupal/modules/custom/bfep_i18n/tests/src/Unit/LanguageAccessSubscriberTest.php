<?php

declare(strict_types=1);

namespace Drupal\Tests\bfep_i18n\Unit;

use Drupal\Core\Session\AccountInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\bfep_i18n\EventSubscriber\LanguageAccessSubscriber;
use Drupal\bfep_i18n\LanguageSettings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * @coversDefaultClass \Drupal\bfep_i18n\EventSubscriber\LanguageAccessSubscriber
 *
 * @group bfep_i18n
 */
final class LanguageAccessSubscriberTest extends UnitTestCase {

  /**
   * @covers ::onRequest
   * @dataProvider accessProvider
   */
  public function testAccess(string $visibility, array $permissions, string $uri, ?string $location): void {
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturnCallback(fn (string $permission): bool => in_array($permission, $permissions, TRUE));
    $settings = new LanguageSettings($this->getConfigFactoryStub([
      'language.negotiation' => ['url' => ['source' => 'path_prefix', 'prefixes' => ['en' => '', 'ar' => 'ar']]],
      'bfep_i18n.settings' => ['visibility' => ['ar' => $visibility]],
      'system.site' => ['default_langcode' => 'en'],
    ]));
    $event = new RequestEvent($this->createMock(HttpKernelInterface::class), Request::create($uri), HttpKernelInterface::MAIN_REQUEST);

    (new LanguageAccessSubscriber($settings, $account))->onRequest($event);

    if ($location === NULL) {
      $this->assertNull($event->getResponse(), 'The page is shown.');
      return;
    }
    $response = $event->getResponse();
    $this->assertSame(302, $response->getStatusCode());
    $this->assertSame($location, $response->headers->get('Location'));
    $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    $this->assertSame('en', $response->headers->getCookies()[0]->getValue(), 'The visitor is not sent back to Arabic.');
  }

  public static function accessProvider(): array {
    return [
      'public' => ['public', [], '/ar/campaigns', NULL],
      'staff only, staff' => ['staff', ['administer bfep external data'], '/ar/campaigns', NULL],
      'staff only, translator' => ['staff', ['translate interface'], '/ar', NULL],
      'staff only, someone else' => ['staff', [], '/ar/campaigns?lang=ar&page=2', '/campaigns?page=2'],
      'staff only, English page' => ['staff', [], '/campaigns', NULL],
      'off, staff' => ['off', ['administer bfep external data', 'administer bfep languages'], '/ar', '/'],
    ];
  }

}
