# The Butterfly Effect Project website

Public source code for
[The Butterfly Effect Project](https://butterfly.joshcross.co.nz/), a
volunteer-led humanitarian fundraiser discovery and information project.

The website helps visitors discover humanitarian fundraising campaigns,
understand the information available about them, submit referrals, and request
corrections.

BFEP does not process donations. Fundraisers and payment processing remain
under the control of their respective organisers and fundraising platforms.

## Repository contents

- `drupal/modules/custom/bfep` — campaign directory, administration, search,
  forms, verification information, metadata, caching and sitemap integration.
- `drupal/modules/custom/bfep_content` — editable public policy content and
  official-channel presentation.
- `drupal/themes/custom/butterfly` — the custom public Drupal theme.
- `Dockerfile.drupal` — the Drupal image build definition.

This repository intentionally excludes production credentials, private
submissions, database contents, uploaded files, backups and environment-specific
configuration.

## Development status

The repository initially represents the custom source currently deployed to the
BFEP website. Reproducible development, automated validation and tagged
deployment workflows are being added incrementally.

## Continuous integration

Every pull request runs:

- **Lint**: PHP and YAML syntax, and Drupal coding standards (`phpcs.xml.dist`).
  Coding standards failures block the check.
- **Tests**: the custom modules' PHPUnit unit tests against a throwaway Drupal 11
  codebase, plus a Twig template syntax check.
- **Docker image**: builds `Dockerfile.drupal` and starts it once, on pull
  requests that change it and weekly. It never pushes an image.

Dependabot proposes updates for the Docker base image and GitHub Actions versions.

## Security

Please do not report security vulnerabilities through a public issue. See
[SECURITY.md](SECURITY.md).

## Licence

The Drupal modules and theme in this repository are licensed under the GNU
General Public License, version 2 or any later version. See [LICENSE](LICENSE).
