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
- An "already listed?" question on the referral form. When the fundraiser link
  matches the active fundraiser of a listed campaign (compared the same way as
  the admin's related records), the form links to that campaign in a new tab
  and asks "Is this the fundraiser you are referring?". Yes opens the update
  request form for that campaign without saving a referral; No saves the
  referral as usual.

## Discovery and sharing

- **Sorting by need.** The campaign list can sort "Least funded first" or
  "Closest to goal" (fully funded campaigns last).
- **Homepage spotlight.** "Campaigns that need help now" shows three
  campaigns short of their goal, urgent medical cases first.
- **Related campaigns.** Each campaign page suggests up to three more
  campaigns from the same country.
- **Share buttons.** Campaign pages have a native Share button, Copy link,
  and plain WhatsApp, Telegram, Facebook, X and email links. No third-party
  scripts are loaded.
- **RSS feed.** `/campaigns/feed` lists the 30 most recently added campaigns
  (public fields only). Point a feed reader or an automation tool (IFTTT,
  Zapier, Make, Buffer) at it to draft posts for new campaigns.

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
  sync columns exist. The bfdb line at the bottom says when the sync last
  checked a fundraiser, and a warning appears at the top when that was over
  48 hours ago.
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
- **Saving returns to the list.** After a successful save, every staff form
  goes back to the list it was opened from, with the same search, filters,
  sort and page, and shows the confirmation there, naming the record with a
  link back to it. List edit links carry the list's query string in `?back=`
  (see `src/Admin/ListReturn.php`); forms pass it on to "Save and review next
  pending" and to fundraiser forms, and fall back to the plain list without
  it. A fundraiser returns to its campaign's Fundraisers table. When a save is
  refused (a validation error, someone else's newer save, or a database
  error) the form stays open with the error and everything typed is kept.
  `?destination=` is deliberately not used: Drupal would apply it to every
  redirect, including "review next".
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
- **Campaign edit** covers the campaign's details, tags and the no-feature
  response, and lists its fundraisers. If someone else saved the campaign after
  you opened it, your save is refused with a message, rather than overwriting
  theirs.
- **Several fundraisers per campaign.** A campaign (a `campaigns`
  row) can have any number of fundraisers (`campaign_fundraisers` rows). The
  edit page's Fundraisers table shows them all, active and ended, with an Edit
  link each and an "Add fundraiser" button. Adding one offers to end the
  campaign's current fundraisers (ticked by default, which is how a move to a new
  platform is recorded); untick to keep them running side by side. Each
  fundraiser's form has the platform, URL, currency, amounts, an Active
  checkbox and the auto-sync switch. Ended fundraisers stay as history and are
  never deleted. The public page adds up active fundraisers that share a
  currency, lists different currencies side by side (never converted), and
  shows each fundraiser with its own figures and button when there is more than
  one. The sync already works per fundraiser, so it needs no change. The data
  check "More than one active fundraiser" lists these campaigns for review.
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
  10,000 rows): line, name, country, flags, tags, the newest active
  fundraiser's figures, any other active fundraisers' links, and links. Internal notes and submitter details are never exported. Cells that
  start with `=`, `+`, `-` or `@` get a leading apostrophe so spreadsheets do
  not run them as formulas. Each export is logged with the user and row count.
- **Sorting.** Click a column heading (line, name, country, email, hours,
  status, created or updated) to sort; click again to reverse. Only listed
  columns can be sorted, so the URL cannot inject SQL.
- **Removing campaigns.** "Remove from site" (edit page) or "Remove" (list)
  opens a confirmation that asks why (duplicate, fundraiser ended, family
  asked, other) and warns when the fundraiser is listed on another campaign,
  the campaign is featured or urgent, or it has pending change requests.
  Removing sets `campaigns.deleted_at`, so the campaign leaves the public
  site, staff lists, data checks and the fundraiser sync; nothing is deleted.
  A dated line with the reason is added to its internal notes. "Removed
  campaigns" lists them, newest first, and "Restore" clears `deleted_at`
  (warning if its line number or fundraiser is now used elsewhere). The site's
  bfdb user needs UPDATE on `campaigns.deleted_at`.
- **Audit log.** Every staff create or update writes a notice to the `bfep` log
  channel (Reports > Recent log messages) with the user, record and the names
  of the fields changed. Values are not logged.
- **Status report.** Reports › Status report has two BFEP entries. "BFEP
  database (bfdb)" shows the PostgreSQL version, and is an error when bfdb
  cannot be reached or the `v_campaigns` view is missing. "BFEP fundraiser
  sync" says when the sync last checked a fundraiser and how many have a sync
  problem, and warns when none has been checked for 48 hours
  (`HealthCheck::SYNC_STALE_HOURS`). Dry runs write nothing, so only
  `--apply` runs count.

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

## Translations

The public site is translatable; `bfep_i18n` adds the Arabic version (see its
README). Public text goes through `t()`, `{% trans %}` or `|t`, and English
names stored in bfdb (countries, regions, tags, initiative types) through
`CampaignPresenter::placeName()` with a context, so visitors see them in their
language while filters and URLs keep the English values. Absolute links use
`BfepSettings::localizedUrl()`, which adds the page's language prefix. Admin
pages stay English.

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

## Visitor IP addresses behind Cloudflare

`settings/cloudflare.settings.php` adds Cloudflare's edge ranges to
`reverse_proxy_addresses`, so Drupal records the real visitor IP instead of a
Cloudflare address. It only takes effect when `sites/default/settings.php`
includes it after its own `reverse_proxy` lines (the snippet is in the file's
header). It ships with this module so `bfep deploy` keeps it up to date.
Refresh the ranges from https://www.cloudflare.com/ips/ if Cloudflare changes
them.
