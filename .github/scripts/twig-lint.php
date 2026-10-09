<?php

/**
 * @file
 * Parses every Twig template under the given directories (syntax check only).
 *
 * Usage: php twig-lint.php <drupal-root> <dir> [<dir> ...]
 * Needs a Drupal codebase for Twig and Drupal's {% trans %} tag. Unknown
 * filters and functions are accepted, since only syntax is checked.
 */

declare(strict_types=1);

use Drupal\Core\Template\TwigTransTokenParser;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\Source;
use Twig\TwigFilter;
use Twig\TwigFunction;

[$script, $drupalRoot] = $argv + [NULL, NULL];
$dirs = array_slice($argv, 2);
if ($drupalRoot === NULL || !$dirs) {
  fwrite(STDERR, "Usage: php twig-lint.php <drupal-root> <dir> [<dir> ...]\n");
  exit(2);
}
require $drupalRoot . '/autoload.php';

$twig = new Environment(new ArrayLoader());
$twig->addTokenParser(new TwigTransTokenParser());
$twig->registerUndefinedFilterCallback(static fn(string $name) => new TwigFilter($name, static fn($value) => $value));
$twig->registerUndefinedFunctionCallback(static fn(string $name) => new TwigFunction($name, static fn() => ''));

$failures = 0;
$count = 0;
foreach ($dirs as $dir) {
  $files = new \RegexIterator(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)), '/\.twig$/');
  foreach ($files as $file) {
    $count++;
    $path = $file->getPathname();
    try {
      $twig->parse($twig->tokenize(new Source((string) file_get_contents($path), basename($path), $path)));
    }
    catch (SyntaxError $e) {
      $failures++;
      printf("::error file=%s,line=%d::%s\n", $path, $e->getTemplateLine(), $e->getRawMessage());
    }
  }
}
printf("%d template(s) checked, %d error(s).\n", $count, $failures);
exit($failures ? 1 : 0);
