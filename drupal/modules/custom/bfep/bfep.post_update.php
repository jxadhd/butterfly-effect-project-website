<?php

/**
 * @file
 * Post-update hooks for the Butterfly Effect Project module.
 */

/**
 * Moves the canonical base URL from butterfly.joshcross.co.nz to bfep.org.
 */
function bfep_post_update_bfep_org_domain(): string {
  $old = 'https://butterfly.joshcross.co.nz';
  $new = 'https://bfep.org';
  $factory = \Drupal::configFactory();
  $changed = [];

  // Only replace the old default, so a base URL staff set by hand is kept.
  $settings = $factory->getEditable('bfep.settings');
  if (rtrim((string) $settings->get('base_url'), '/') === $old) {
    $settings->set('base_url', $new)->save(TRUE);
    $changed[] = 'BFEP settings';
  }

  $sitemap = $factory->getEditable('simple_sitemap.settings');
  if (rtrim((string) $sitemap->get('base_url'), '/') === $old) {
    $sitemap->set('base_url', $new)->save(TRUE);
    $changed[] = 'Simple XML Sitemap';
  }

  return $changed
    ? 'Canonical base URL is now ' . $new . ' in: ' . implode(', ', $changed) . '. Regenerate the sitemap with drush simple-sitemap:generate.'
    : 'Canonical base URL was not the old default; left unchanged.';
}
