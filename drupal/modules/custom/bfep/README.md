# Butterfly Effect Project (`bfep`)

Custom Drupal 11 module backed by the external `bfdb` PostgreSQL connection.

## Public features

- Cacheable campaign, country, initiative, home, and accountability pages.
- Canonical browser titles, descriptions, robots directives, Open Graph,
  Twitter Cards, breadcrumb JSON-LD, and organisation JSON-LD.
- A clean native-GET campaign filter without Form API state parameters.
- Dynamic campaign and country links in Simple XML Sitemap.
- Privacy notices, explicit consent, Honeypot protection, and Drupal Flood
  rate limiting on public submission forms.
- Soft-deleted campaigns excluded from pages, search, counts, and sitemaps.
- Public campaign descriptions separated from staff-only working notes.

## Administration

- `/admin/bfep` — workflow dashboard
- `/admin/bfep/campaigns` — campaign administration
- `/admin/bfep/referrals` — referral review
- `/admin/bfep/volunteers` — volunteer workflow
- `/admin/bfep/change-requests` — update/privacy/safety requests
- `/admin/config/search/bfep` — titles, contact details, indexing, caching,
  verification tokens, social profiles, and submission limits

Record administration requires `administer bfep external data`. The settings
form requires Drupal's `administer site configuration` permission.

## Cache policy

Anonymous Page Cache is configured site-wide for 900 seconds, including the
homepage. BFEP data caches use tags and are invalidated after campaign edits or
adds. Hourly cron invalidates all external-data tags as a backstop for changes
made outside Drupal. Listings/search default to 300 seconds. Pages containing
CSRF-protected forms and permission-aware staff search remain deliberately
uncacheable.

## External database boundary

Public reads select explicit fields and independently join `campaigns` with
`deleted_at IS NULL`; they do not retrieve `internal_notes`. Update 11003 also
hardens `v_campaigns` and creates `v_campaigns_public` when the Drupal database
role owns the view and may create objects. If it cannot, the update safely skips
DDL and reports that `database-hardening.sql` should be run later by the schema
owner. No new indexes are added because the audited database already has useful
country, featured, line-number, updated-time, trigram, and search indexes.

## Release operations

Run `drush updb` and `drush cr` after deploying source, enable Honeypot, and
regenerate Simple XML Sitemap. Use the release-level installer supplied with
this version instead of replacing files manually; it performs source-drift
checks, creates a rollback snapshot, rebuilds the image, lints PHP, runs update
hooks, and tests public routes.

The policy-page wording is an operational draft and should be reviewed whenever
the project's real people, providers, retention practices, or contact details
change.
