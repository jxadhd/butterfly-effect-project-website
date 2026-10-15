<?php

declare(strict_types=1);

namespace Drupal\bfep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\bfep\Admin\AdminFormat;
use Drupal\bfep\Repository\InitiativeMedia;
use Drupal\bfep\Repository\InitiativeRepository;
use Drupal\bfep\Service\BfepCacheInvalidator;
use Drupal\bfep\Service\BfepSettings;
use Drupal\bfep\Service\CampaignPresenter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public ground-initiative listing and initiative photos.
 */
final class InitiativeController extends ControllerBase {

  public function __construct(
    protected InitiativeRepository $initiatives,
    protected BfepSettings $settings,
    protected CampaignPresenter $presenter,
    protected InitiativeMedia $media,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bfep.initiative_repository'),
      $container->get('bfep.settings'),
      $container->get('bfep.campaign_presenter'),
      $container->get('bfep.initiative_media'),
    );
  }

  public function list(): array {
    $rows = $this->initiatives->featured();
    $ids = array_map(static fn(object $row): int => (int) $row->id, $rows);
    try {
      $images = $this->media->imagesFor($ids);
      $links = $this->media->linksFor($ids);
    }
    catch (\Throwable $exception) {
      // Photos and links are extras; the list still shows without them.
      $this->getLogger('bfep')->warning('Ground initiative photos or links failed to load: @class', ['@class' => get_class($exception)]);
      $images = $links = [];
    }

    $items = [];
    foreach ($rows as $row) {
      $id = (int) $row->id;
      $name = trim((string) ($row->contact_name ?? '')) ?: (string) $this->t('Ground initiative #@id', ['@id' => $id]);
      $photos = [];
      foreach ($images[$id] ?? [] as $image) {
        $photos[] = [
          'url' => Url::fromRoute('bfep.initiative_image', ['image' => $image['id'] . '.' . $image['ext']])->toString(),
          'alt' => $image['alt'] !== '' ? $image['alt'] : $name,
          'width' => $image['width'],
          'height' => $image['height'],
        ];
      }
      $donate = [];
      foreach ($links[$id] ?? [] as $link) {
        $url = AdminFormat::externalUrl($link['url']);
        if ($url !== NULL) {
          $donate[] = ['url' => $url, 'label' => $link['label'] !== '' ? $link['label'] : (string) $this->t('Donate')];
        }
      }
      $items[] = [
        'id' => $id,
        'name' => $name,
        'country' => $this->presenter->placeName($row->country_raw ?? NULL),
        'type' => $this->presenter->placeName($row->initiative_type ?? NULL, 'Initiative type'),
        'description' => trim((string) strip_tags((string) ($row->project_description ?? ''))),
        'photos' => $photos,
        'links' => $donate,
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

  /**
   * One initiative photo, such as /initiatives/image/12.webp.
   *
   * Photos of featured initiatives are cached for a day, by browsers and
   * Cloudflare, so a removed photo can still be served from a cache for up
   * to a day. Staff can also see photos of initiatives that are not
   * featured, uncached.
   */
  public function image(string $image): Response {
    $image_id = (int) strtok($image, '.');
    $found = $image_id > 0 ? $this->media->imageData($image_id) : NULL;
    if ($found === NULL || $image !== $image_id . '.' . InitiativeMedia::extension($found['mime'])) {
      throw new NotFoundHttpException();
    }
    if (!$found['featured'] && !$this->currentUser()->hasPermission('administer bfep external data')) {
      throw new NotFoundHttpException();
    }
    return new Response($found['data'], 200, [
      'Content-Type' => $found['mime'],
      'Cache-Control' => $found['featured'] ? 'public, max-age=86400' : 'private, no-store',
      'X-Content-Type-Options' => 'nosniff',
    ]);
  }

}
