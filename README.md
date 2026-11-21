# MapR

MapR is a post-disaster building damage assessment and geospatial resident impact estimation application for Tandag City, Surigao del Sur. The study area covers Bag-ong Lungsod, Telaje, Mabua, and Bongtud.

The research project is titled **“MapR: Evaluating Fine-Tuned EfficientNetB0 with AdamW Optimization for Post-Disaster Building Damage Classification and Geospatial-Based Resident Impact Estimation.”**

## Workspaces

| Workspace | URL | Purpose |
| --- | --- | --- |
| Resident | `/resident` | Submit damage reports and photos, track reviews, and view hazard maps |
| Barangay / BDRRM | `/bdrrm` | Review resident submissions and prepare barangay reports |
| Assessor / RDANA | `/rdana` | Review barangay reports, verify building damage, and submit field assessments |
| CDRRMO | `/cdrrmo` | Manage incidents, residents, and users; review reports; estimate resident impact using map selections |

The reporting workflow moves from resident submissions through barangay review and assessor verification to CDRRMO consolidation. Access is controlled by role and, for field personnel, barangay assignments.

MapR uses Laravel 12, PHP, Vue 3, Vue Router, Vite 7, Tailwind CSS 4, MySQL spatial queries, and Mapbox GL. Report rendering uses Dompdf; imported DOCX previews use LibreOffice.

### Current limitations

- **AI inference is not connected.** The queued `ClassifyBuildingPhoto` job currently records a failed prediction without assigning a damage class. The repository does not provide an executable EfficientNetB0 inference integration.
- Map eligibility requires field-verified building locations. Imported CBMS coordinates start unverified.
- Resident impact counts are estimates subject to field validation, not confirmed casualty counts.

Changes reach production through `develop` → `testing` → `main`, with commit messages formatted as `<type>/<description>` (for example `feature/add resident export`). See [docs/CI.md](docs/CI.md) and run `composer hooks` once per clone.

## Local setup

Run MapR directly with PHP, Composer, MySQL, and Node.js. Run commands from the repository root.

### 1. Install prerequisites

- PHP **8.5.12+ within the 8.5 series** and Composer 2. Install the extensions required by Composer, including `pdo_mysql`, `pdo_sqlite` (tests), `mbstring`, `xml`, `dom`, `fileinfo`, `curl`, `gd`, `zip`, `bcmath`, `intl`, `exif`, and `pcntl` (queue listener/log streaming). Use the same PHP version for CLI and PHP-FPM.
- Node.js 20.19+ within the 20.x series, or Node.js 22.12+; npm is required for frontend dependencies.
- MySQL **8.0.16+** with spatial support (8.4 is also supported). Migrations use MySQL-specific SQL, geometry columns, and SRID 4326. SQLite is not a substitute for running the application schema.
- A Mapbox access token for map tiles.
- The study area's geospatial source files for the full data import (see below).
- Optional: LibreOffice Writer at `/usr/bin/soffice` or `/usr/bin/libreoffice`, plus DejaVu/Liberation fonts, for imported DOCX previews. Install it to run preview tests without skips. Original documents can still be downloaded without it.

Set the following in the PHP configuration used by CLI and PHP-FPM (`php --ini` shows the CLI configuration), then restart PHP-FPM after changes. These limits support the application's photo uploads:

```ini
memory_limit=512M
upload_max_filesize=25M
post_max_size=30M
max_execution_time=120
expose_php=Off
session.upload_progress.enabled=0
```

Set the web server's request body limit to at least 30 MiB as well; for Nginx, use `client_max_body_size 30m;` in the server block.

Report PDFs use Carlito at `/usr/share/fonts/google-carlito-fonts/Carlito-Regular.ttf` and `Carlito-Bold.ttf`. On Ubuntu/Debian, install the fonts and create the expected paths:

```bash
sudo apt-get install fonts-crosextra-carlito fonts-dejavu-core fonts-liberation
sudo mkdir -p /usr/share/fonts/google-carlito-fonts
sudo ln -sfn /usr/share/fonts/truetype/crosextra/Carlito-Regular.ttf /usr/share/fonts/google-carlito-fonts/Carlito-Regular.ttf
sudo ln -sfn /usr/share/fonts/truetype/crosextra/Carlito-Bold.ttf /usr/share/fonts/google-carlito-fonts/Carlito-Bold.ttf
```

### 2. Install dependencies and configure the environment

From the repository root:

```bash
php -v
composer install
composer check-platform-reqs
npm ci
# Create the environment file only when it does not already exist.
[ -f .env ] || cp .env.example .env
```

For an existing installation, retain your `.env` and application key rather than replacing them.

Start MySQL and connect as a database administrator (`mysql -u root -p`). For a new local installation, create a dedicated database and user; replace the example password before running:

```sql
CREATE DATABASE mapr_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mapr'@'localhost' IDENTIFIED BY 'replace-with-a-strong-local-password';
GRANT ALL PRIVILEGES ON mapr_db.* TO 'mapr'@'localhost';
```

Migrations install triggers. If binary logging is enabled and MySQL rejects trigger creation, ask the database administrator to configure `log_bin_trust_function_creators=1` for this server. Do not grant the application global administrator privileges.

Configure `.env` with the same database credentials:

```dotenv
APP_NAME=MapR
APP_ENV=local
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mapr_db
DB_USERNAME=mapr
DB_PASSWORD="replace-with-a-strong-local-password"

MAPBOX_ACCESS_TOKEN=your_mapbox_access_token
```

`.env.example` already selects MySQL. Keep `.env` private. For an existing installation, retain its database credentials and `APP_KEY`. After editing settings, run `php artisan config:clear`.

For a new installation, generate the application key:

```bash
php artisan key:generate
```

### 3. Create the database tables and seed reference data

```bash
php artisan migrate
php artisan db:seed
```

Seeding loads the configured barangays, roles, disaster types, damage classes, and vulnerability categories. In `local` and `testing`, it also creates one account for each role.

### 4. Supply geospatial data

**Geospatial source data is not bundled with this checkout.** The `geospatial_data/` directory is ignored by Git. Obtain the project datasets separately and preserve these paths, including the corresponding `.shp` and `.dbf` files and any supplied companion files:

```text
geospatial_data/
├── CDRA Shapefiles/
│   ├── Boundary/
│   │   └── Tandag_brgy_Bndy_utm.{shp,dbf,...}
│   └── HOUSEHOLD 2022/
│       └── CBMS2022HOUSEHOLD.{shp,dbf,...}
└── Flood/
    └── flood.{shp,dbf,...}
```

Household data can alternatively be provided as `geospatial_data/CBMS22/CBMS22.{shp,dbf,...}`. Boundary and household data are required for the import; flood data supplies the flood overlay.

Once the files are available:

```bash
php artisan mapr:import-geospatial
```

Building locations become eligible for map display after location verification. Registry data is optional for local sign-in.

### 5. Start development servers

```bash
composer run dev
```

This starts Laravel's server, the database queue listener, log streaming, and Vite. Open `http://localhost:8000/login`. Stop any previous application and Vite processes using ports 8000 and 5173 before starting.

For compiled frontend assets, run:

```bash
npm run build
```

## Local role accounts

On a fresh database, all eight accounts use `password`. The seeder creates accounts only in `local` and `testing` and preserves existing accounts on reruns.

| Display name | Email |
| --- | --- |
| Resident | `resident@mapr.test` |
| BDRRM Personnel | `bdrrm@mapr.test` |
| RDANA Assessor | `rdana@mapr.test` |
| CDRRMO Administrator | `cdrrmo.admin@mapr.test` |
| Barangay Secretary | `barangay.secretary@mapr.test` |
| Punong Barangay | `barangay.captain@mapr.test` |
| RDANA Administrative Officer III | `rdana.admin.officer@mapr.test` |
| City Assessor | `rdana.city.assessor@mapr.test` |

Assign barangays through the CDRRMO Users workspace to grant access to scoped records. Set `MAPR_CONSOLIDATED_NOTED_BY_EMAIL` and `MAPR_CONSOLIDATED_APPROVED_BY_EMAIL` to the active CDRRMO accounts authorized for those signature slots.

## Optional training data

The default seeder creates reference data and local role accounts. On local or testing MySQL, load a full training dataset after migrating.

```bash
php artisan db:seed --class=DemoDataSeeder
```

This seeder fills all four barangays and links registry data, report stages, photos, PDFs, simulated signatures, assessment and impact records, and audit history. It also creates expired session/cache examples and inert examples on a dedicated queue. Migrations write their own ledger.

The data is synthetic and labeled for training. The seeder preserves existing report records and skips after a successful run. The default resident login shows the full report lifecycle for its barangay. Other training residents use `resident.<barangay-code>@mapr.test` with `password`.

The bundled photos are illustrative public-domain FEMA images sourced from Wikimedia Commons. [Flood damage by Bob McMillan](https://commons.wikimedia.org/wiki/File:FEMA_-_31130_-_Flood_Damaged_home_in_Texas.jpg) and [storm damage by Jocelyn Augustino](https://commons.wikimedia.org/wiki/File:FEMA_-_38660_-_Damaged_house_on_Galveston_Island.jpg).

To run the training seeder integration tests, create and migrate a disposable MySQL database named `mapr_demo_seed_test`, then run the test against that database.

```bash
DB_DATABASE=mapr_demo_seed_test vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Integration/DemoDataSeederTest.php
```

## Verify the setup

After signing in as a CDRRMO administrator:

- Open `/cdrrmo/map` and check for household markers after the geospatial import and location verification.
- Check `/cdrrmo/api/map/meta` for `eligible_marker_count > 0`.
- Open `/cdrrmo/residents` for imported active residents.
- Open `/cdrrmo/reports/field` and `/cdrrmo/reports/barangay` for reports once users submit them.

Run automated checks:

```bash
composer validate --strict
composer check-platform-reqs
composer test
npm run test:map
npm run test:pwa
npm run build
python3 -B -m unittest discover -s tests/ci -v
```

PHPUnit is configured to use in-memory SQLite and test-specific settings. Passing these tests does not independently verify MySQL spatial migrations or the full geospatial import.

## Install MapR

Serve the production build from the domain root over HTTPS. For local testing, use a production build on localhost. Open MapR and choose **Install** in Chrome or **Add to Home Screen** from the Share menu in iOS Safari. MapR opens the sign-in page and sends signed-in users to their workspace.

After the service worker activates, a failed navigation shows an offline page. Select **Try again** after reconnecting. Sign-in, maps, reports, and submissions need a connection.

When changing a file listed in the service worker’s cache, increment the cache version in `public/sw.js`. An updated worker activates after the old MapR tabs and app windows close.

## Troubleshooting

| Symptom | Check or action |
| --- | --- |
| Composer reports a PHP version or extension mismatch | Check `php -v`, `php --ini`, and `composer check-platform-reqs`; select PHP 8.5.12+ and enable missing extensions for CLI and PHP-FPM |
| MySQL connection refused or access denied | Start MySQL and check `.env` host, port, database, user, and password; run `php artisan config:clear` |
| MySQL rejects trigger creation with binary logging enabled | Ask the database administrator to configure `log_bin_trust_function_creators=1` |
| Login page errors after first startup | Set `APP_KEY`, run migrations and seeding, and inspect `storage/logs/laravel.log`; ensure `storage/` and `bootstrap/cache/` are writable |
| Migration fails with SQLite or geometry SQL errors | Configure MySQL in `.env`; run `php artisan config:clear` after changing cached settings |
| Geospatial import cannot find shapefiles | Supply the datasets at the exact paths above; they are not downloaded by setup |
| Empty resident registry | Complete `php artisan mapr:import-geospatial` with household data present |
| Buildings exist but map markers are missing | Check building location verification |
| Map tiles do not load | Check `MAPBOX_ACCESS_TOKEN` and its permissions; tiles and API markers load separately |
| Frontend assets or Vite are missing | Run `npm ci`, then `composer run dev` or `npm run build` |
| Imported DOCX preview unavailable | Install LibreOffice at a supported path, or download the original document |

Use `php artisan migrate` for normal setup. `migrate:fresh` deletes existing tables and data.

## Production setup

Use a separate host database and a private `.env`. Install the same PHP/extensions and MySQL prerequisites as local development. Configure a web server with HTTPS and PHP-FPM, using `public/` as its document root and forwarding application requests to `public/index.php`. Give the web and queue users write access to `storage/` and `bootstrap/cache/`; keep `.env` outside the document root.

1. Install locked dependencies and compile assets:

   ```bash
   composer install --no-dev --optimize-autoloader --no-interaction
   composer check-platform-reqs --no-dev
   npm ci
   npm run build
   ```

2. Create `.env` from `.env.example` only for a new installation. Set `APP_ENV=production`, `APP_DEBUG=false`, your HTTPS `APP_URL`, `SESSION_SECURE_COOKIE=true`, and production MySQL credentials. Generate `APP_KEY` once with `php artisan key:generate`; retain it on updates. Behind a reverse proxy, set `TRUSTED_PROXIES` to its verified IP addresses/CIDRs and forward HTTPS headers.
3. Back up an existing database and `storage/app/` before upgrades. Run:

   ```bash
   php artisan migrate --force
   php artisan db:seed --force
   ```

   The production seeder creates reference records only. On a new installation, run `php artisan mapr:provision-admin` and enter the administrator password at its masked prompt.
4. Run `php artisan queue:work --tries=1 --timeout=60` under a process manager such as systemd or Supervisor. Keep the worker running and restart it after releases with `php artisan queue:restart`. Keep the worker timeout below the database queue's `retry_after` (90 seconds by default). Use the PHP-FPM web server for production; `composer run dev` starts development tools.
5. Cache configuration with `php artisan config:cache` after settings are final. Verify `/up`, HTTPS login, role access, compiled assets, queued work, report downloads, and DOCX previews if enabled.

CI verifies the native application build; deployment remains manual. If migrating an existing installation, transfer its database, `storage/app/`, and original `APP_KEY` before serving traffic. Existing data is not imported automatically.

## Repository guide

| Location | Contents |
| --- | --- |
| `routes/` | Public, authentication, and role-specific routes |
| `app/Http/Controllers/` | Workspace endpoints and shared controllers |
| `app/Services/` | Report workflows, spatial estimates, registry operations, and document handling |
| `app/Jobs/` | Queued photo classification placeholder |
| `resources/js/` | Vue workspaces and shared map/report components |
| `database/migrations/` | Application schema and database guards |
| `database/seeders/` | Reference data and local role accounts |
| `tests/` | Feature and unit tests |

Further documentation:

- [CDRRMO local setup](docs/CDRRMO_LOCAL_SETUP.md) — supplemental workspace setup notes; use this README for the corrected dependency and dataset prerequisites.
- [CBMS household field mapping](docs/CBMS22_HOUSEHOLD_FIELD_MAPPING.md)
- [CDRRMO UI and location verification plan](docs/CDRRMO_UI_AND_LOCATION_VERIFICATION_PLAN.md)
- [Implementation plan](docs/MAPR_IMPLEMENTATION_PLAN.md) — historical snapshot from October 2026; some implementation status descriptions are outdated.
- [Building assessment criteria](Assessor_Building_Assessment_Criteria.md)
