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

## Deferred live verification

Per the user's instruction, implementation is prepared locally while repository
owner access is arranged. No branch rulesets or repository settings were changed,
no remote branches or PRs were created, and no images were published or deployed.
GitHub Actions runs, blocked direct pushes, required approvals, allowed merge
methods, and the full promotion smoke test have not been verified on GitHub.
Follow [CI activation instructions](CI.md#activation-status-and-owner-setup) when
admin access becomes available. Do not treat local results as proof of active
GitHub branch enforcement.
