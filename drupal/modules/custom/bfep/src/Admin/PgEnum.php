<?php

declare(strict_types=1);

namespace Drupal\bfep\Admin;

use Drupal\Core\Database\Connection;

/**
 * Reads the allowed values of a PostgreSQL enum column.
 *
 * Some bfdb columns, such as referral_submissions.verification_status, are
 * enums in production but plain text in other copies of the schema. Staff
 * forms use this to offer only values the column will accept.
 */
final class PgEnum {

  /**
   * The enum labels of a column, in their defined order.
   *
   * @return string[]|null
   *   NULL when the column is not an enum or the lookup fails.
   */
  public static function labels(Connection $db, string $table, string $column): ?array {
    try {
      $labels = $db->query(<<<'SQL'
        SELECT e.enumlabel
        FROM pg_attribute a
        INNER JOIN pg_enum e ON e.enumtypid = a.atttypid
        WHERE a.attrelid = CAST(:table AS regclass)
          AND a.attname = :column
          AND NOT a.attisdropped
        ORDER BY e.enumsortorder
        SQL, [':table' => $table, ':column' => $column])->fetchCol();
    }
    catch (\Throwable) {
      return NULL;
    }
    return $labels ? array_map('strval', $labels) : NULL;
  }

}
