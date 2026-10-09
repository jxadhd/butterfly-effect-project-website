<?php

declare(strict_types=1);

namespace Drupal\bfep\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\bfep\Admin\AdminFormat;
use Drupal\bfep\Repository\CampaignRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Opens the result directly from the site search when it is unambiguous.
 *
 * On the Butterfly database search page (/search/database), a query that is
 * only a campaign line number ("142" or "#142") opens that campaign, and a
 * query with exactly one result opens that result. Staff are sent to the
 * same place the result link would take them (for campaigns, the edit form).
 */
final class SearchShortcutSubscriber implements EventSubscriberInterface {

  private const SEARCH_PAGE = 'bfep_database';

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly CampaignRepository $campaigns,
    private readonly LoggerInterface $logger,
  ) {}

  public static function getSubscribedEvents(): array {
    // After routing and access checks (RouterListener runs at 32).
    return [KernelEvents::REQUEST => ['onRequest', 0]];
  }

  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest() || $this->routeMatch->getRouteName() !== 'search.view_' . self::SEARCH_PAGE) {
      return;
    }
    $request = $event->getRequest();
    $keys = trim((string) $request->query->get('keys', ''));
    // Only the first page of a fresh search; paging through results stays put.
    if ($keys === '' || (int) $request->query->get('page', 0) > 0) {
      return;
    }

    try {
      if (($line = AdminFormat::lineNumberQuery($keys)) !== NULL
        && ($id = $this->campaigns->idForLineNumber($line)) !== NULL) {
        $route = $this->currentUser->hasPermission('administer bfep external data')
          ? 'bfep.admin_campaign_edit'
          : 'bfep.campaign_detail';
        $event->setResponse(new RedirectResponse(Url::fromRoute($route, ['campaign_id' => $id])->toString(), 302));
        return;
      }

      /** @var \Drupal\search\SearchPageInterface|null $page */
      $page = $this->entityTypeManager->getStorage('search_page')->load(self::SEARCH_PAGE);
      if ($page === NULL || !$page->status()) {
        return;
      }
      $plugin = $page->getPlugin();
      $plugin->setSearch($keys, $request->query->all(), $request->attributes->all());
      if (!$plugin->isSearchExecutable()) {
        return;
      }
      $results = $plugin->execute();
      if (count($results) === 1 && !empty($results[0]['link'])) {
        $link = (string) $results[0]['link'];
        // Result links are absolute URLs on this site; never follow others.
        if (str_starts_with($link, $request->getSchemeAndHttpHost() . '/')) {
          $event->setResponse(new TrustedRedirectResponse($link, 302));
        }
      }
    }
    catch (\Throwable $exception) {
      // The normal results page still works; just skip the shortcut.
      $this->logger->warning('Search shortcut skipped: @message', ['@message' => $exception->getMessage()]);
    }
  }

}
