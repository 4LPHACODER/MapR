# Docker setup and operations

MapR uses PHP **8.5.12**, the latest stable release checked on 14 November 2026, in its application, CLI and queue images. This supersedes the original plan's PHP 8.3 target. [PHP's security advisory](https://www.php.net/archive/2026.php#2026-10-21-5) identifies this as a PHP2Shell-fixed release (CVE-2026-109618 / CVE-2026-109619). `session.upload_progress.enabled=0` applies PHP's additional [23 October guidance](https://www.php.net/archive/2026.php#2026-10-23-1) for CVE-2026-115560. Rebuild with `--pull` when updating; do not assume an existing container changes when its image is rebuilt.

phpMyAdmin is local only. Its supported PHP branch differs from MapR's: the verified 5.2 Apache image uses patched PHP 8.3.36, with the same upload-progress setting mounted explicitly. Recheck its effective PHP version after pulling an update. Host PHP is not used by this workflow; a host installation remains separately managed.

## Local first run

Run Composer and Artisan inside the application container. Plain `composer install` on the host uses host PHP, which Docker does not upgrade. For an already running local stack, use this command from the repository root without exporting shell variables:

```bash
docker compose --env-file .env.docker -f compose.yaml -f compose.local.yaml exec app composer install
```

The container includes the required PHP version and DOM/XML extensions. Keep the lock file and platform checks enabled; this error does not call for `composer update` or ignoring platform requirements.

Install Docker Engine and Docker Compose 2.24.4 or later for [`!override` support](https://docs.docker.com/reference/compose-file/merge/#replace-value) (verified here with Compose v5.7.0). The local environment-file list must replace, rather than append to, the production list. From the repository root:

```bash
cp .env.docker.example .env.docker
chmod 600 .env.docker
```

For a Linux user whose UID/GID are not 1000, also set `LOCAL_UID` and `LOCAL_GID` to the values from `id -u` / `id -g` before building. Rebuild the development app and Vite images after changing these values. Existing named volumes retain their previous ownership; use a fresh Compose project when verifying a different UID/GID.

Edit `.env.docker`: set different random `DB_PASSWORD` and `MYSQL_ROOT_PASSWORD` and your `MAPBOX_ACCESS_TOKEN`. Leave an existing host `.env` and its database untouched. The Docker environment is separate. Local app, queue, and one-off Artisan containers mount the selected `MAPR_ENV_FILE` read-only at `/run/mapr/.env`; `MAPR_LARAVEL_ENV_PATH` selects that directory before Laravel loads environment files. Host `.env` and `.env.local` files are not loaded. A separate `local-bootstrap-cache` named volume hides host cached configuration and is shared by app and queue. Never commit actual credentials.

Select that file for Compose interpolation and container environment loading in every new shell. App, queue, and MySQL reject a selected file whose `APP_ENV` does not match the target stack (`local` with the local override, `production` with the base file). This also protects against a leftover `MAPR_ENV_FILE` export; keep `APP_ENV` in custom environment files:

```bash
export COMPOSE_ENV_FILES=.env.docker
export MAPR_ENV_FILE=.env.docker
mkdir -p geospatial_data
# Local development, including phpMyAdmin
docker compose -f compose.yaml -f compose.local.yaml up -d --build app web db vite phpmyadmin
```

Install development PHP dependencies into the named vendor volume, generate the key once, and save it in `.env.docker`:

```bash
docker compose -f compose.yaml -f compose.local.yaml run --rm app composer install --no-interaction --prefer-dist
docker compose -f compose.yaml -f compose.local.yaml run --rm app php artisan key:generate --show
```

Copy the printed `base64:...` key to `APP_KEY` in `.env.docker`, then recreate services to load it. `key:generate` without `--show` attempts to edit the read-only Docker environment mount and fails; always use the shown command here. On this first run, recreate app now; queue loads the updated file when started below. For later environment edits, recreate both app and queue. If you explicitly cached configuration, explicitly clear it before loading changed settings.

```bash
docker compose -f compose.yaml -f compose.local.yaml up -d --force-recreate app
docker compose -f compose.yaml -f compose.local.yaml exec app php artisan migrate
docker compose -f compose.yaml -f compose.local.yaml exec app php artisan db:seed
```

The first-run commands leave the queue stopped while dependencies and tables are created. Start it after setup:

```bash
docker compose -f compose.yaml -f compose.local.yaml up -d queue
```

Local logs, framework caches, sessions, and font caches live in the `local-storage` named volume rather than the host `storage/` directory. Uploads use the separate `uploads` volume. View application stderr logs with `docker compose -f compose.yaml -f compose.local.yaml logs app queue`.

Vite installs locked npm dependencies automatically into a named volume on every start, so startup requires registry access. If installation fails, restore registry connectivity and restart `vite`; the repeated install keeps dependencies aligned with `package-lock.json`. Its home and npm cache use `/home/mapr`, owned by the build-time local UID/GID, including UIDs without a matching account. Startup normalizes `public/storage` to `../storage/app/public`, including existing host-created or dangling symlinks. This updates the link in the source checkout and resolves on both host and containers. A real file or directory at that path is preserved and blocks startup with an error; move it aside manually before retrying. MapR: http://localhost:8000/login; Vite: http://localhost:5173; phpMyAdmin: http://127.0.0.1:8081. All published ports bind to loopback. Log in to phpMyAdmin using `DB_USERNAME` and `DB_PASSWORD`; credentials are not prefilled. Local role account emails are listed in the README. Their initial password is `password`.

The ignored `geospatial_data/` folder is mounted read-only. Supply the separately obtained datasets in the README's layout, or set `MAPR_DATA_DIR` to an existing absolute dataset directory. The source path must exist. Import is explicit:

```bash
docker compose -f compose.yaml -f compose.local.yaml exec app php artisan mapr:import-geospatial
```

No datasets are downloaded or baked into images. Without them the application works with empty registry/map data. To run the automated suite:

```bash
docker compose -f compose.yaml -f compose.local.yaml exec app composer test
```

The SQLite test suite does not replace fresh MySQL migration and spatial checks.

## Production first run

Use a separate server or Compose project and fresh volumes. The base Compose file defaults to `.env.docker.production`; the local override defaults to `.env.docker`. `MAPR_ENV_FILE` explicitly selects another container environment file, while `COMPOSE_ENV_FILES` (or `--env-file`) selects Compose interpolation values. App, queue, and MySQL all load credentials from the selected Docker environment file. Its `APP_ENV` must be `production` for the base stack or `local` for the local override. A mismatch or missing value stops startup before application storage changes or MySQL initialization. MySQL maps its `DB_*` settings to the official image’s `MYSQL_*` variables at startup, so host interpolation cannot substitute different database credentials. Compose interpolation still controls ports, image tags, and build arguments; select the same file for those settings as well. There are no source mounts, Vite, phpMyAdmin, or published MySQL/FPM ports in `compose.yaml`. Only Nginx is published, to a configurable loopback port. Nginx waits for the app’s FPM port to accept connections before starting. This check covers the FPM listener; complete the application and HTTPS checks below before opening public traffic.

```bash
cp .env.docker.production.example .env.docker.production
chmod 600 .env.docker.production
export COMPOSE_ENV_FILES=.env.docker.production
export MAPR_ENV_FILE=.env.docker.production
export COMPOSE_PROJECT_NAME=mapr-production
```

Before public use, complete the HTTPS reverse proxy section below: determine the actual upstream address, set `TRUSTED_PROXIES`, and verify login through the public HTTPS origin. Empty proxy trust intentionally rejects forwarded headers.

Set strong distinct DB/root passwords, `APP_URL=https://YOUR_PUBLIC_HOST`, `SESSION_SECURE_COOKIE=true`, and a persistent `APP_KEY`. Generate the key without creating a new stack or touching the host `.env`:

```bash
docker compose -f compose.yaml build --pull app web
docker compose -f compose.yaml run --rm --no-deps app php artisan key:generate --show
```

Save the key in `.env.docker.production` and retain it in encrypted off-server backups. Never regenerate it during deployment.

```bash
# First run: start the queue after migrations and administrator provisioning.
# Production: no phpMyAdmin or Vite service
docker compose -f compose.yaml up -d --build app web db
docker compose -f compose.yaml ps
# After MySQL reports healthy:
docker compose -f compose.yaml exec app php artisan migrate --force
docker compose -f compose.yaml exec app php artisan db:seed --force
```

Reference defaults in `config/mapr_reference.php` include common disaster types and five vulnerability categories. They are deployment starter values chosen to make fresh seeding usable, not an assertion of official CDRRMO approval. Review them before importing official records. Category creation does not assign a vulnerability to any resident.

Changing environment credentials does not update users or passwords in an existing MySQL volume; reconcile existing accounts explicitly before changing deployed credentials.

The official MySQL image creates the non-root `MYSQL_USER` from `DB_USERNAME` and grants `ALL PRIVILEGES ON mapr.*` for the configured database only, including schema changes, indexes, references, and **TRIGGER**. No global administrative grants are necessary. For an externally managed database, the migration account needs database-scoped `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES, TRIGGER` grants. Use the non-root credentials for all Artisan commands. `--log-bin-trust-function-creators=1` permits trigger migrations with binary logging enabled. Do not disable binary logs, strip guard migrations, or run migrations as root.

Verify reference data before creating the administrator:

```bash
docker compose -f compose.yaml exec app php artisan tinker --execute='foreach (["roles", "barangays", "disaster_types", "damage_classes", "vulnerability_categories", "users"] as $table) { echo $table.": ".DB::table($table)->count().PHP_EOL; }'
docker compose -f compose.yaml exec app php artisan mapr:provision-admin
```

On a fresh production database all five reference tables must contain records and users must be zero before provisioning. The interactive command asks for first/last name, unique email, and a masked password plus confirmation (at least 12 characters, upper/lowercase, number and symbol). It creates an active `cdrrmo_admin` using Laravel hashing. Existing emails, including soft-deleted accounts, are rejected without account changes. It refuses noninteractive execution and accepts no password arguments. Never put administrator passwords in shell commands, environment files, logs or images.

After migrations and administrator provisioning, start the worker:

```bash
docker compose -f compose.yaml up -d queue
```

Open the public HTTPS `/login`, sign in with the new administrator, and verify access to `/cdrrmo` before declaring bootstrap complete. There is no self-registration route. Production provisioning is always an explicit operator action.

## HTTPS reverse proxy

TLS/domain provisioning is external to this stack. The public proxy must connect to `127.0.0.1:8000` (or `MAPR_HTTP_PORT`) on the Docker host. Do not publish that upstream on all interfaces. Use a fixed public origin and overwrite every forwarding header; never append untrusted incoming values. Example inside an existing external Nginx TLS server:

```nginx
server_name mapr.example.com;
# ssl_certificate / ssl_certificate_key belong to the external proxy.
location / {
    proxy_pass http://127.0.0.1:8000;
    proxy_set_header Host mapr.example.com;
    proxy_set_header X-Forwarded-Host mapr.example.com;
    proxy_set_header X-Forwarded-Proto https;
    proxy_set_header X-Forwarded-Port 443;
    proxy_set_header X-Forwarded-For $remote_addr;
    proxy_set_header Forwarded "";
    proxy_set_header X-Forwarded-Prefix "";
}
```

For a different public port, set its actual value instead of 443. Configure the default TLS virtual host to reject unknown hosts. If there is another load balancer before the TLS proxy, establish that proxy's own narrowly scoped real-IP trust first.

Container Nginx passes the sanitized `X-Forwarded-*` headers unchanged to FPM; it declares CGI parameters explicitly and omits `HTTPS` and `REQUEST_SCHEME` rather than inheriting scheme values from the internal HTTP hop. PHP `REMOTE_ADDR` comes from Nginx's `$remote_addr`: for a host-based loopback proxy it is commonly the Compose network gateway, not 127.0.0.1. Inspect the actual path:

```bash
docker network inspect mapr-production_default --format '{{range .IPAM.Config}}{{.Gateway}}{{end}}'
docker compose -f compose.yaml logs --tail=20 web
```

Send a request through your public proxy and match its Nginx access-log source IP. Put that exact IP (or an intentionally small CIDR) into `TRUSTED_PROXIES` in `.env.docker.production`; comma-separate multiple verified addresses. No wildcard trust. A containerized external proxy can instead appear as its fixed container IP. Network recreation can change gateway addresses: reverify then update trust. Trusting the gateway assumes only trusted operators can reach the host loopback upstream.

If configuration was cached, clear it in both services before recreating them; recreation alone does not invalidate a persisted Laravel configuration cache. After recreation, cache configuration again only if your deployment uses it.

```bash
docker compose -f compose.yaml exec app php artisan config:clear
docker compose -f compose.yaml exec queue php artisan config:clear
docker compose -f compose.yaml up -d --force-recreate app queue
```

Verify through the full HTTPS chain: `/login` form action and `/build/` URLs use HTTPS, redirects stay on the public origin, and session cookies contain `Secure; HttpOnly`. Repeat with spoofed `X-Forwarded-Proto: http`, `X-Forwarded-Host: evil.example`, `X-Forwarded-Port: 80`, and `X-Forwarded-For` headers: the public proxy must overwrite them. `APP_URL` alone does not prove request-time HTTPS recognition.

## Backups, deployments and rollback

Back up database and uploads together during a maintenance window, and keep the environment/key separately encrypted. Example (shell redirection writes backups on the host):

```bash
mkdir -p backups
chmod 700 backups
docker compose -f compose.yaml exec app php artisan down
docker compose -f compose.yaml stop queue
docker compose -f compose.yaml exec -T db sh -c 'MYSQL_PWD="$DB_PASSWORD" mysqldump -u "$DB_USERNAME" --single-transaction --no-tablespaces --triggers "$DB_DATABASE"' > backups/database.sql
docker compose -f compose.yaml exec -T app tar -C storage/app -czf - . > backups/uploads.tar.gz
docker compose -f compose.yaml start queue
docker compose -f compose.yaml exec app php artisan up
```

Protect and move backups off-server. Test restoration on a separate project: initialize a fresh database, restore SQL as an account with database-scoped trigger privileges, restore `storage/app` and the original `APP_KEY`, then verify records, files, and login. Never use production data casually for local tests.

For each deployment, retain the previous image tags and take a backup. Set an immutable `IMAGE_TAG` in the shell or deployment environment:

```bash
export IMAGE_TAG=YOUR_RELEASE_TAG
docker compose -f compose.yaml build --pull app web
docker compose -f compose.yaml exec app php artisan down
docker compose -f compose.yaml stop queue web
docker compose -f compose.yaml up -d --no-deps app
docker compose -f compose.yaml exec app php artisan migrate --force
docker compose -f compose.yaml up -d --no-deps --force-recreate queue web
docker compose -f compose.yaml exec app php artisan up
```

The web service stays stopped while the replacement application runs migrations; do not rely on a file-based maintenance flag surviving container replacement. If migration fails, keep web/queue stopped until recovery is complete.

No startup script runs migrations, seeds, or key generation. Cache configuration only explicitly, after environment configuration; clear caches on environment changes. The same image runs FPM and the worker, and worker recreation loads the new code. Named volumes retain records and uploads through `up --force-recreate` and `down`; **`down -v` destroys them**.

Rollback to a retained image tag only if the current schema is compatible. Schema changes can make image rollback unsafe; follow a reviewed reverse migration or restore the matching database/uploads backup and original key, accepting loss of writes since the backup. Do not assume `migrate:rollback` is safe.

## Verification commands

```bash
docker compose -f compose.yaml config --services
# Must list only app, queue, web, db.
docker compose -f compose.yaml -f compose.local.yaml config --services
docker compose -f compose.yaml exec app php -v
docker compose -f compose.yaml exec queue php -r 'echo PHP_VERSION, " upload-progress=", ini_get("session.upload_progress.enabled"), PHP_EOL;'
docker compose -f compose.yaml exec db sh -c 'MYSQL_PWD="$DB_PASSWORD" mysql -u "$DB_USERNAME" "$DB_DATABASE" -e "SELECT CURRENT_USER(), @@GLOBAL.log_bin, @@GLOBAL.log_bin_trust_function_creators; SHOW TRIGGERS; SELECT ST_AsText(ST_GeomFromText(\"POINT(126.216 9.074)\",4326,\"axis-order=long-lat\"));"'
# Local PHP runtime used by phpMyAdmin:
docker compose -f compose.yaml -f compose.local.yaml exec phpmyadmin php -r 'echo PHP_VERSION, " upload-progress=", ini_get("session.upload_progress.enabled"), PHP_EOL;'
```

Build and inspect production images for absent `.env`, private datasets, `node_modules`, PHPUnit/Faker and source mounts. Exercise login, assets, hot reload locally, queued jobs, PDF rendering, LibreOffice DOCX conversion, and upload downloads. Recreate containers and verify a known record and uploaded file survive. Keep runtime results distinct from this setup guide; see [verification record](DOCKER_VERIFICATION.md).
