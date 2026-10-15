<?php

declare(strict_types=1);

namespace Drupal\bfep\Admin;

/**
 * Carries a staff list's filters, sort and page through its record forms.
 *
 * Links from a list to a record form add ?back=<the list's query string>, and
 * the form passes it along (to "save and review next", to fundraiser forms)
 * and uses it to return to the same view of the list after saving.
 *
 * Only the query string is carried, never a path, so it cannot redirect
 * anywhere but the form's own list. Drupal's ?destination= is not used
 * because it would override every redirect, including "review next".
 */
final class ListReturn {

  /**
   * The query parameter that holds the list's query string.
   */
  public const PARAM = 'back';

  /**
   * List query keys worth keeping: the filters, paging and table sort.
   */
  private const KEYS = ['q', 'status', 'featured', 'urgent', 'sync', 'check', 'per_page', 'p', 'order', 'sort'];

  /**
   * The query to add to a link from a list page to a record form.
   *
   * @param array $listQuery
   *   The list request's query parameters.
   *
   * @return array<string, string>
   *   ['back' => ...], or an empty array for the unfiltered first page.
   */
  public static function linkQuery(array $listQuery): array {
    $kept = self::clean($listQuery);
    return $kept ? [self::PARAM => http_build_query($kept)] : [];
  }

  /**
   * The list query to return to, from a record form's request query.
   *
   * @param array $formQuery
   *   The form request's query parameters.
   *
   * @return array<string, string>
   *   Query parameters for the list route; empty for the plain list.
   */
  public static function listQuery(array $formQuery): array {
    $back = $formQuery[self::PARAM] ?? NULL;
    if (!is_string($back) || $back === '' || strlen($back) > 2000) {
      return [];
    }
    parse_str($back, $parsed);
    return self::clean($parsed);
  }

  /**
   * Passes ?back= along from one record form to another.
   *
   * @param array $formQuery
   *   The current form request's query parameters.
   *
   * @return array<string, string>
   *   ['back' => ...], or an empty array.
   */
  public static function forward(array $formQuery): array {
    return self::linkQuery(self::listQuery($formQuery));
  }

  /**
   * Keeps the known, non-empty, single-valued list parameters.
   */
  private static function clean(array $query): array {
    $kept = [];
    foreach (self::KEYS as $key) {
      $value = $query[$key] ?? NULL;
      if (is_scalar($value) && (string) $value !== '') {
        $kept[$key] = (string) $value;
      }
    }
    return $kept;
  }

}
