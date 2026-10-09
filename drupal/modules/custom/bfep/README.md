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
- `/admin/bfep/checks` — data checks: campaigns with missing or conflicting
  details
- `/admin/bfep/help` — short staff guide to the admin workflow, linking to
  the full guide set in settings ("Full staff guide URL")
- `/admin/config/search/bfep` — titles, contact details, indexing, caching,
  verification tokens, social profiles, and submission limits

Record administration requires `administer bfep external data`. The settings
form requires Drupal's `administer site configuration` permission.

### Admin workflow

- **Dashboard.** Counts come from one query. A database error shows a notice
  instead of breaking the page. Each queue shows how many items are pending and
  the age of the oldest one. A "Fundraiser sync problems" card appears when the
  sync columns exist.
- **Data checks** (`/admin/bfep/checks`) count campaigns with no active
  fundraiser, a fundraiser URL shared with another campaign, a line number used
  twice, no line number, no country, no public description, no goal, amounts
  without a currency, fully funded, or no edit in 6 months. Each count links
  to the campaign list filtered by that check (the "Data check" filter), and
  the dashboard shows how many campaigns are flagged. The checks are defined
  in `src/Admin/DataChecks.php`.
- **Review forms** (referrals, volunteers, change requests) show the submitted
  date and render external URLs as links only when they are `http(s)`. Each
  form has a "Save and review next pending" button that opens the oldest
  remaining pending item.
- **Referral status** is a fixed list: pending, needs information, verified and
  rejected. Older free-text values are matched case-insensitively and kept as
  an extra option, so nothing is lost on save.
- **Status badges.** Referral, volunteer and change-request lists show each
  status as a coloured badge with its text label (pending amber, needs
  information blue, verified or accepted green, rejected red), so colour is
  never the only cue.
- **Related records.** A referral lists campaigns and other referrals with the
  same fundraiser URL or email. A change request with no campaign suggests
  campaigns that use its URL. URLs are compared without scheme, `www.`, query
  string, fragment or trailing slash.
- **Campaign edit** covers the active fundraiser (platform, URL, currency, goal
  and raised amounts), tags, the no-feature response and the auto-sync switch.
  Changing the fundraiser URL deactivates the old fundraiser row and adds a new
  one, so history is kept. If someone else saved the campaign after you opened
  it, your save is refused with a message, rather than overwriting theirs.
- **Campaign add** refuses a fundraiser URL that another campaign already uses,
  with links to those campaigns, unless "Add anyway if another campaign
  already uses this fundraiser URL" is ticked.
- **Referral to campaign.** A referral's "Add as a new campaign" button opens
  the add form (`?referral=ID`) with the contact name, fundraiser URL, a
  platform guessed from the URL's host, a country guessed from the referral's
  city/country text, and an internal note naming the referral. The public
  description is left empty on purpose. After saving, staff are reminded to
  update the referral's status.
- **Lists** have working paging with a 25, 50 or 100 per-page choice, escape
  `%` and `_` in searches, and filter referrals by "Pending (including no
  status)" and campaigns by "Sync problems".
- **CSV export.** "Download … as CSV" under the campaign list exports the
  campaigns the list is showing, with the same search and filters (up to
  10,000 rows): line, name, country, flags, tags, active fundraiser figures and
  links. Internal notes and submitter details are never exported. Cells that
  start with `=`, `+`, `-` or `@` get a leading apostrophe so spreadsheets do
  not run them as formulas. Each export is logged with the user and row count.
- **Sorting.** Click a column heading (line, name, country, email, hours,
  status, created or updated) to sort; click again to reverse. Only listed
  columns can be sorted, so the URL cannot inject SQL.
- **Audit log.** Every staff create or update writes a notice to the `bfep` log
  channel (Reports > Recent log messages) with the user, record and the names
  of the fields changed. Values are not logged.

### Search shortcuts

A search that is only a campaign line number, optionally with `#` (for example
`42` or `#42`), opens that campaign directly. So does a search with exactly one
result. This applies to the public campaign filter (`/campaigns?q=`), the site
search (`/search/database?keys=`) and the admin campaign list. Staff are sent
to the edit form; visitors to the public page.

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

## Tests

Unit tests live in `tests/src/Unit` and run in CI (`.github/workflows/tests.yml`).
To run them locally from a Drupal 11 codebase with `drupal/core-dev` installed
and this repository's `drupal/modules/custom` linked into `web/modules/custom`:

```sh
vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom
```

## Release operations

Run `drush updb` and `drush cr` after deploying source, enable Honeypot, and
regenerate Simple XML Sitemap. Use the release-level installer supplied with
this version instead of replacing files manually; it performs source-drift
checks, creates a rollback snapshot, rebuilds the image, lints PHP, runs update
hooks, and tests public routes.

The policy-page wording is an operational draft and should be reviewed whenever
the project's real people, providers, retention practices, or contact details
change.
