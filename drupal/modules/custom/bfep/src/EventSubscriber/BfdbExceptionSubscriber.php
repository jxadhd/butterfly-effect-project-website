<?php

declare(strict_types=1);

namespace Drupal\bfep\EventSubscriber;

use Drupal\Core\Url;
use Drupal\bfep\Admin\BfdbError;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Replaces the generic error page when a BFEP page fails on bfdb.
 *
 * Staff pages explain what went wrong (unreachable, missing permission,
 * schema mismatch, rejected value) and what to do. Public pages say the
 * directory is temporarily unavailable, without technical detail. Drupal
 * still logs the full exception first, because its logging subscriber runs
 * at a higher priority.
 */
final class BfdbExceptionSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly HttpKernelInterface $httpKernel,
    private readonly LoggerInterface $logger,
  ) {}

  public static function getSubscribedEvents(): array {
    // After ExceptionLoggingSubscriber (50), before Drupal's HTML error
    // pages (-128 and lower).
    return [KernelEvents::EXCEPTION => ['onException', 0]];
  }

  public function onException(ExceptionEvent $event): void {
    $request = $event->getRequest();
    $route = (string) $request->attributes->get('_route');
    if (!$event->isMainRequest() || !str_starts_with($route, 'bfep.')
      || $request->getRequestFormat() !== 'html'
      || in_array($route, ['bfep.admin_error', 'bfep.unavailable'], TRUE)) {
      return;
    }
    $explanation = BfdbError::explain($event->getThrowable());
    if ($explanation === NULL) {
      return;
    }
    if ($route === 'bfep.campaign_feed') {
      $event->allowCustomResponseCode();
      $event->setResponse(new Response('The campaign feed is temporarily unavailable.', 503, [
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Retry-After' => '300',
        'Cache-Control' => 'no-store, private',
      ]));
      return;
    }

    $staff = str_starts_with($route, 'bfep.admin') || $route === 'bfep.settings';
    $target = $staff ? 'bfep.admin_error' : 'bfep.unavailable';
    $sub = Request::create(Url::fromRoute($target)->toString(), 'GET', [], $request->cookies->all(), [], $request->server->all());
    if ($request->hasSession()) {
      $sub->setSession($request->getSession());
    }
    $sub->attributes->set('_bfep_error', $explanation + ['retry' => $request->getRequestUri()]);

    try {
      $response = $this->httpKernel->handle($sub, HttpKernelInterface::SUB_REQUEST);
    }
    catch (\Throwable $exception) {
      // Leave the original exception to Drupal's own error page.
      $this->logger->warning('Could not show the bfdb error page: @message', ['@message' => $exception->getMessage()]);
      return;
    }
    $response->setStatusCode($explanation['kind'] === 'unreachable' ? 503 : 500);
    if ($explanation['kind'] === 'unreachable') {
      $response->headers->set('Retry-After', '300');
    }
    $response->headers->set('Cache-Control', 'no-store, private');
    $event->allowCustomResponseCode();
    $event->setResponse($response);
  }

}
