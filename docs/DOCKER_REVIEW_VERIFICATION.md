# Docker Bugbot findings verification

Verified 15 November 2026 against `.superpowers/bugbot-review-docker-integration.md`.

| Finding | Assessment and action |
| --- | --- |
| Production secure cookie / proxy trust | Production HTTPS settings are intentional. Empty proxy trust rejects forwarded headers. Added an explicit first-run prerequisite pointing to proxy setup; retain secure cookies and narrowly scoped trust. Actual public proxy addresses require deployment-specific verification. |
| Production falls back to local environment | Confirmed by a failing Compose regression test. Base now defaults to `.env.docker.production`; local override replaces the environment-file list with `.env.docker`. Explicit `MAPR_ENV_FILE` still works. |
| Empty production proxy trust lacks fail-fast | Overlaps the first finding. A blanket boot failure would also block CLI key generation before network setup. Documented required setup rather than blocking bootstrap. |
| Local storage shadows host storage | Confirmed, intentional named-volume isolation. Documented storage locations and log access. |
| Cached proxy configuration survives settings changes | Confirmed operations-guide gap. Added explicit `config:clear` commands for app and queue before recreation when configuration was cached. |
| Missing explicit FastCGI HTTP_HOST | No demonstrated defect. Nginx passes request headers by default; explicit HTTP_HOST is not required by the current configuration. |
| Absolute font cache paths | Confirmed stale host artifact, but excluded from images; fresh local storage volume hides host storage and production only persists storage/app. No Docker change needed. Existing manually populated volumes still need separate inspection if PDF problems occur. |
| Vite npm ci on every startup | Confirmed network dependency; intentional lockfile synchronization. Documented connectivity and restart requirements. |
| Uncommitted Docker files | Confirmed workspace state, not a runtime defect. Changes remain uncommitted for user review. |

Validation:

- Before the fix, the regression test showed production configuration successfully loading the local file when no production file existed.
- `python3 -B -m unittest discover -s tests/docker -v`: 9 passed, including production/local defaults, explicit selection, and entrypoint concurrency/link handling.
- `docker exec mapr-app-1 composer test`: 94 passed, 1 failed (780 assertions). `ResidentHazardMapTest::test_critical_points_keep_public_fields_and_land_near_tandag` fails because `geospatial_data/CDRA Shapefiles/Critical Point/BARANGAY_HALL_HAZ.shp` is incomplete/missing. This change does not modify datasets or that test.
- The application run includes a passing `TrustedProxyTest`; public HTTPS proxy deployment was not exercised.
- Compose v5.7.0 used. Local override requires support for the `!override` YAML tag.

## Re-review findings and fixes

Verified `.superpowers/bugbot-rereview-codex-fixes.md` on 15 November 2026.

| Finding | Result |
| --- | --- |
| Compose interpolation and container credentials diverge | Confirmed with a failing test using conflicting host credentials. MySQL now loads the same selected environment file as app and queue, maps DB_* to MYSQL_* inside its startup shell, validates required values, and invokes the official MySQL entrypoint. Local DB replaces the production file list too. Healthcheck and operational SQL/backup commands use DB_* from that file. |
| Mandatory !override compatibility | Prerequisite already documented. Unsupported Compose parsers reject the tag; they do not silently merge it. Both local and production selection tests pass on Compose v5.7.0. |
| Production environment overrides env_file | Intentional production protections. Local override provides local behavior. No change needed. |
| Integration remains uncommitted | Process observation; changes remain available for review. |
| Production fallback test could be stronger | Added a local-only sentinel and verified it never appears in the production environment. Added database initialization checks for conflicting host credentials, missing required values, and retained mysqld arguments in both local and production configurations. |

Fresh validation after these changes:

- Docker tests: **10 passed** (`python3 -B -m unittest discover -s tests/docker -v`).
- An isolated Compose project with a temporary MySQL data directory successfully initialized MySQL 8.4, passed its healthcheck, and accepted the selected-file non-root credentials despite conflicting host values. The probe also verified the selected database and `log_bin_trust_function_creators=1`. The probe container and network were removed; existing application containers and database volumes were not recreated.
- Cavecrew diff review: no issues.
- Existing database accounts are not changed by editing environment files. Reconcile their credentials explicitly when changing a deployed environment.
- Fresh application suite: **94 passed, 1 failed** (780 assertions), with the same `ResidentHazardMapTest::test_critical_points_keep_public_fields_and_land_near_tandag` missing/incomplete `BARANGAY_HALL_HAZ` shapefile failure described above. `TrustedProxyTest` passed.

## Review 3: environment identity and startup readiness

Verified `.superpowers/bugbot-review-3-codex-db-env-fix.md` on 15 November 2026.

| Finding | Assessment and action |
| --- | --- |
| Sticky MAPR_ENV_FILE selects wrong stack settings | Confirmed with failing startup tests. Compose now sets MAPR_EXPECTED_ENV per stack and preserves APP_ENV from the selected file. App/queue reject missing or mismatched APP_ENV before storage changes; MySQL rejects it before initialization. Matching production environments still allow CLI bootstrap. This supersedes earlier references to APP_ENV being forcibly overridden. Custom files must declare APP_ENV=local or APP_ENV=production as appropriate. |
| Older Compose silently merges !override | Not supported by evidence: unsupported parsers reject the tag. Keep the documented plugin requirement; current merged configurations are tested on Compose v5.7.0. |
| Production requires configured trusted proxies | Intentional deployment requirement. Empty trust remains a secure default; proxy setup and public HTTPS verification remain required. |
| Nginx may start before FPM listens | Added an app-only TCP listener healthcheck and made web depend on app being healthy. The queue has no FPM healthcheck. This covers the initial FPM listener, not application migrations, public proxy setup, or continuous availability during later restarts. |
| Nested official MySQL entrypoint is fragile | No current defect demonstrated. Retained the existing command and verified initialization against MySQL 8.4 again. |
| Fail-fast test should check local-file exclusion | Already covered on the successful production path; extended that exclusion check to DB as well as app/queue. The missing-file path separately checks failure. |
| Existing volume credentials do not reconcile | Intentional MySQL behavior, already documented. No automatic account mutation. |
| Files uncommitted | Process observation; working changes remain available for review. |

Validation after these changes:

- `python3 -B -m unittest discover -s tests/docker -v`: **14 passed**. New tests first failed for accepted mismatches and missing readiness gating, then passed after fixes. The listener probe passes with an accepting socket and fails when it closes.
- Isolated MySQL 8.4 startup with matching production APP_ENV passed initialization, healthcheck, selected-file login, and the trigger flag check. An APP_ENV=local one-off invocation against the production model exited with the environment mismatch error. Temporary containers/network were removed; existing application volumes were not changed.
- `docker exec mapr-app-1 composer test`: **94 passed, 1 failed** (780 assertions). The same missing/incomplete BARANGAY_HALL_HAZ shapefile failure remains; TrustedProxyTest passed. This runs the application suite in the existing local container; changed entrypoint behavior is covered separately by startup tests.
- Shell syntax and tracked diff whitespace checks passed. Cavecrew review: no issues.

Rebuild app/queue images and recreate the relevant services to apply the baked-in entrypoint guard. These changes do not deploy or recreate the existing application stack. The environment guard checks the file's declared APP_ENV; it cannot determine whether credentials inside an intentionally mislabeled file belong to another deployment.

## Review 4: verified before changes

Verified `.superpowers/bugbot-review-4-env-identity-fpm-readiness.md` on 15 November 2026. All 14 Docker tests passed before edits. No new runtime defect was demonstrated; this round changes only tests and operating instructions.

| # | Finding | Verified result / action |
| --- | --- | --- |
| 1 | HTTPS requires TRUSTED_PROXIES | Intentional trust boundary, not a new defect. Keep the documented deployment-specific configuration and public HTTPS checks. |
| 2 | Unsupported !override silently merges files | Silent merging is not established. Docker documents !override as requiring Compose 2.24.4 or later. Clarified that minimum in the setup guide; v5.7.0 remains the tested version. |
| 3 | FPM listener is not application/schema readiness; queue starts before migration | Listener scope already documented. Improved local and production first-run commands to start services without queue, then start queue after dependency installation/migrations (and production provisioning). No automatic migrations or schema readiness probe added. This sequence is for a fresh stack; use the existing stop/migrate/recreate workflow for subsequent deployments. |
| 4 | Entrypoint changes require image rebuild | Correct, normal image lifecycle; already documented, and first-run/deployment commands build images. |
| 5 | APP_ENV label cannot establish credential provenance | Correct, documented scope. An application cannot infer which deployment arbitrary valid credentials belong to. |
| 6 | Later FPM failure requires manual recreate | Overstated. Initial dependency gating is not continuous gating, but app has restart: unless-stopped and Nginx dynamically resolves its upstream. Exited containers can recover automatically. A live but hung process is a distinct monitoring limitation; no reproduced hang supplied. |
| 7 | Queue has no healthcheck | Correct, but no hung-worker defect demonstrated. Queue already has a job timeout and maximum worker lifetime. No speculative health mechanism added. |
| 8 | APP_DEBUG overridden by Compose | Intentional production/local policy. |
| 9 | Explicit-file fixture is incomplete | Confirmed test-quality gap. Completed the selected environment fixture with production APP_ENV and DB credentials; strengthened the test to check app, queue, and DB identity and credential selection. Existing startup tests cover validation and DB initialization mapping. |
| 10 | Guard optional outside Compose | Intentional compatibility. Documented Compose workflows always set expected identity. |
| 11 | Docker tree uncommitted | Confirmed process observation. No commit or deployment performed. |

Verification after edits: `python3 -B -m unittest discover -s tests/docker -v` — **14 passed**, including the stronger explicit-file test. Application suite was not rerun in this round because production code and Compose service definitions were unchanged; the previous application result and missing-shapefile failure remain recorded above.

Authoritative references: Docker's [merge specification](https://docs.docker.com/reference/compose-file/merge/#replace-value) documents the Compose minimum and replacement behavior; [startup ordering](https://docs.docker.com/compose/how-tos/startup-order/) describes service_healthy dependency gating; [restart policies](https://docs.docker.com/engine/containers/start-containers-automatically/) explains exit recovery and manual-stop exceptions. These support the distinctions above, not a claim of continuous application health monitoring.

Cavecrew review of this round’s test and guide changes: no issues.
