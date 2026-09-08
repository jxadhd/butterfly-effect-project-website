<?php

declare(strict_types=1);

namespace Drupal\bfep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\bfep\Repository\CampaignRepository;
use Drupal\bfep\Service\BfepCacheInvalidator;
use Drupal\bfep\Service\BfepSettings;
use Drupal\bfep\Service\CampaignListBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public country directory and country campaign pages.
 */
final class CountryController extends ControllerBase {

  public function __construct(
    protected CampaignRepository $campaigns,
    protected CampaignListBuilder $listBuilder,
    protected BfepSettings $settings,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bfep.campaign_repository'),
      $container->get('bfep.campaign_list_builder'),
      $container->get('bfep.settings'),
    );
  }

  public function list(): array {
    $countries = [];
    foreach ($this->campaigns->countries() as $row) {
      $countries[] = [
        'name' => (string) $row->country,
        'url' => Url::fromRoute('bfep.country_detail', ['country' => (string) $row->country])->toString(),
        'region' => trim((string) ($row->global_region ?? '')),
        'campaign_count' => (int) $row->campaign_count,
        'featured_count' => (int) $row->featured_count,
        'urgent_count' => (int) $row->urgent_count,
      ];
    }

    return [
      '#theme' => 'bfep_country_list',
      '#attached' => ['library' => ['bfep/public']],
      '#countries' => $countries,
      '#cache' => [
        'tags' => [BfepCacheInvalidator::COUNTRIES, BfepCacheInvalidator::CAMPAIGNS],
        'max-age' => $this->settings->listingCacheMaxAge(),
      ],
    ];
  }

  public function detail(string $country, Request $request): array|RedirectResponse {
    $canonicalCountry = $this->campaigns->canonicalCountry($country);
    if ($canonicalCountry === NULL) {
      throw new NotFoundHttpException();
    }

    if ($canonicalCountry !== $country) {
      return new RedirectResponse(Url::fromRoute('bfep.country_detail', [
        'country' => $canonicalCountry,
      ], [
        'query' => $request->query->all(),
      ])->toString(), 301);
    }

    return $this->listBuilder->build($request, $canonicalCountry);
  }

}
