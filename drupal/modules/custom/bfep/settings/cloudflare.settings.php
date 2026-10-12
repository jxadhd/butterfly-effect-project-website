<?php

/**
 * @file
 * Trusts Cloudflare's edge servers as reverse proxies.
 *
 * Requests reach Drupal through Cloudflare and then Nginx Proxy Manager.
 * settings.php already trusts NPM, so without this file Drupal stops at the
 * Cloudflare edge address and records it as the visitor's IP. Every visitor
 * then shares a handful of addresses for logs, failed-login lockouts and
 * form flood control.
 *
 * Include it from sites/default/settings.php, after the reverse_proxy lines:
 *
 * @code
 * $bfep_cloudflare = $app_root . '/modules/custom/bfep/settings/cloudflare.settings.php';
 * if (file_exists($bfep_cloudflare)) {
 *   include $bfep_cloudflare;
 * }
 * @endcode
 *
 * Trusting these ranges is safe even if someone reaches NPM directly: NPM
 * appends the real connecting address to X-Forwarded-For, and that address
 * is not trusted, so a forged header cannot choose the client IP.
 *
 * Ranges from https://www.cloudflare.com/ips/ (checked 2026-10-12).
 */

if (!empty($settings['reverse_proxy'])) {
  $settings['reverse_proxy_addresses'] = array_merge($settings['reverse_proxy_addresses'] ?? [], [
    // IPv4.
    '173.245.48.0/20',
    '103.21.244.0/22',
    '103.22.200.0/22',
    '103.31.4.0/22',
    '141.101.64.0/18',
    '108.162.192.0/18',
    '190.93.240.0/20',
    '188.114.96.0/20',
    '197.234.240.0/22',
    '198.41.128.0/17',
    '162.158.0.0/15',
    '104.16.0.0/13',
    '104.24.0.0/14',
    '172.64.0.0/13',
    '131.0.72.0/22',
    // IPv6.
    '2400:cb00::/32',
    '2606:4700::/32',
    '2803:f800::/32',
    '2405:b500::/32',
    '2405:8100::/32',
    '2a06:98c0::/29',
    '2c0f:f248::/32',
  ]);
}
