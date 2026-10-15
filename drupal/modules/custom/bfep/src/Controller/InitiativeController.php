<?php

declare(strict_types=1);

namespace Drupal\bfep\Controller;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Controller\ControllerBase;
use Drupal\bfep\Repository\InitiativeRepository;
use Drupal\bfep\Service\BfepCacheInvalidator;
use Drupal\bfep\Service\BfepSettings;
use Drupal\bfep\Service\CampaignPresenter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public ground-initiative listing.
 */
final class InitiativeController extends ControllerBase {

  public function __construct(
    protected InitiativeRepository $initiatives,
    protected BfepSettings $settings,
    protected CampaignPresenter $presenter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bfep.initiative_repository'),
      $container->get('bfep.settings'),
      $container->get('bfep.campaign_presenter'),
    );
  }

  public function list(): array {
    $items = [];
    foreach ($this->initiatives->featured() as $row) {
      $items[] = [
        'id' => (int) $row->id,
        'name' => trim((string) ($row->contact_name ?? '')) ?: (string) $this->t('Ground initiative #@id', ['@id' => (int) $row->id]),
        'country' => $this->presenter->placeName($row->country_raw ?? NULL),
        'type' => $this->presenter->placeName($row->initiative_type ?? NULL, 'Initiative type'),
        'description' => Unicode::truncate(
          trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) ($row->project_description ?? '')))),
          320,
          TRUE,
          TRUE,
        ),
      ];
    }

    return [
      '#theme' => 'bfep_initiative_list',
      '#attached' => ['library' => ['bfep/public']],
      '#initiatives' => $items,
      '#cache' => [
        'tags' => [BfepCacheInvalidator::INITIATIVES],
        'max-age' => $this->settings->listingCacheMaxAge(),
      ],
    ];
  }

}
