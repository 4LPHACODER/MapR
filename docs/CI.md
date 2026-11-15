# CI and branch flow

MapR promotes changes through three long-lived branches. GitHub Actions and branch rulesets enforce every step.

```
<type>/<name>  ──squash──▶  develop  ──merge──▶  testing  ──merge──▶  main
   your work              review stage          test stage        production
```

| Branch | Purpose | Accepts PRs from | Merge method | Required checks |
| --- | --- | --- | --- | --- |
| `develop` | Code review of new features and fixes | `<type>/<name>` work branches | Squash | Branch flow, Commit messages, Lint, Tests, CI scripts + 1 approval |
| `testing` | Full test run before release | `develop` only | Merge commit | Branch flow, Commit messages, Full test suite, Docker tests, images / Build images |
| `main` | Production | `testing` only | Merge commit | Branch flow, Commit messages, images / Build images |

Nobody pushes directly to these branches. Urgent fixes follow the same path (`hotfix/<name>` → `develop` → `testing` → `main`).

## Commit messages

Every commit subject and every pull request title uses:

```
<type>/<description>
```

- Types: `feature`, `fix`, `hotfix`, `refactor`, `docs`, `test`, `chore`, `ci`, `style`, `perf`, `build`, `revert`.
- Description starts lowercase (or a digit), no trailing period, whole subject at most 72 characters.
- Only the first line is checked; write any detail in the body.

Examples: `feature/add resident export`, `fix/login redirect loop`, `docs/describe backup restore`.

Enable the local hook once per clone so bad messages are rejected before they reach GitHub:

```bash
composer hooks
```

Branch names use the same types: `feature/resident-export`, `fix/login-redirect`.

## Everyday workflow

1. `git checkout develop && git pull && git checkout -b feature/<name>`
2. Commit with `feature/...` messages; push; open a PR into `develop` titled `feature/<description>`.
3. Address review; squash-merge when approved and green.

## Promoting a release

1. Open a PR from `develop` into `testing` titled e.g. `chore/promote develop to testing`. Merge with **Create a merge commit** once the Testing checks pass.
2. Open a PR from `testing` into `main` titled e.g. `chore/release testing to main`. Merge with **Create a merge commit** once the production image build passes.
3. Deploy manually from `main` by following [`DOCKER.md`](DOCKER.md). CI builds the images but does not publish or deploy them.

## Where the checks live

| Check | Workflow | Runs |
| --- | --- | --- |
| Branch flow, Commit messages | `.github/workflows/pr-policy.yml` | `scripts/ci/check-branch-flow.sh`, `scripts/ci/check-commit-message.sh` |
| Lint, Tests, CI scripts | `.github/workflows/review.yml` | Pint, `composer test`, `tests/ci` |
| Full test suite, Docker tests | `.github/workflows/testing.yml` | `composer test` in the development image (with LibreOffice), `tests/docker` |
| images / Build images | `.github/workflows/build-images.yml` | Production `app` and `web` image builds |

Branch rulesets are versioned in `.github/rulesets/`. If you rename a job, update the matching ruleset `context` or the PR will wait forever for a check that never reports.
