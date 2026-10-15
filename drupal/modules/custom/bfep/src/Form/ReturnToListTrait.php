<?php

declare(strict_types=1);

namespace Drupal\bfep\Form;

use Drupal\Core\Url;
use Drupal\bfep\Admin\ListReturn;

/**
 * Sends a staff form back to the list view it was opened from.
 *
 * @see \Drupal\bfep\Admin\ListReturn
 */
trait ReturnToListTrait {

  /**
   * The list route with the filters, sort and page from ?back=, if any.
   */
  protected function listUrl(string $listRoute): Url {
    return Url::fromRoute($listRoute, [], ['query' => ListReturn::listQuery($this->getRequest()->query->all())]);
  }

  /**
   * The ?back= query to pass on to another record form.
   *
   * @return array<string, string>
   *   ['back' => ...], or an empty array.
   */
  protected function backQuery(): array {
    return ListReturn::forward($this->getRequest()->query->all());
  }

}
