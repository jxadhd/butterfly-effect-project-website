<?php

declare(strict_types=1);

namespace Drupal\bfep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\bfep\Service\BfepSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A short, staff-only guide to the BFEP admin workflow.
 *
 * The full guide lives elsewhere (its link is a BFEP setting); this page is
 * the quick reference next to the tools it describes.
 */
final class StaffGuideController extends ControllerBase {

  public function __construct(
    private readonly BfepSettings $settings,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('bfep.settings'));
  }

  public function page(): array {
    $guideUrl = $this->settings->string('staff_guide_url');
    $settingsUrl = Url::fromRoute('bfep.settings');

    $pending = ['query' => ['status' => 'pending']];
    $links = [
      'dashboard' => Url::fromRoute('bfep.admin')->toString(),
      'add_campaign' => Url::fromRoute('bfep.admin_campaign_add')->toString(),
      'pending_referrals' => Url::fromRoute('bfep.admin_referrals', [], $pending)->toString(),
      'pending_changes' => Url::fromRoute('bfep.admin_changes', [], $pending)->toString(),
      'pending_volunteers' => Url::fromRoute('bfep.admin_volunteers', [], $pending)->toString(),
    ];

    return [
      '#theme' => 'bfep_staff_guide',
      '#guide_url' => preg_match('#^https://#i', $guideUrl) ? $guideUrl : '',
      '#settings_url' => $settingsUrl->access() ? $settingsUrl->toString() : '',
      '#links' => $links,
      '#attached' => ['library' => ['bfep/admin']],
      '#cache' => [
        'tags' => ['config:' . BfepSettings::CONFIG_NAME],
        'contexts' => ['user.permissions'],
      ],
    ];
  }

}
