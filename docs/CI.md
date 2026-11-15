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

## Activation status and owner setup

The implementation is prepared on `ci/github-actions-branch-flow`. Repository
rulesets and settings have **not** been applied. Live branch enforcement and the
GitHub promotion smoke test remain pending repository owner/admin access. Local
checks do not prove that GitHub branch protection is active.

The branch includes the Docker integration prerequisite and merges the newer
`main` application changes. Land the Docker prerequisite first, then land this CI
branch on `main`, before enabling rulesets. This bootstrap PR intentionally fails
`Branch flow` because its source is not `testing`. Until the Docker prerequisite
is merged, its older `feat:` commits also fail `Commit messages`; do not rewrite
existing history or add a permanent policy bypass. Inspect the bootstrap image
build result before merging.

Run the following as an administrator after the bootstrap is on `main`:

```bash
gh api repos/4LPHACODER/MapR --jq .permissions.admin  # must be true
git fetch origin
git push origin origin/main:refs/heads/testing
git push origin origin/main:refs/heads/develop
git ls-remote --heads origin develop testing main
```

Create only missing branches; if a branch already exists, inspect it instead of
resetting it. All three branches must initially have the same commit. Wait for
Review on `develop`, Testing on `testing`, and Production on `main` to succeed.
Check that the full suite runs the LibreOffice tests without skips:

```bash
gh run list --branch develop --limit 5
gh run list --branch testing --limit 5
gh run list --branch main --limit 5
```

Enable both repository merge methods; rulesets restrict the method per branch.
Keep automatic deletion disabled until all three branches are protected:

```bash
gh api --method PATCH repos/4LPHACODER/MapR \
  -F allow_squash_merge=true -F allow_merge_commit=true \
  -F allow_rebase_merge=false -F delete_branch_on_merge=false \
  -f squash_merge_commit_title=PR_TITLE \
  -f squash_merge_commit_message=COMMIT_MESSAGES -f default_branch=develop

for branch in develop testing main; do
  gh api --method POST repos/4LPHACODER/MapR/rulesets \
    --input ".github/rulesets/$branch.json" --jq '"\(.id) \(.name)"'
done

gh api repos/4LPHACODER/MapR/rulesets \
  --jq '.[] | "\(.id) \(.name) \(.enforcement)"'
gh api --method PATCH repos/4LPHACODER/MapR -F delete_branch_on_merge=true
```

Create each ruleset once. For subsequent updates, find its existing ID and use
`PUT`, for example:

```bash
id=$(gh api repos/4LPHACODER/MapR/rulesets \
  --jq '.[] | select(.name == "main: production") | .id')
gh api --method PUT "repos/4LPHACODER/MapR/rulesets/$id" \
  --input .github/rulesets/main.json
```

After activation, verify the complete flow using a disposable work branch:

1. Confirm a direct push to `develop` is rejected by the ruleset.
2. Open a `docs/ci-smoke-test` PR into `develop` with a deliberately invalid
   `docs: verify ci flow` commit, bypassing only the local hook with `--no-verify`.
   Confirm `Commit messages` fails and merging is blocked.
3. Amend to `docs/verify ci flow`, push with `--force-with-lease`, and confirm all
   five review checks pass. Obtain one approval from another collaborator and
   squash-merge. Verify the squash subject matches the PR title.
4. Open `develop` into `main`; confirm `Branch flow` rejects it, then close the
   PR without deleting `develop`.
5. Promote `develop` into `testing` with a merge commit. Confirm all five testing
   checks pass and `DocxPreviewTest` does not skip.
6. Promote `testing` into `main` with a merge commit. Confirm all three production
   checks pass, including the subsequent Production push run. Its summary must
   confirm images were built without publishing them.
7. Fetch and verify empty content diffs between `origin/develop`,
   `origin/testing`, and `origin/main`. Merge commits may differ.

Record the actual verification date only after this live smoke test succeeds.
