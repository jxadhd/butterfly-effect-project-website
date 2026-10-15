# BFEP Arabic and other languages (`bfep_i18n`)

Adds an Arabic version of the public website, picks the visitor's language
automatically, and puts a language switch in the header. The staff admin
stays English.

> **The Arabic was written by Claude (an AI model) and has not been checked by
> a native speaker.** Have someone fluent read the site before it is
> promoted. Wording can be changed without a developer (see
> [Changing the Arabic](#changing-the-arabic)).

## What visitors get

- **Arabic pages under `/ar`.** `/campaigns` is English and `/ar/campaigns`
  is Arabic. English URLs do not change, so existing links, shares and search
  results keep working.
- **Automatic choice.** On their first visit, a visitor whose browser prefers
  Arabic over English is sent to the Arabic page. Everyone else stays on
  English.
- **A remembered choice.** The switch in the header (EN / عربي) remembers the
  choice for a year in a `bfep_lang` cookie, and that choice wins over the
  browser from then on. The cookie holds only `en` or `ar`.
- **Right-to-left layout.** Arabic pages have `<html lang="ar" dir="rtl">`.
  The theme mirrors itself (menus, cards, forms, progress bars, the mobile
  menu) and Arabic text uses IBM Plex Sans Arabic, loaded only on Arabic
  pages.
- **Staff pages are English.** `/admin`, `/user` and the like are never
  redirected, and their `/ar/...` form redirects to the English page.

## What is translated

| Text | Where its Arabic lives |
|---|---|
| Labels, headings, buttons, menus, form fields and messages, page titles and descriptions, the About and policy pages' default wording | `translations/ar.po`, loaded into Drupal's interface translations |
| Country, region and tag names, initiative types and volunteer interest areas (stored in English in bfdb) | `translations/ar.po`, with the context `Country name`, `World region`, `Campaign tag`, `Initiative type` or `Volunteer interest area` |
| Organisation name, tagline, homepage title and description, country title pattern, Instagram message | Configuration translations, written on install |
| Policy pages saved in **Content and channels** | Configuration translations, written on install while the English is still the default wording |

What stays as entered: campaign names, descriptions, careers, platforms,
initiative names and anything else staff type into bfdb. Long English
descriptions are marked `dir="auto"`, so they read left to right inside an
Arabic page. The RSS feed and the admin stay English.

A country, region or tag that is not in `ar.po` is shown in English. Once a
page using it has been viewed in Arabic, it can be translated on **User
interface translation** (filter by the context above).

## Search engines

- Every public page lists its English and Arabic versions with `hreflang`
  links (`en`, `ar` and `x-default`), and its canonical URL is in its own
  language.
- The sitemap lists both versions of each page, each with alternates.
- Search engines and link-preview bots are never redirected, so each URL
  always shows them the same language.
- Open Graph `og:locale` is `en_NZ` or `ar_AR`.

## Installing on the server

After the code is deployed:

```sh
bfep deploy
docker exec -u www-data -w /opt/drupal drupal vendor/bin/drush en bfep_i18n -y
docker exec -u www-data -w /opt/drupal drupal vendor/bin/drush cr
```

`drush en` also turns on Drupal's Language, Interface Translation and
Configuration Translation modules, adds Arabic, sets up `/ar` URLs and loads
the translations. Nothing changes for English visitors except the switch in
the header.

Optional: `drush locale:check && drush locale:update` downloads Drupal's own
Arabic translations from localize.drupal.org, which covers the occasional
core message this module does not. It never replaces this module's wording,
which is marked as customised.

### Cloudflare

Redirects are sent with `Cache-Control: private, no-store` and English pages
with `Vary: Accept-Language, Cookie`. With Cloudflare's default settings
(HTML is not cached) everything works. If a "Cache Everything" rule is ever
added for HTML, exclude requests that have a `bfep_lang` cookie, or the
automatic choice stops working (the pages themselves still work).

## Changing the Arabic

- **A label or sentence:** **Configuration › Regional and language › User
  interface translation** (`/admin/config/regional/translate`), language
  Arabic. Search for the English and edit the Arabic.
- **Headline, titles, policy pages, Instagram message:** the **Translate**
  tab on **BFEP settings** (`/admin/config/search/bfep/translate`) or on
  **Content and channels** (`/admin/bfep/content/translate`).
- **In the repository:** edit `translations/ar.po` and deploy. `bfep deploy`
  runs `drush cr`, which loads a changed file.

Edits made on the site are kept: a new `ar.po` only replaces Arabic that still
holds what the module loaded last time, and the module never touches a
configuration translation someone saved on a Translate tab.

When staff change English that the module translated (the tagline, a policy
page), the module's Arabic for the old wording is removed as they save, so
Arabic visitors see the new English rather than an out-of-date translation.
It reads left to right (policy pages are marked `lang="en"`) until someone
translates it on the Translate tab. Changing the English back brings the
module's Arabic back.

`tests/src/Unit/TranslationFileTest.php` checks that every entry in `ar.po`
keeps its placeholders (`@count`, `%name`, `:url`) and HTML tags, so an edit
cannot break a page.

## Adding text to the site

New public text must go through `t()`, `$this->t()`, `{% trans %}` or `|t`.
Add its Arabic to `translations/ar.po` in the same pull request. Text from
bfdb that visitors should see in their language goes through
`CampaignPresenter::placeName()` with a context.

## Adding another language

1. Add the language under **Configuration › Regional and language ›
   Languages** and give it a URL prefix (for example `fr`).
2. Add `translations/LANGCODE.po`. It is loaded on the next `drush cr`.
3. Add its short label to `$labels` in `bfep_i18n_preprocess_page()` and its
   Open Graph locale in `bfep_i18n_metatags_alter()`.

A right-to-left language gets `css/right-to-left.css`; its typeface rules
are for Arabic.

## Removing it

```sh
docker exec -u www-data -w /opt/drupal drupal vendor/bin/drush pmu bfep_i18n -y
```

Uninstalling deletes the Arabic language and its translations; the site is
English-only again and `/ar` URLs return 404. Language, Interface Translation
and Configuration Translation stay on; uninstall them separately if wanted.

## How it works

| Piece | File |
|---|---|
| Install: Arabic, `/ar` prefix, URL-based language negotiation | `bfep_i18n.install` |
| Automatic choice, the switch's `?lang=` links, English-only staff pages | `src/StackMiddleware/LanguageRedirect.php` (runs before the page cache) |
| Loading `ar.po` and the configuration translations | `src/Translations.php` (on install and every cache rebuild) |
| Header switch | `templates/bfep-language-switch.html.twig`, `css/language-switcher.css`, `js/language-switcher.js` |
| Removing out-of-date Arabic when staff change the English | `src/EventSubscriber/ConfigTranslationSubscriber.php` |
| hreflang, sitemap, `og:locale`, staff guide tip | `bfep_i18n.module` |
| Right-to-left fixes and the Arabic typeface | `css/right-to-left.css`, `fonts/` (SIL Open Font License, see `fonts/OFL.txt`) |
