<?php

declare(strict_types=1);

namespace Drupal\bfep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\bfep\Repository\CampaignRepository;
use Drupal\bfep\Service\BfepCacheInvalidator;
use Drupal\bfep\Service\BfepSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Homepage and project overview.
 */
final class HomeController extends ControllerBase {

  public function __construct(
    protected CampaignRepository $campaigns,
    protected BfepSettings $settings,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('bfep.campaign_repository'),
      $container->get('bfep.settings'),
    );
  }

  public function home(): array {
    return [
      '#theme' => 'bfep_home',
      '#attached' => ['library' => ['bfep/public']],
      '#organization' => $this->settings->organizationName(),
      '#tagline' => $this->settings->string('tagline'),
      '#stats' => $this->campaigns->stats(),
      '#links' => [
        'campaigns' => Url::fromRoute('bfep.campaigns')->toString(),
        'countries' => Url::fromRoute('bfep.countries')->toString(),
        'refer' => Url::fromRoute('bfep.refer')->toString(),
        'volunteer' => Url::fromRoute('bfep.volunteer')->toString(),
        'about' => Url::fromRoute('bfep.about')->toString(),
        'transparency' => Url::fromRoute('bfep.trust_transparency')->toString(),
        'verify' => Url::fromRoute('bfep.trust_how_we_verify')->toString(),
        'change' => Url::fromRoute('bfep.change_request')->toString(),
      ],
      '#cache' => [
        'tags' => [BfepCacheInvalidator::HOME, BfepCacheInvalidator::CAMPAIGNS, 'config:bfep.settings'],
        'max-age' => $this->settings->publicCacheMaxAge(),
      ],
    ];
  }

  public function about(): array {
    return [
      '#theme' => 'bfep_trust_page',
      '#attached' => ['library' => ['bfep/public']],
      '#intro' => 'The Butterfly Effect Project is a volunteer-led directory that helps humanitarian fundraisers be found, reviewed, and kept current.',
      '#sections' => [
        [
          'heading' => 'Our purpose',
          'paragraphs' => [
            'We organise selected public fundraiser information into a searchable directory so people can discover campaigns by country, urgency, and other useful signals.',
            'We do not collect donations through this website. Supporters follow an external link and decide for themselves whether to donate on the fundraiser’s own platform.',
          ],
        ],
        [
          'heading' => 'Keeping records useful',
          'paragraphs' => [
            'Campaign information changes. Referral and update forms let families, organisers, and community members alert the team to new or inaccurate information.',
            'A listing is not a guarantee of future conduct or outcomes. Read the fundraiser, platform terms, and available evidence before donating.',
          ],
        ],
      ],
      '#actions' => [
        ['label' => 'Browse campaigns', 'url' => Url::fromRoute('bfep.campaigns')->toString(), 'primary' => TRUE],
        ['label' => 'How verification works', 'url' => Url::fromRoute('bfep.trust_how_we_verify')->toString()],
        ['label' => 'Request an update', 'url' => Url::fromRoute('bfep.change_request')->toString()],
      ],
      '#cache' => [
        'tags' => ['config:bfep.settings'],
        'max-age' => $this->settings->publicCacheMaxAge(),
      ],
    ];
  }

}
