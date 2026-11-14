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
- Official map eligibility requires field-verified building locations. Imported CBMS coordinates start unverified; local demo bootstrap verifies a sample for testing.
- Resident impact counts are estimates subject to field validation, not confirmed casualty counts.

## Local setup

### 1. Install prerequisites

- PHP 8.2+ and Composer, with the extensions required by Composer and the MySQL PDO driver.
- Node.js 20.19+ within the 20.x series, or Node.js 22.12+; npm is required for frontend dependencies.
- MySQL 8 with spatial support. Migrations use MySQL-specific SQL, geometry columns, and SRID 4326. SQLite is not a substitute for running the application schema.
- A Mapbox access token for map tiles.
- The study area's geospatial source files for the full data import (see below).
- Optional: LibreOffice at `/usr/bin/soffice` or `/usr/bin/libreoffice` for imported DOCX previews. Original documents can still be downloaded without it.

### 2. Install dependencies and configure the environment

From the repository root:

```bash
composer install
npm ci
cp .env.example .env
```

For an existing installation, retain your `.env` and application key rather than replacing them.

Create an empty MySQL database, such as `mapr_db`, and configure `.env`:

```dotenv
APP_NAME=MapR
APP_ENV=local
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mapr_db
DB_USERNAME=your_mysql_user
DB_PASSWORD=your_mysql_password

MAPR_DEMO_PASSWORD=choose-a-local-demo-password
MAPBOX_ACCESS_TOKEN=your_mapbox_access_token
```

`.env.example` defaults to SQLite: change the database connection to MySQL before migrating. `MAPR_DEMO_PASSWORD` is required when seeding local demo accounts. Keep `.env` private.

For a new installation, generate the application key:

```bash
php artisan key:generate
```

### 3. Create the database tables and seed reference data

```bash
php artisan migrate
php artisan db:seed
```

Seeding loads the configured barangays, roles, disaster types, damage classes, and vulnerability categories. In `local` and `testing`, it also creates demo accounts.

### 4. Supply geospatial data and bootstrap the demo

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
php artisan mapr:bootstrap-local-data
```

This command seeds reference data when needed, imports the geospatial registry, verifies a sample of building locations, and seeds demo report workflow rows when no field assessments exist. It refuses to run in production.

The Composer alias runs the same bootstrap command:

```bash
composer setup:cdrrmo-local
```

The alias does not install dependencies or run migrations. Without the source datasets, you can run the application after step 3, but imported registry data and map markers will be unavailable. Use `--skip-import` only when intentionally skipping import, for example to refresh sample verification on an existing registry:

```bash
php artisan mapr:bootstrap-local-data --skip-import
```

### 5. Start development servers

```bash
composer run dev
```

This starts Laravel's server, the database queue listener, log streaming, and Vite. Open `http://localhost:8000/login`.

For compiled frontend assets, run:

```bash
npm run build
```

## Demo accounts

Newly seeded accounts use the value of `MAPR_DEMO_PASSWORD`:

| Role | Email |
| --- | --- |
| CDRRMO administrator | `admin.demo@mapr.test` |
| CDRRMO administrator | `lestertalan@mapr_admin.com` |
| Resident | `resident.demo@mapr.test` |
| BDRRM personnel | `bdrrm.demo@mapr.test` |
| RDANA assessor | `rdana.demo@mapr.test` |

Existing accounts are generally skipped when reseeding; changing the environment variable does not generally reset their passwords.

An additional assessor account is available through a separate seeder. Set `MAPR_ASSESSOR_DEMO_PASSWORD` in `.env`, then run:

```bash
php artisan db:seed --class=AssessorDemoUserSeeder
```

This creates `mark.merillo@mapr.test` if absent. It does not reset an existing account or assign barangays; field access still requires assignments.

## Verify the setup

After signing in as a CDRRMO administrator:

- Open `/cdrrmo/map` and check for household markers after the geospatial bootstrap.
- Check `/cdrrmo/api/map/meta` for `eligible_marker_count > 0`.
- Open `/cdrrmo/residents` for imported active residents.
- Open `/cdrrmo/reports/field` and `/cdrrmo/reports/barangay` for demo reports.

Run automated tests with:

```bash
composer test
```

PHPUnit is configured to use in-memory SQLite and test-specific settings. Passing these tests does not independently verify MySQL spatial migrations or the full geospatial import.

## Troubleshooting

| Symptom | Check or action |
| --- | --- |
| Migration fails with SQLite or geometry SQL errors | Configure MySQL in `.env`; run `php artisan config:clear` after changing cached settings |
| Demo seeding requests a password | Set `MAPR_DEMO_PASSWORD` before running `php artisan db:seed` |
| Bootstrap cannot find shapefiles | Supply the datasets at the exact paths above; they are not downloaded by setup |
| Empty resident registry | Complete `php artisan mapr:bootstrap-local-data` with household data present |
| Buildings exist but map markers are missing | Check location verification; locally, run `php artisan mapr:mark-sample-locations-verified` |
| Map tiles do not load | Check `MAPBOX_ACCESS_TOKEN` and its permissions; tiles and API markers load separately |
| Empty demo report lists | Re-run bootstrap without `--skip-reports` |
| Frontend assets or Vite are missing | Run `npm ci`, then `composer run dev` or `npm run build` |
| Imported DOCX preview unavailable | Install LibreOffice at a supported path, or download the original document |

Use `php artisan migrate` for normal setup. `migrate:fresh` deletes existing tables and data.

## Repository guide

| Location | Contents |
| --- | --- |
| `routes/` | Public, authentication, and role-specific routes |
| `app/Http/Controllers/` | Workspace endpoints and shared controllers |
| `app/Services/` | Report workflows, spatial estimates, registry operations, and document handling |
| `app/Jobs/` | Queued photo classification placeholder |
| `resources/js/` | Vue workspaces and shared map/report components |
| `database/migrations/` | Application schema and database guards |
| `database/seeders/` | Reference data and local demo accounts/reports |
| `tests/` | Feature and unit tests |

Further documentation:

- [CDRRMO local setup](docs/CDRRMO_LOCAL_SETUP.md) — supplemental workspace setup notes; use this README for the corrected dependency and dataset prerequisites.
- [CBMS household field mapping](docs/CBMS22_HOUSEHOLD_FIELD_MAPPING.md)
- [CDRRMO UI and location verification plan](docs/CDRRMO_UI_AND_LOCATION_VERIFICATION_PLAN.md)
- [Implementation plan](docs/MAPR_IMPLEMENTATION_PLAN.md) — historical snapshot from October 2026; some implementation status descriptions are outdated.
- [Building assessment criteria](Assessor_Building_Assessment_Criteria.md)
- [Report signatories](report_signatories.md)
