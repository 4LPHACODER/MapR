# CI implementation verification

Verified locally on 2026-11-15 on `ci/github-actions-branch-flow`, after merging
`origin/main` at `5f77808` into the Docker integration and CI changes.

## Passing checks

- `python3 -B -m unittest discover -s tests/ci -v`: 22 tests, including real Git
  hook acceptance/rejection and one-character commit descriptions.
- `python3 -B -m unittest discover -s tests/docker -v`: 14 tests.
- `composer validate --strict`: valid manifest and lock file.
- `vendor/bin/pint --test`: passes across the integrated application.
- `composer test`: 143 tests, 1,796 assertions, using PHP 8.5.12 in the development
  image. Also passes from a clean source export with freshly installed Composer
  dependencies and a Node 22 frontend build, without local geospatial datasets.
- `vendor/bin/phpunit --filter DocxPreviewTest --display-skipped`: 4 tests,
  34 assertions, no skips. LibreOffice is executable in the development image.
- `npm ci` and `npm run build`: pass with Node 22 in the clean source export.
- Production app, web, and development Docker targets build successfully.
- `rhysd/actionlint:latest`: all five workflows pass validation.
- All three ruleset files parse as JSON. Executable modes are committed for both
  validators and the local hook.
- Fifteen additional scenarios exercised the actual workflow Bash steps and
  ruleset definitions: only commits in the PR range are checked; bad subjects
  and missing commit objects fail; PR titles cannot execute shell substitutions;
  forked promotion branches fail; configured checks, approvals, merge methods,
  and branch freshness requirements match the plan.

## Corrections found during verification

- The planned commit regular expression rejected valid `fix/a` and `fix/1`.
- Commit enumeration now fails if Git cannot resolve the requested range.
- Promotions must originate from the repository, so a fork named `develop` or
  `testing` cannot impersonate a promotion branch.
- Existing code required Pint formatting before introducing the lint gate.
- Newer `main` contained resident map handlers and tests without registered
  routes; the three routes are registered under the existing authorization and
  barangay-scope middleware.
- A dashboard assertion expected obsolete CSS classes; it now checks each
  workspace's current card classes.
- A hazard export test depended on ignored local shapefiles. It now generates
  synthetic point and polygon fixtures in isolated test storage, preserving
  privacy filtering and coordinate conversion assertions.

## TODO follow-up — 2026-11-15

- Reviewed `check-branch-flow.sh` and its six tests: the typed work branch and
  exact promotion rules match `CI.md`; no defect found in those reviewed rules.
- Reviewed `test_compose_environment.py`, `test_entrypoint.py`, and
  `environment_probe.php` against Compose, the entrypoint, and Laravel bootstrap.
  Found a test isolation defect: inherited `MAPR_EXPECTED_ENV=production` with
  `APP_ENV=local` caused five entrypoint test failures and two cascading errors.
  The fixture now removes those caller variables before individual tests set them.
- After the fix, all 14 Docker tests pass with those conflicting caller values.
  All 28 CI tests pass, including strict PR-title diagnostics and the local hook.
- Bash syntax and ShellCheck 0.11.0 pass for both policy scripts. ShellCheck uses
  `-x -P scripts/ci` to resolve `change-types.sh`. Actionlint 1.7.12 passes for
  `pr-policy.yml`; both linters run from the existing `rhysd/actionlint` image.
- PHP syntax and `environment_probe.php` pass in a disposable source export,
  before and after `php artisan config:cache`, using PHP 8.5.12 in `mapr-ci-dev`.
  Synthetic `/run/mapr/.env` values remain isolated from conflicting source
  `.env` and `.env.local` files. Dependencies are mounted read-only from the
  existing vendor volume; the container has no network access.
- Remote branch inspection (`git ls-remote --heads origin develop testing main`)
  finds only `main`. The live PR-title smoke test is still pending activation;
  `CI.md` now explicitly checks `Merge develop` rejection and title restoration.

## Deferred live verification

On 2026-11-15, at the user's request, remote `develop` and `testing` branches were
created from `main` at `5f77808`, and the integrated work was prepared for a PR
from `ci/github-actions-branch-flow` into `develop`. Branch creation used the
authenticated GitHub CLI after the connector rejected ref creation with HTTP 403.
No branch rulesets or repository settings were changed, and no images were
published or deployed.
GitHub Actions runs, blocked direct pushes, required approvals, allowed merge
methods, and the full promotion smoke test have not been verified on GitHub.
Follow [CI activation instructions](CI.md#activation-status-and-owner-setup) when
admin access becomes available. Do not treat local results as proof of active
GitHub branch enforcement.
