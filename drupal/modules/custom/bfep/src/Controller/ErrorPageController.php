<?php

declare(strict_types=1);

namespace Drupal\bfep\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Pages shown by BfdbExceptionSubscriber when a BFEP page fails on bfdb.
 */
final class ErrorPageController extends ControllerBase {

  /**
   * The staff version: what failed and what to do about it.
   */
  public function staff(Request $request): array {
    $error = (array) $request->attributes->get('_bfep_error', []);
    if (!$error) {
      throw new NotFoundHttpException();
    }
    $build = [
      '#attached' => ['library' => ['bfep/admin']],
      '#cache' => ['max-age' => 0],
      'notice' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['bfep-admin-notice', 'bfep-admin-notice--error'], 'role' => 'alert'],
        'title' => ['#markup' => '<p><strong>' . Html::escape($error['title']) . '</strong></p>'],
        'message' => ['#markup' => '<p>' . Html::escape($error['message']) . '</p>'],
        'action' => ['#markup' => '<p>' . $this->t('What to do: @action', ['@action' => $error['action']]) . '</p>'],
      ],
    ];
    if (($error['detail'] ?? '') !== '') {
      $build['detail'] = [
        '#type' => 'details',
        '#title' => $this->t('Technical detail'),
        'text' => ['#markup' => '<p><code>' . Html::escape($error['detail']) . '</code></p>'],
      ];
    }
    $build['links'] = [
      '#theme' => 'item_list',
      '#items' => array_filter([
        isset($error['retry']) ? ['#type' => 'link', '#title' => $this->t('Try again'), '#url' => Url::fromUserInput($error['retry'])] : NULL,
        $this->moduleHandler()->moduleExists('dblog') ? ['#type' => 'link', '#title' => $this->t('Recent log messages'), '#url' => Url::fromRoute('dblog.overview')] : NULL,
        ['#type' => 'link', '#title' => $this->t('Status report'), '#url' => Url::fromRoute('system.status')],
        ['#type' => 'link', '#title' => $this->t('BFEP Admin'), '#url' => Url::fromRoute('bfep.admin')],
      ]),
    ];
    return $build;
  }

  /**
   * The public version: no technical detail.
   */
  public function public(Request $request): array {
    if (!$request->attributes->has('_bfep_error')) {
      throw new NotFoundHttpException();
    }
    return [
      '#markup' => '<p>' . $this->t('The campaign directory is temporarily unavailable. Please try again in a few minutes.') . '</p>',
      '#cache' => ['max-age' => 0],
    ];
  }

}
