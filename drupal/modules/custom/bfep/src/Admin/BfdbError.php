<?php

declare(strict_types=1);

namespace Drupal\bfep\Admin;

use Drupal\Core\Database\DatabaseException;

/**
 * Turns a failed bfdb query into a plain-language explanation for staff.
 *
 * Kept free of services so it can be unit tested. Only the first line of the
 * PostgreSQL error is kept as detail: the rest of a Drupal database
 * exception message repeats the query and its arguments, which can include
 * contact details.
 */
final class BfdbError {

  /**
   * Explains a database failure, or returns NULL for any other exception.
   *
   * @return array{kind: string, title: string, message: string, action: string, detail: string}|null
   *   A short title, what happened, what to do, and the PostgreSQL error.
   *   Strings are plain English with @placeholders already filled in.
   */
  public static function explain(\Throwable $exception): ?array {
    $database = NULL;
    for ($e = $exception; $e !== NULL; $e = $e->getPrevious()) {
      if ($e instanceof DatabaseException || $e instanceof \PDOException) {
        $database = $e;
        break;
      }
      if ($e instanceof \RuntimeException && str_starts_with($e->getMessage(), 'bfdb is unavailable')) {
        return self::unreachable('');
      }
    }
    if ($database === NULL) {
      return NULL;
    }

    $message = $database->getMessage();
    $state = preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $message, $m) ? $m[1] : (string) $database->getCode();
    $detail = self::detail($message);

    if (str_starts_with($state, '08') || str_starts_with($state, '57P0') || $state === '7') {
      return self::unreachable($detail);
    }
    if ($state === '42501') {
      $object = preg_match('/permission denied for (?:table|view|relation|sequence|schema|function) ([A-Za-z0-9_.]+)/', $message, $m) ? $m[1] : '';
      $verb = preg_match('/:\s*(SELECT|INSERT|UPDATE|DELETE)\b/i', $message, $m) ? strtoupper($m[1]) : '';
      return [
        'kind' => 'permission',
        'title' => 'bfdb refused access',
        'message' => $object !== ''
          ? 'The database user this site connects as is not allowed to ' . ($verb !== '' ? $verb . ' on' : 'use') . ' ' . $object . '.'
          : 'The database user this site connects as is missing a permission this page needs.',
        'action' => $object !== '' && $verb !== ''
          ? 'Ask whoever manages bfdb to run: GRANT ' . $verb . ' ON ' . $object . ' TO <the site\'s database user>;'
          : 'Ask whoever manages bfdb to grant the missing permission shown below.',
        'detail' => $detail,
      ];
    }
    if (in_array($state, ['42P01', '42703', '42883', '42704'], TRUE)) {
      return [
        'kind' => 'schema',
        'title' => 'bfdb does not match what this page expects',
        'message' => 'A table, column, type or function this page uses is missing or different in bfdb.',
        'action' => 'Check that pending database updates have run (drush updb), then compare bfdb with the schema this version of the site expects.',
        'detail' => $detail,
      ];
    }
    if (in_array($state, ['22P02', '23502', '23503', '23505', '23514', '22001'], TRUE)) {
      return [
        'kind' => 'data',
        'title' => 'bfdb rejected a value',
        'message' => 'The database refused a value this page tried to use, such as an unknown status, a duplicate, or text that is too long.',
        'action' => 'Go back and check the record. If it keeps happening, send the detail below to whoever maintains the site.',
        'detail' => $detail,
      ];
    }
    if ($state === '57014') {
      return [
        'kind' => 'timeout',
        'title' => 'bfdb took too long',
        'message' => 'The query was cancelled because it ran for too long.',
        'action' => 'Try again in a moment, or narrow your search or filters.',
        'detail' => $detail,
      ];
    }
    return [
      'kind' => 'query',
      'title' => 'A bfdb query failed',
      'message' => 'The database returned an error for this page.',
      'action' => 'Try again. If it keeps failing, send the detail below to whoever maintains the site.',
      'detail' => $detail,
    ];
  }

  /**
   * The first line of the PostgreSQL error, without the query or arguments.
   */
  public static function detail(string $message): string {
    $line = strtok($message, "\n") ?: '';
    // DatabaseExceptionWrapper appends ": <query>; Array (...)" to the error.
    $line = preg_replace('/:\s*(SELECT|INSERT|UPDATE|DELETE|WITH)\b.*$/is', '', $line) ?? $line;
    $line = preg_replace('/^SQLSTATE\[[0-9A-Z]{5}\]:\s*[^:]*:\s*\d+\s*/', '', $line) ?? $line;
    return mb_strimwidth(rtrim(trim($line), ':'), 0, 300, '…');
  }

  private static function unreachable(string $detail): array {
    return [
      'kind' => 'unreachable',
      'title' => 'bfdb could not be reached',
      'message' => 'The external database did not answer, so this page cannot load its data.',
      'action' => 'Try again in a few minutes. If it continues, check that the bfdb server is running and reachable. The status report shows the connection state.',
      'detail' => $detail,
    ];
  }

}
