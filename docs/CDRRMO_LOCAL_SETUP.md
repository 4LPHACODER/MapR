# CDRRMO local setup

Use this path to run the MapR CDRRMO workspace with **Assessment Map markers**, **Residents registry**, and **demo report lists** on your machine.

## Prerequisites

- PHP 8.2+, Composer, Node.js
- MySQL with PostGIS-style geometry support (MapR uses MySQL spatial functions)
- CBMS shapefiles under `geospatial_data/` (included in the repo)
- `.env` configured for your database

## Environment

Copy and edit environment variables:

```bash
cp .env.example .env
```

| Variable | Purpose |
|----------|---------|
| `DB_*` | MySQL connection (`mapr_db` or your database name) |
| `MAPR_DEMO_PASSWORD` | Password for local demo users (required for `db:seed`) |
| `MAPBOX_ACCESS_TOKEN` | Map tiles for Assessment Map (markers are loaded from the API) |

Generate the app key after copying `.env`:

```bash
php artisan key:generate
```

## One-time database setup

```bash
composer install
php artisan migrate
php artisan mapr:bootstrap-local-data
```

Or via Composer:

```bash
composer setup:cdrrmo-local
```

`mapr:bootstrap-local-data`:

1. Seeds reference data if needed (roles, barangays, demo users)
2. Imports boundaries, households, and residents from shapefiles (non-destructive)
3. Marks a sample of buildings as **verified** so map markers appear (local/testing only)
4. Seeds demo barangay and field assessment rows when none exist

**Production:** This command refuses to run when `APP_ENV=production`.

### Options

```bash
php artisan mapr:bootstrap-local-data --skip-import    # Re-verify map sample / reports only
php artisan mapr:bootstrap-local-data --skip-reports   # Skip demo report seeder
```

## Run the app

```bash
composer run dev
```

Sign in with a CDRRMO demo account (see `MaprDemoUserSeeder`), for example `lestertalan@mapr_admin.com` and your `MAPR_DEMO_PASSWORD`.

The Assessor demo account is separate. Set `MAPR_ASSESSOR_DEMO_PASSWORD` in `.env`, then run:

```bash
php artisan db:seed --class=AssessorDemoUserSeeder
```

That creates `mark.merillo@mapr.test` only when the email is not already present. It does not reset an existing password, role, or user id, and it does not assign barangays. Sign-in still uses email and password and opens `/rdana`.

## Verify

- `/cdrrmo/map` — teal household markers; `GET /cdrrmo/api/map/meta` shows `eligible_marker_count > 0`
- `/cdrrmo/residents` — paginated active residents
- `/cdrrmo/reports/field` and `/cdrrmo/reports/barangay` — demo rows after bootstrap

## Map policy

Map markers require **field-verified** building locations. CBMS import stores coordinates as **unverified**. Local bootstrap verifies a small per-barangay sample for QA; production workflows must verify locations through CDRRMO policy.

## Troubleshooting

| Symptom | Action |
|---------|--------|
| Empty residents, message to run bootstrap | Run `php artisan mapr:bootstrap-local-data` |
| Map overlay, buildings exist but zero eligible | Run `php artisan mapr:mark-sample-locations-verified` |
| Import fails on shapefiles | Confirm `geospatial_data/CDRA Shapefiles/HOUSEHOLD 2022/CBMS2022HOUSEHOLD.dbf` or fallback `geospatial_data/CBMS22/CBMS22.dbf` exists |
| Empty report lists | Re-run bootstrap without `--skip-reports`, or seed `MaprDemoReportWorkflowSeeder` in local/testing only |

Do **not** use `migrate:fresh` unless you intentionally wipe data.
