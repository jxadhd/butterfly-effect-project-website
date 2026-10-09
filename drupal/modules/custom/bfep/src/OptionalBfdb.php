<?php

declare(strict_types=1);

namespace Drupal\bfep;

use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Gets the bfdb connection for classes that must still load without it.
 *
 * The bfep.database service opens the connection when it is created, so a
 * class that takes it in create() fails to build while bfdb is down. Classes
 * that can show something useful anyway (the dashboard's notice, a public
 * form that keeps the visitor's answers) take it through this instead.
 */
final class OptionalBfdb {

  /**
   * The bfdb connection, or NULL when it cannot be opened.
   */
  public static function get(ContainerInterface $container): ?Connection {
    try {
      return $container->get('bfep.database');
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
