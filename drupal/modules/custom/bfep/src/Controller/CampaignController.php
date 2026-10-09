<?php

declare(strict_types=1);

namespace Drupal\bfep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\bfep\Repository\CampaignRepository;
use Drupal\bfep\Service\BfepCacheInvalidator;
use Drupal\bfep\Service\BfepSettings;
use Drupal\bfep\Service\CampaignListBuilder;
use Drupal\bfep\Service\CampaignPresenter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public campaign pages.
 */
final class CampaignController extends ControllerBase {

  public function __construct(
    protected CampaignRepository $campaigns,
    protected CampaignListBuilder $listBuilder,
    protected CampaignPresenter $presenter,
    protected BfepSettings $settings,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bfep.campaign_repository'),
      $container->get('bfep.campaign_list_builder'),
      $container->get('bfep.campaign_presenter'),
      $container->get('bfep.settings'),
    );
  }

  public function campaignList(Request $request): array|RedirectResponse {
    return $this->listBuilder->build($request);
  }

  public function detail(int|string $campaign_id): array {
    $campaignId = (int) $campaign_id;
    $row = $this->campaigns->find($campaignId);
    if ($row === NULL) {
      throw new NotFoundHttpException();
    }

    $related = [];
    $country = trim((string) ($row->country ?? ''));
    try {
      foreach ($this->campaigns->related($campaignId, $country) as $relatedRow) {
        $related[] = $this->presenter->card(
          $relatedRow,
          Url::fromRoute('bfep.campaign_detail', ['campaign_id' => (int) $relatedRow->id])->toString(),
        );
      }
    }
    catch (\Throwable $exception) {
      // The campaign itself still renders without suggestions.
      $this->getLogger('bfep')->warning('Related campaigns failed for @id: @message', [
        '@id' => $campaignId,
        '@message' => $exception->getMessage(),
      ]);
    }

    return [
      '#theme' => 'bfep_campaign_detail',
      '#attached' => ['library' => ['bfep/public']],
      '#campaign' => $this->presenter->detail($row),
      '#change_url' => Url::fromRoute('bfep.change_request', [], [
        'query' => ['campaign_id' => $campaignId],
      ])->toString(),
      '#campaigns_url' => Url::fromRoute('bfep.campaigns')->toString(),
      '#verification_url' => Url::fromRoute('bfep.trust_how_we_verify')->toString(),
      '#related' => $related,
      '#country_url' => $country !== '' ? Url::fromRoute('bfep.country_detail', ['country' => $country])->toString() : '',
      '#cache' => [
        'tags' => [BfepCacheInvalidator::CAMPAIGNS, 'bfep:campaign:' . $campaignId],
        'max-age' => $this->settings->publicCacheMaxAge(),
      ],
    ];
  }

}
