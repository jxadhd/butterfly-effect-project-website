<?php

declare(strict_types=1);

namespace Drupal\bfep\Service;

use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Applies privacy-conscious per-client and global submission rate limits.
 */
final class SubmissionGuard {

  public function __construct(
    private readonly FloodInterface $flood,
    private readonly RequestStack $requestStack,
    private readonly BfepSettings $settings,
    private readonly TranslationInterface $translation,
  ) {}

  public function validate(FormStateInterface $formState, string $formId): void {
    $limit = max(1, min(50, $this->settings->integer('submission_limit')));
    $window = max(60, min(86400, $this->settings->integer('submission_window')));
    $event = $this->eventName($formId);

    if (!$this->flood->isAllowed($event, $limit, $window, $this->clientIdentifier())) {
      $formState->setErrorByName('', $this->translation->translate('Too many submissions were received from this connection. Please wait and try again later.'));
    }

    if (!$this->flood->isAllowed($event . '.global', 250, $window, 'all')) {
      $formState->setErrorByName('', $this->translation->translate('This form is temporarily receiving too many submissions. Please try again later.'));
    }
  }

  public function register(string $formId): void {
    $event = $this->eventName($formId);
    $window = max(60, min(86400, $this->settings->integer('submission_window')));
    $this->flood->register($event, $window, $this->clientIdentifier());
    $this->flood->register($event . '.global', $window, 'all');
  }

  private function eventName(string $formId): string {
    return 'bfep.submission.' . preg_replace('/[^a-z0-9_]+/', '_', strtolower($formId));
  }

  private function clientIdentifier(): string {
    $request = $this->requestStack->getCurrentRequest();
    $address = $request?->getClientIp() ?: 'unknown';
    return hash('sha256', $address);
  }

}
