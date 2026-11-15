# Docker verification record

Verified on 14 November 2026 in this checkout using Docker Engine 29.9.3 and Compose 5.7.0. This records executed checks; operational commands are in [DOCKER.md](DOCKER.md).

## Runtime and build

- Application, CLI and database queue worker: **PHP 8.5.12**. This is the latest stable release listed by [php.net](https://www.php.net/) on the verification date and includes the [PHP2Shell fixes](https://www.php.net/archive/2026.php#2026-10-21-5).
- Local phpMyAdmin: PHP **8.3.36**, the patched branch supported by its 5.2 image. Its verified image digest is pinned in `compose.local.yaml`.
- Effective `session.upload_progress.enabled=0` verified in application, worker and phpMyAdmin. This applies PHP's additional session-upload-progress guidance. Remediation evidence is the vendor-fixed runtime version and effective configuration; no exploit was run.
- Host PHP remains 8.3.6 and is not used by this container workflow. This change does not upgrade operating-system PHP packages.
- Production application and Nginx images built successfully. Composer manifest validation and platform requirements passed on PHP 8.5.12. Composer dependencies were retained at their locked versions; no downgrade was needed.
- Production image inspection confirmed the compiled Vite manifest and report fonts are present. Actual `.env` files, a synthetic environment probe, the geospatial directory, a synthetic backup probe, `node_modules`, PHPUnit and Faker are absent. Probes were present in the build context during this check and removed afterward.
- Both Compose configurations validated. Production services are exactly `app`, `queue`, `web`, `db`; local adds `vite` and `phpmyadmin`. Published ports bind to 127.0.0.1. MySQL and FPM have no published ports. Production application volumes are named volumes, without source binds. Application and worker run as `www-data` in both configurations.

## Fresh databases and bootstrap

Checks used a new local Docker database and a separate disposable `mapr-verification` production project. Existing host `.env` and host database were not modified.

- MySQL **8.4.13** ran all **52 migrations** using the non-root `mapr` account in both configurations.
- `CURRENT_USER()` was `mapr@%`. Grants were `USAGE ON *.*` and `ALL PRIVILEGES ON mapr.*`, with no global administrative grant.
- `@@GLOBAL.log_bin = 1` and `@@GLOBAL.log_bin_trust_function_creators = 1` in both configurations.
- All **35 trigger names** matched the repository's history-guard migration, not merely the trigger count.
- SRID 4326 point construction and polygon containment succeeded with a Tandag-area point, using explicit longitude/latitude input order.
- Exact production `php artisan db:seed --force` completed after adding the deployment starter taxonomies. Roles: 4; barangays: 4; disaster types: 7; damage classes: 3; vulnerability categories: 5. Production seeding skipped demo users.
- An interactive `mapr:provision-admin` run created one active CDRRMO administrator in the disposable production database. The generated test password did not appear in captured terminal output. Duplicate-email provisioning failed before password collection. Automated tests additionally cover soft-deleted duplicates, unchanged existing account attributes, weak/mismatched passwords and noninteractive rejection.
- The provisioned administrator logged in and accessed `/cdrrmo` through the complete HTTPS proxy chain.

The disaster/vulnerability lists were missing in the original checkout. The user authorized sensible defaults. They are explicitly documented as starter values for CDRRMO review before official data imports; no resident is automatically assigned a vulnerability by seeding categories.

## Proxy and application behavior

A temporary loopback-only Nginx TLS proxy with a one-day self-signed test certificate forwarded to the production stack's loopback HTTP port. It was used only for verification and removed afterward; public domain/certificate provisioning remains external.

- Before configuring proxy trust, the actual login form action was HTTP despite HTTPS `APP_URL`, demonstrating the request-time scheme problem.
- The observed internal Nginx client address matched the Compose gateway (`172.20.0.1` in this test). Trusting that exact address produced HTTPS login form actions, compiled asset URLs and login redirects, plus secure, HttpOnly session cookies.
- Spoofed `X-Forwarded-Host`, `X-Forwarded-Proto`, `X-Forwarded-Port`, `X-Forwarded-For` and `Forwarded` headers did not change the public origin or scheme because the external test proxy overwrote/removed them.
- Every compiled asset referenced by the production login page returned HTTP 200 through TLS.
- App-only recreation initially reproduced stale Nginx upstream DNS. The final configuration uses Docker DNS with a five-second cache and a variable FastCGI upstream. After the fix, replacing application/worker containers recovered login access without restarting Nginx; the complete TLS/login checks passed again.
- Local HTTP login and CDRRMO access succeeded using the configured demo account.
- Vite served the development client and delivered an actual WebSocket `full-reload` event after a temporary Blade template was created. The template was removed after the check.
- phpMyAdmin was reachable on loopback and accepted the non-root database credentials through its cookie login form.
- Production GD decoded the repository's WebP logo; Dompdf emitted a PDF; the real DOCX preview converter converted a supplied repository DOCX using LibreOffice. Automated report and preview tests also passed.
- A real database queue job was enqueued with the worker stopped, then consumed after starting the worker: zero remaining jobs and zero failed jobs.
- Public upload access through Nginx succeeded. A database administrator record and private/public uploads survived production container/database recreation. Local demo login and a public upload also survived local database/application/worker/web recreation.
- The documented non-root `mysqldump` command succeeded with schema and triggers included. The upload tar archive included private and public files. A full off-server backup restoration was not performed.

## Automated checks and remaining inputs

- Focused administrator/proxy/bootstrap/document/image/report tests: **19 passed, 249 assertions**.
- Full `composer test`: **94 passed, 1 failed, 780 assertions**. The failure is `ResidentHazardMapTest::test_critical_points_keep_public_fields_and_land_near_tandag`: `geospatial_data/CDRA Shapefiles/Critical Point/BARANGAY_HALL_HAZ.shp` and companion files are absent. This test was not skipped or weakened.
- Pint passed for all seven changed/new PHP implementation and test files. `git diff --check` passed.

Supply the separately maintained geospatial datasets to exercise the remaining dataset-dependent test and real data import. Mapbox tiles also need the operator's token. No private datasets were invented, downloaded or baked into images. The configured local application can be used with the reference/demo accounts while its imported registry remains empty.

The local stack is left running at http://localhost:8000/login, with Vite at port 5173 and phpMyAdmin at http://127.0.0.1:8081. Credentials and the persistent local application key are in ignored `.env.docker` (mode 0600). Disposable production verification services, volumes, credentials and synthetic test uploads are removed after verification.


## Local review-fix verification (14 November 2026)

The four local-workflow review fixes were verified separately from the earlier deployment checks above. Tests used a temporary checkout with synthetic credentials and the disposable `mapr-review-fixes` Compose project; its containers, network and private volumes were removed afterward. The existing vendor volume was reused for dependencies; existing application/database services were not recreated.

- Both production and merged local Compose configurations validated. The merged configuration contains the isolated environment mount and bootstrap-cache volume for app and queue.
- Environment regression reproduced by omitting the selector in the disposable bootstrap file: the probe rejected host-derived settings. Restoring the selector passed in both app and queue, before and after explicit `artisan config:cache`. Docker database host/name/user/password/key won; host-only `DB_URL` and `SESSION_DOMAIN` were absent. Sentinel host `.env`, `.env.local`, and cached configuration bytes remained unchanged. No database migrations or seeds were run for these checks.
- `python3 -B -m unittest discover -s tests/docker -v`: all **5 tests passed**, covering absent, correct, absolute host and dangling links; real file/directory preservation; and eight concurrent entrypoint processes. The tests reproduced the original failures before the patch. `tests/docker/environment_probe.php` provides the configuration assertions used inside disposable app/queue containers.
- A marker upload returned identical bytes through Nginx before and after web-container recreation with an initially dangling absolute host storage link.
- Development Vite images built with UID/GID 1000 and 1001. Both had writable home, npm cache and node_modules directories. UID 1000 wrote/verified its npm cache; unmatched numeric UID 1001 completed `npm ci` (165 packages) and served `/@vite/client` over HTTP. The latter ran from a writable temporary application directory to emulate source files owned by the configured local UID.
- The updated production image built successfully. Its storage link was relative, `MAPR_LARAVEL_ENV_PATH` was unset, Artisan reported Laravel 12.70.2, and `php-fpm -t` passed.
- PHP syntax, shell syntax, Pint for both touched PHP files, and `git diff --check` passed. Final independent review found no correctness/regression findings in the scoped fixes.
- Full `composer test` rerun: **94 passed, 1 failed, 780 assertions**. The existing failure remains `ResidentHazardMapTest::test_critical_points_keep_public_fields_and_land_near_tandag`, caused by the absent `BARANGAY_HALL_HAZ` shapefile dataset. No tests were skipped or weakened.

The running local services still use their earlier images/mounts. Apply these fixes to that stack with the documented environment selection and `docker compose -f compose.yaml -f compose.local.yaml up -d --build app queue vite`; this rebuilds images and recreates services with the isolated mounts. No existing database/upload volumes need removal.
