# Using the pipeline: a guide for staff

The pipeline is the set of automatic checks GitHub runs whenever someone
proposes a change to the website's code. The checks catch mistakes before they
reach the live site: a typo that would break a page, a template that won't
render, code that doesn't follow the house style, or a change that breaks
something that used to work.

You don't need to be a developer to use it. This guide explains what each
check does, how to open a pull request and read the results, and what to do
when something goes red.

What the pipeline never does:

- It never deploys anything or changes the live site.
- It never reads or writes the campaign database.
- It never uses passwords, keys or other secrets.

Deploying is a separate, manual step. See "Release operations" in the
[module README](../drupal/modules/custom/bfep/README.md#release-operations).

## Contents

- [A few words first](#a-few-words-first)
- [The checks at a glance](#the-checks-at-a-glance)
- [Day-to-day: making a change, step by step](#day-to-day-making-a-change-step-by-step)
- [Opening a pull request](#opening-a-pull-request)
- [Reading the results](#reading-the-results)
- [What each check does and how to fix it](#what-each-check-does-and-how-to-fix-it)
- [When a failure isn't your change's fault](#when-a-failure-isnt-your-changes-fault)
- [Dependabot pull requests](#dependabot-pull-requests)
- [The weekly image build](#the-weekly-image-build)
- [Running the sync service safely](#running-the-sync-service-safely)

## A few words first

- **Repository (repo):** the project's code on GitHub, including its full history.
- **`main`:** the main branch. It holds the code that is ready to deploy.
- **Branch:** a separate copy of the code where you make a change without affecting `main`.
- **Commit:** one saved change on a branch, with a short message saying what it does.
- **Pull request (PR):** a request to merge a branch into `main`. This is where
  the checks run and where people review the change.
- **Draft PR:** a PR marked "not ready yet". The checks still run, but it can't be merged until someone clicks "Ready for review".
- **Check:** one automatic test. Each one shows as a row at the bottom of the PR,
  with a green tick when it passes or a red cross when it fails.
- **Workflow:** a group of checks, defined in a file under `.github/workflows/`.

## The checks at a glance

| Shown on GitHub as | What it looks at | Runs when |
|---|---|---|
| **Lint / PHP and YAML syntax** | Every PHP file can be read by PHP, and every `.yml` settings file is valid YAML | Every PR, and every push to `main` |
| **Lint / Drupal coding standards** | The code follows Drupal's coding style (the rules are in `phpcs.xml.dist`) | Every PR, and every push to `main` |
| **Tests / PHPUnit (custom modules)** | The automated unit tests pass, and every Twig template can be parsed | Every PR, and every push to `main` |
| **Image build / Build Drupal image** | The website's Docker image still builds and starts | PRs that change `Dockerfile.drupal`, every Monday morning, and on demand |
| **CodeQL** and **Analyze (actions)** | GitHub's security scan of the workflow files | Set up in the repo settings, and runs on its own |

**Every check counts.** The coding standards check used to be advisory, and now
it fails the PR like the others. Don't merge a PR with a red cross, even if
GitHub lets you.

> Tip for the repo owner: to make GitHub enforce this, open **Settings >
> Rules > Rulesets** (or **Settings > Branches**), protect `main` and mark the
> checks above as required. The merge button then stays disabled until they pass.

## Day-to-day: making a change, step by step

1. **Start from the latest `main`.** On GitHub you are on `main` by default.
   On your own computer, run `git switch main` and then `git pull`.
2. **Make a branch** with a short name that says what it's for, such as
   `fix-volunteer-form-typo`. On your computer, run `git switch -c fix-volunteer-form-typo`.
   On GitHub, the editor offers to make a branch for you when you save (see the next section).
3. **Make the change.** For a small text change, GitHub's web editor is fine.
   For anything bigger, work on your computer, or ask Claude in the BFEP project to
   make the change and open a draft PR for you.
4. **Open a pull request** into `main`. Start it as a draft if you're still working on it.
5. **Wait for the checks.** They start on their own, usually within a minute, and
   most finish within a few minutes. The yellow dot turns into a tick or a cross.
6. **Read the results.** If everything is green, go to step 7. If something is
   red, open it (see [Reading the results](#reading-the-results)), fix the problem
   on the same branch, and push or commit again. The checks re-run on their own
   for every new commit, and an older run that is still going is cancelled.
   A grey "cancelled" row is normal and nothing to worry about.
7. **Ask for a review.** Click **Ready for review** if it was a draft, and add a
   reviewer on the right. The reviewer reads the **Files changed** tab and can
   leave comments on individual lines.
8. **Merge** when it's approved and green. Use **Create a merge commit**, which
   is the repo's usual choice. It keeps each commit separate, so one feature can
   later be removed with `git revert <commit>`. **Squash and merge** turns the whole PR
   into one commit, which breaks the per-feature removal steps in PR descriptions.
9. **After merging,** the Lint and Tests checks run again on `main`. If they go
   red there, tell Josh straight away, because every new PR now starts from a broken base.
   Merging does not deploy. The change reaches the website at the next release.

## Opening a pull request

**From the GitHub website (small changes):**

1. Open the file on GitHub and click the pencil icon (**Edit this file**).
2. Make your change.
3. Click **Commit changes…**. Write a short message saying what you changed,
   such as "Fix typo on volunteer form".
4. Choose **Create a new branch for this commit and start a pull request**, then
   click **Propose changes**.
5. On the next page, check that the base is `main`. Give the PR a clear title
   and describe what changed, why, and how someone can check it.
6. Click **Create pull request**. To open it as a draft instead, click the arrow
   next to the button and choose **Create draft pull request**.

**From a branch you pushed from your computer:**

1. Run `git push -u origin your-branch-name`.
2. On the repo's GitHub page, click **Compare & pull request** in the yellow
   banner. Or open the **Pull requests** tab, click **New pull request**, and pick your branch.
3. Continue from step 5 above.

**PRs that Claude opens** follow the same rules. They start as drafts, each
feature is its own commit, and the description says how to test and how to
remove each feature. Claude fixes its own failing checks. Your part is to
review, test, and merge when you're happy.

## Reading the results

**On the PR page,** scroll to the bottom of the **Conversation** tab. A box
says **All checks have passed** or **Some checks were not successful**. Click
**Show all checks** to see each row:

- ✅ green tick: passed.
- ❌ red cross: failed. This needs fixing.
- 🟡 yellow dot: still running.
- ⚪ grey: skipped (it didn't apply to this change) or cancelled (a newer commit replaced the run).

**To see why a check failed,** click **Details** on its row. This opens the log
for that check:

1. The failed step has a red cross and is expanded. Steps above it passed.
2. Scroll to the first line that says `Error`, `ERROR`, `FAIL` or `FAILURES`.
   The lines after it say which file and line, and what's wrong.
3. Some checks also add **annotations**. These are short error notes shown on
   the run's **Summary** page and next to the line in the PR's **Files changed** tab.
   The syntax, coding standards and Twig checks do this. GitHub shows at most 10
   errors per step this way, but the log always has the full list.

The **Checks** tab at the top of the PR lists the same checks with their logs.
Each commit in the **Commits** tab also has its own tick or cross.

## What each check does and how to fix it

### Lint / PHP and YAML syntax

**What it does:** It asks PHP to read every PHP file (`.php`, `.module`,
`.install`, `.inc`, `.theme`) without running it, and it parses every `.yml` file
under `drupal/`. It's a quick check for typos that would stop the site loading.

**When it fails:** The log and the annotations name the file and line, for
example `PHP Parse error: syntax error, unexpected token "}" in … on line 42`,
or a YAML message such as `mapping values are not allowed here … line 7`.
The usual causes are:

- PHP: a missing `;`, an unclosed bracket or quote, or a stray character.
- YAML: wrong indentation (YAML uses spaces, never tabs), a missing space after
  a `:`, or a value that contains `:` or `#` and needs quotes.

Fix the line and commit again.

### Lint / Drupal coding standards (blocking)

**What it does:** It runs `phpcs` (PHP CodeSniffer) with Drupal's official rules
and checks the custom modules and theme. The rules, and the few that are
switched off, are in `phpcs.xml.dist` at the top of the repo. A style problem
such as wrong indentation **fails the PR**, so the code stays consistent and
easy to review.

**When it fails:** Open **Details**. The log shows a report for each file:

```
FILE: drupal/modules/custom/bfep/src/Example.php
-----------------------------------------------------------------------
 22 | ERROR   | [x] Line indented incorrectly; expected 4 spaces, found 6
 26 | ERROR   | [x] Expected 1 space after IF keyword; 0 found
 15 | ERROR   | [ ] Parameter $b is not described in comment
-----------------------------------------------------------------------
PHPCBF CAN FIX THE 2 MARKED SNIFF VIOLATIONS AUTOMATICALLY
```

The number is the line, and `[x]` means the tool can fix it for you. `[ ]`
means a person has to fix it. Each problem also shows as an annotation next to
its line in the PR's **Files changed** tab.

**How to fix it.** Pick whichever option suits you:

**Option 1: Ask Claude.** In the BFEP project, say "fix the coding standards
failure on PR #N". Claude runs the fixer, checks the result and pushes it to the branch.

**Option 2: Fix it by hand.** For one or two problems, edit the lines the log
names on GitHub or on your computer. Common messages:

| Message | What to do |
|---|---|
| `Line indented incorrectly; expected 2 spaces, found 4` | Drupal indents with **2 spaces** per level, never tabs. |
| `Whitespace found at end of line` | Delete the spaces after the last character on that line. |
| `Expected 1 newline at end of file; 0 found` | Add one empty line at the very end of the file. |
| `A comma should follow the last multiline array item` | Add a `,` after the last item of a list that spans several lines. |
| `Expected 1 space after IF keyword; 0 found` | Write `if (`, not `if(`. The same goes for `foreach (` and `while (`. |
| `Short array syntax must be used to define arrays` | Write `[1, 2]`, not `array(1, 2)`. |
| `Unused use statement` | Delete that `use …;` line near the top of the file. |
| `Parameter $x is not described in comment` | Add a `@param` line for `$x` to the comment block above the function, with a short description on the next line. |

**Option 3: Run the fixer on your computer, with Docker.** This is the easiest
local route if you don't have PHP installed. Install
[Docker Desktop](https://www.docker.com/products/docker-desktop/), open a terminal
in the repo folder, and run:

```sh
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD":/app -w /app composer:2 sh -c '
  composer global config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true &&
  composer global require --quiet drupal/coder:^8.3 &&
  bin="$(composer global config bin-dir --absolute --quiet)" &&
  "$bin/phpcbf"; "$bin/phpcs"'
```

`phpcbf` fixes everything marked `[x]`, then `phpcs` lists anything left over
(no output apart from dots means it's clean). Review the changes with
`git diff`, commit them and push. On Windows, run it in a WSL terminal.

**Option 4: Run it with PHP and Composer installed (developers).** This is the
same as CI:

```sh
composer global config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
composer global require drupal/coder:^8.3
export PATH="$(composer global config bin-dir --absolute --quiet):$PATH"

phpcs     # list problems (reads phpcs.xml.dist automatically)
phpcbf    # fix everything marked [x]
phpcs     # check again
```

Don't switch off a rule in `phpcs.xml.dist` to make a PR pass. If you think a
rule is wrong for this project, raise it with Josh in its own PR.

### Tests / PHPUnit (custom modules)

**What it does:** It builds a temporary, empty Drupal 11 codebase, adds the
custom modules to it, and runs their unit tests from
`drupal/modules/custom/*/tests`. The tests check small pieces of logic, such
as URL checks, line-number searches, paging, status labels, progress bars and
RSS output. No database or website is involved, so nothing real can be affected.

**When the "Run unit tests" step fails:** The log ends with something like:

```
There was 1 failure:

1) Drupal\Tests\bfep\Unit\AdminFormatTest::testReferralStatusLabel
Failed asserting that two strings are identical.
--- Expected
+++ Actual
-'Needs information'
+'Needs_information'

FAILURES!
Tests: 20, Assertions: 49, Failures: 1.
```

The test name says which behaviour broke. "Expected" is what the test wanted,
and "Actual" is what the code produced. Either the change broke that behaviour
and the code needs fixing, or the behaviour changed on purpose and the test
needs updating to match, in the same PR. **Never delete or skip a test to get a green tick.**

To run the tests on your own computer, see "Tests" in the
[module README](../drupal/modules/custom/bfep/README.md#tests).

**When the "Build Drupal test harness" step fails:** This step only downloads
Drupal, so a failure there is almost never caused by your change. See
[When a failure isn't your change's fault](#when-a-failure-isnt-your-changes-fault).

### Tests / Twig template syntax (a step in the Tests check)

**What it does:** It parses every `.twig` template in the custom modules and
theme with Drupal's own Twig, the way the site does when it renders a page.

**When it fails:** The annotation is attached to the template and line, with
Twig's message. The two most common messages are:

- `Unexpected end of template.` An `{% if %}`, `{% for %}` or `{% block %}` is
  missing its `{% endif %}`, `{% endfor %}` or `{% endblock %}`. The line number
  points at the end of the file, so look upwards for the unclosed tag.
- `Unexpected "}".` A `{{` or `{%` isn't closed properly, such as `{{ name }` instead of `{{ name }}`.

The last line of the step says how many templates were checked and how many had errors.

### Image build / Build Drupal image

**What it does:** It builds the website's Docker image from `Dockerfile.drupal`,
then starts it a few times to check that PHP runs, the `gmp` extension is
there, Drush works and `robots.txt` has the sitemap line. It **never pushes
the image anywhere and never deploys it**. It runs on PRs that change
`Dockerfile.drupal` (including Dependabot's), every Monday, and whenever someone
clicks **Run workflow** on the **Actions** tab. Building the image installs the
contrib modules and Drush with Composer, which picks the newest releases the
Dockerfile's version rules allow (`^2.2` means "2.2 or any later 2.x").

The run's **Summary** page has a **Versions in this image** table listing
PHP, Drupal core, Drush and each contrib module. The check also **fails on
purpose if Drupal's major version isn't 11**, so an unplanned Drupal 12 upgrade
can't slip in as a routine update. When the site is deliberately moved to the next
major version, change `EXPECTED_DRUPAL_MAJOR` at the top of `.github/workflows/image.yml`.

**When it fails:**

- **In the "Build" step:** The base image or a package changed, or the
  Dockerfile has a mistake. Read the last lines of the step, which name the
  failing command. If the PR didn't touch the Dockerfile (the weekly run, for example), see
  [The weekly image build](#the-weekly-image-build).
- **In the "Versions" step, with "Drupal 11 is expected":** The base image moved
  to a new Drupal major version. Don't merge it. Tell Josh, because the site
  needs testing on the new version first.
- **In the "Smoke test" step:** The image built but something is missing. The
  failing line is the one printed just before the error. For example, if
  `php -m | grep -qx gmp` fails, the gmp extension is missing.
- **A network error** (`429 Too Many Requests`, `TLS handshake timeout`,
  `failed to fetch`) is Docker Hub or a mirror being busy. Re-run it once.

### CodeQL and Analyze (actions)

**What they do:** GitHub's built-in security scanner checks the code it
understands for risky patterns. At the moment that's the workflow files, where it looks
for things like untrusted text used in a shell command. It doesn't read PHP. It's turned
on in the repo settings (**Settings > Code security**), not by a file in this repo.

**When they report something:** Open **Security > Code scanning** on GitHub.
Each alert explains the risk and how to fix it. Tell Josh about anything you
don't understand rather than dismissing it.

## When a failure isn't your change's fault

Some failures come from outside your change. Signs of this:

- The error is about downloading something (`Could not download`, `curl error`,
  `429`, `502`, `timed out`) or the runner itself (`The runner has received a shutdown signal`).
- The same check is red on `main` too. Open the **Actions** tab, pick the
  workflow and look at the latest run on `main`.
- The error names files your PR doesn't touch, and it fails the same way again.

What to do:

1. **Re-run it once.** On the failed run's page, click **Re-run jobs > Re-run
   failed jobs**. You need write access to the repo for this.
2. If it fails the same way again, it's a real failure. Check whether `main` is
   red too. If it is, tell Josh and wait for `main` to be fixed. Then click **Update branch**
   on your PR, which brings in the fix and re-runs the checks.
3. Never get past a red check by deleting or skipping tests, turning off a rule,
   or editing the workflow files. Never open an empty commit or close and
   reopen the PR to trigger another run. Fix the cause, or ask.

## Dependabot pull requests

[Dependabot](https://docs.github.com/en/code-security/dependabot) is a GitHub
bot that opens PRs to keep things up to date. It's set up in
`.github/dependabot.yml` for two things:

- **The Docker base image** (the `FROM` line in `Dockerfile.drupal`): checked
  every Monday at 09:00 New Zealand time. Titles look like
  "Bump drupal from `369ec2a` to `7a6958c`". The image is pinned by its digest
  (the long `sha256:` code), so each bump is a newer build of the official Drupal image,
  usually with security fixes for PHP, Apache or Debian.
- **GitHub Actions versions** (the `uses:` lines in the workflows): checked
  monthly and grouped into one PR.

Dependabot PRs run the same checks as anyone else's. A Docker bump also runs
**Image build**, because it changes `Dockerfile.drupal`.

**How to handle one:**

1. Wait for the checks. If they're all green, carry on. If one is red, see
   below.
2. **For a Docker bump,** open the **Image build** check's **Details**, click
   **Summary** at the top left, and look at the **Versions in this image** table.
   Compare it with the latest run on `main` (**Actions > Image build**). Because
   the base image is pinned only by its digest, with no version tag, a bump could
   bring in a new version of PHP or Drupal when the official image moves on.
   A new Drupal major version (11 to 12) fails the check on purpose. If PHP's
   first or second number changed (for example 8.5 to 8.6), ask Josh before merging,
   because a new PHP version can need code changes.
3. **For an Actions bump,** read the release notes Dependabot links in the PR
   description. If the checks are green, these are low-risk.
4. Merge with **Create a merge commit**. A Docker bump reaches the live site
   only at the next release.

**When a Dependabot PR's check is red:** Read the log the same way as any other.
A failure on a Docker bump means the new base image breaks the build, so
don't merge it. Tell Josh, or close it, and Dependabot will offer the next one.

**Useful commands.** Post these as a comment on the Dependabot PR:

| Comment | Effect |
|---|---|
| `@dependabot rebase` | Brings the PR up to date with `main` and re-runs the checks. |
| `@dependabot recreate` | Rebuilds the PR from scratch, dropping any edits made to it. |
| `@dependabot ignore this major version` | Stops offering this major version. Use it when an upgrade needs planning. |
| `@dependabot ignore this dependency` | Stops all updates for it. Use this rarely. |

## The weekly image build

**Image build** also runs on its own every **Monday at about 09:00 New Zealand
time** (08:00 when daylight saving is off). Nobody has to change anything for
the result to differ from last week: each build installs the newest releases of
the contrib modules, Drush and possibly Drupal core that the Dockerfile's
version rules allow. The weekly run notices a breaking upstream release during
the week, not on release day.

**Where to see it:** Go to the **Actions** tab and choose **Image build** on the
left. Runs started by the schedule are labelled **Scheduled**. GitHub emails a
failure notice to the person who added the schedule, or who last changed it.

**When it fails:** Nothing on the live site has changed. It means the next
release would fail to build if it went ahead unchanged.

1. Open the run and find the failing step, as described above. The
   **Versions in this image** table on a passing run's Summary page shows what
   changed since the previous week.
2. If it's a network error, click **Re-run all jobs** once.
3. If it fails again, tell Josh before the next release. Include the run link and
   the last error lines from the log.

**To run it by hand** (for example before a release): **Actions > Image build >
Run workflow**, choose the branch (usually `main`), and click **Run workflow**.

GitHub pauses scheduled workflows in a public repo after 60 days without any
activity. If the Monday runs stop appearing, open **Actions > Image build** and
click **Enable workflow**.

## Running the sync service safely

The sync service updates campaigns' **raised** and **goal** amounts from the
fundraising platforms (GoFundMe for now). It is **not in this repository**. The
upgraded version, with its own README and tests, is in the `sync-service`
folder in the BFEP project's shared files. Ask Josh for access.

**The one rule: it does nothing until you add `--apply`.**

- **Dry run (the default):** `python3 sync.py` reads the campaigns, fetches
  each fundraiser page and **prints** what it would change. It writes nothing,
  so it's safe to run at any time.
- **Apply:** `python3 sync.py --apply` writes the new amounts to the database.
  Only do this after a dry run whose output you've read and agree with.

**A safe routine:**

1. Run the tests first. They use no network and no database:
   `python3 -m unittest discover -s tests -v`.
2. Dry run a few campaigns: `python3 sync.py --limit 5`. The first line says
   `DRY RUN: 5 campaigns`. Then there's one line per fundraiser, starting with its record
   number: `17: ok, raised 1200 -> 1350` for a change, or `17: suspicious_drop`
   for one held back. The last line counts each status and says how many would change.
3. Read the output. Look for anything held back (see the statuses below) and any figure that looks wrong.
4. Only then apply, with the same options plus `--apply`. To clear the website's
   cache afterwards so pages show the new figures, add `--after "drush cr"`. That
   command runs only when something changed.

**What the statuses mean.** These also show in the admin, on the campaign edit
form under "Last sync" and in the **Sync problems** filter on the campaign list:

| Status | Meaning | What to do |
|---|---|---|
| `ok` | Figures read and saved, or already up to date. | Nothing. |
| `suspicious_drop` | Raised fell by more than 20%, so it was **not** saved. | Check the fundraiser page. If the drop is real, update the amount by hand. |
| `currency_unknown` | The fundraiser has a raised amount but no currency stored, so nothing was saved. | Set the currency on the campaign edit form. |
| `currency_mismatch` | The platform reports a different currency and a different goal, so nothing was saved. (If only the currency label differs and the goal matches, the sync corrects the label itself.) | Check the fundraiser and fix the currency on the campaign. |
| `not_found_pending` | The page returned "not found", but fewer than 3 times in a row. A dry run prints `not_found` for these. | Usually nothing yet. After 3 failures in a row it becomes `not_found`. |
| `not_found` | The page has been missing 3 times in a row. | Check whether the fundraiser moved or closed, and update its URL or deactivate it. |
| `parse_error` | The page loaded but its figures couldn't be read. | If several campaigns show this, the platform changed its page layout. Tell Josh. |
| `invalid_url` | The stored URL isn't a valid fundraiser address for that platform. | Fix the fundraiser URL on the campaign. |
| `http_500`, `http_503`, … | The platform returned an unexpected error, even after one retry. | Usually temporary. If it repeats, check the page in a browser. |
| `error` | Something else went wrong, such as a timeout. | Usually temporary. If it repeats for the same campaign, tell Josh. |
| `not_configured` | The platform needs a key that isn't set (JustGiving, for example). | Nothing, unless you meant to turn that platform on. |

**Other things to know:**

- To stop the sync overwriting figures you entered by hand, untick **Update
  raised and goal amounts automatically** on the campaign's edit form.
- Only one run happens at a time. A second run exits straight away with code 3
  ("another run holds the lock").
- Exit code 2 means a platform blocked the requests (HTTP 403 or 429). The run
  stops calling that platform. Wait a few hours before trying again.
- The database connection comes from environment variables. **Never** put
  passwords or connection details in this repo, in a PR, in an issue or in a chat message.
- Don't run `--apply` against a database you aren't sure about. When in doubt,
  dry run and ask.
