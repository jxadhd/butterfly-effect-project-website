<?php

namespace Drupal\bfep_oidc\EventSubscriber;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\externalauth\Event\ExternalAuthEvents;
use Drupal\externalauth\Event\ExternalAuthLoginEvent;
use Drupal\oidc\OpenidConnectSessionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Keeps the Butterfly role in sync with membership of the authentik group.
 *
 * Runs on every OIDC login, after the oidc module's own UpdateUserSubscriber
 * (priority 1000). Only the Butterfly role is ever added or removed.
 */
class StaffGroupRoleSubscriber implements EventSubscriberInterface {

  /**
   * Machine name of the Drupal role (see /admin/people/roles).
   */
  const ROLE_ID = 'butterfly';

  /**
   * Exact name of the authentik group, as it appears in the groups claim.
   */
  const GROUP_NAME = 'Staff Users';

  /**
   * Name of the claim that lists the user's authentik groups.
   */
  const GROUPS_CLAIM = 'groups';

  public function __construct(
    protected OpenidConnectSessionInterface $session,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerInterface $logger,
  ) {}

  public static function getSubscribedEvents(): array {
    $events[ExternalAuthEvents::LOGIN][] = ['onLogin', 0];
    return $events;
  }

  public function onLogin(ExternalAuthLoginEvent $event): void {
    $plugin_id = $this->session->getRealmPluginId();
    if (!$plugin_id || $event->getProvider() !== 'oidc:' . $plugin_id) {
      return;
    }

    $tokens = $this->session->getJsonWebTokens();
    if ($tokens === NULL) {
      return;
    }

    $groups = $tokens->getClaim(self::GROUPS_CLAIM);
    if (is_string($groups)) {
      $groups = [$groups];
    }
    if (!is_array($groups)) {
      // Claim not present at all (e.g. the profile scope was not granted).
      // Leave roles untouched rather than stripping them by mistake.
      $this->logger->warning('No "@claim" claim in the OIDC response for @user; roles left unchanged.', [
        '@claim' => self::GROUPS_CLAIM,
        '@user' => $event->getAccount()->getAccountName(),
      ]);
      return;
    }

    if (!$this->entityTypeManager->getStorage('user_role')->load(self::ROLE_ID)) {
      $this->logger->error('Role "@role" does not exist; cannot sync OIDC group membership.', [
        '@role' => self::ROLE_ID,
      ]);
      return;
    }

    $account = $event->getAccount();
    $in_group = in_array(self::GROUP_NAME, $groups, TRUE);
    $has_role = $account->hasRole(self::ROLE_ID);

    if ($in_group && !$has_role) {
      $account->addRole(self::ROLE_ID);
      $account->save();
      $this->logger->info('Granted @role to @user (member of @group).', [
        '@role' => self::ROLE_ID,
        '@user' => $account->getAccountName(),
        '@group' => self::GROUP_NAME,
      ]);
    }
    elseif (!$in_group && $has_role) {
      $account->removeRole(self::ROLE_ID);
      $account->save();
      $this->logger->info('Removed @role from @user (no longer in @group).', [
        '@role' => self::ROLE_ID,
        '@user' => $account->getAccountName(),
        '@group' => self::GROUP_NAME,
      ]);
    }
  }

}
