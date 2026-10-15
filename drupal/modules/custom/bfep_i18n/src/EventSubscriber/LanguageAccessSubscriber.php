<?php

declare(strict_types=1);

namespace Drupal\bfep_i18n\EventSubscriber;

use Drupal\Core\Session\AccountInterface;
use Drupal\bfep_i18n\LanguageSettings;
use Drupal\bfep_i18n\StackMiddleware\LanguageRedirect;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Keeps staff-only languages to staff.
 *
 * LanguageRedirect lets any request with a session reach a staff-only
 * language, because it runs before Drupal knows who the visitor is. This
 * checks the account once it does, and sends anyone else to the page in the
 * default language.
 */
final class LanguageAccessSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly LanguageSettings $settings,
    private readonly AccountInterface $currentUser,
  ) {}

  public static function getSubscribedEvents(): array {
    // After authentication (300), before routing and page building.
    return [KernelEvents::REQUEST => ['onRequest', 250]];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    $prefixes = $this->settings->prefixes();
    if (count($prefixes) < 2) {
      return;
    }
    [$langcode, $path] = LanguageSettings::split($request->getPathInfo(), $prefixes);
    if ($langcode === NULL || $this->settings->canSee($langcode, $this->currentUser)) {
      return;
    }

    $default = $this->settings->defaultLangcode();
    $url = $request->getBasePath() . LanguageSettings::localize($path, $prefixes[$default] ?? '');
    $query = $request->query->all();
    unset($query[LanguageRedirect::QUERY]);
    if ($query) {
      $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
    $response = new RedirectResponse($url, 302);
    $response->setPrivate();
    $response->headers->addCacheControlDirective('no-store');
    // Replace a stored choice of this language, so the next page in the
    // default language does not send them straight back.
    $response->headers->setCookie(Cookie::create(
      LanguageRedirect::COOKIE,
      $default,
      strtotime('+1 year'),
      '/',
      NULL,
      $request->isSecure(),
      FALSE,
      FALSE,
      Cookie::SAMESITE_LAX,
    ));
    $event->setResponse($response);
  }

}
