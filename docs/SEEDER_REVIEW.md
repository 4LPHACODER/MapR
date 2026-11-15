# Seeder and database review

Reviewed on 2026-11-15 against the local Docker MySQL 8.4 database and a separate,
disposable MySQL 8.4 instance.

## Fixes

- Workflow personnel are identified by their explicit demo email addresses,
  including known Lester aliases. Surnames no longer promote or assign unrelated
  users. Existing passwords are preserved, and deleted/inactive accounts are
  left unchanged.
- New workflow personnel use `MAPR_DEMO_PASSWORD`, matching the other default demo
  users. Missing configuration fails before personnel are written. Existing
  accounts retain their current passwords; reseeding is not a password reset.
- Default database seeding and standalone workflow personnel seeding run in
  transactions. A failed account/assignment write cannot leave a partially
  completed seed.
- Role, barangay, disaster type, and vulnerability reference seeders preserve
  existing `created_at` values when rerun.

## Local database update

The earlier `roles_chk_1` failure came from an unapplied role migration, not an
unsupported role in the new schema. Four other pending migrations were applied:

- Building damage cost assessments.
- Cost adjustment fields.
- Report workflow continuity and assignments.
- Nullable official incident references for typed incidents.

All migrations are now applied, and default seeding completes successfully.
Pre/post checks confirmed unchanged existing business-table row counts and user
password hashes. All 102 foreign keys were checked for orphaned rows, with none
found. There are no duplicate active user/barangay assignments.

The initial backup attempt failed because the database container did not expose
`DB_DATABASE` to the command; that shell still proceeded to run migrations. A
successful **post-migration** dump is saved locally as
`backups/seeder-review-20261115-after-migration.sql` with mode `0600`. It is not a
pre-migration rollback snapshot. No database reset was run against the local
application database.

## Verification

- `composer test`: 151 tests, 1,846 assertions passed, including eight new
  regression tests for identity matching, configuration, inactive/deleted users,
  aliases, atomic writes, and reference creation dates.
- Fresh migration and default seed on isolated MySQL: passed.
- Repeat default seed on isolated MySQL: unchanged counts and password hashes;
  no orphaned foreign keys or duplicate active assignments.
- Fresh production migration and seed on isolated MySQL: populated reference
  data, zero demo users, and the role constraint rejects invalid role codes.
- Default seed on the existing local database: passed.

Run migrations before seeding after pulling schema changes:

```bash
docker compose -f compose.yaml -f compose.local.yaml exec app php artisan migrate --seed
```

Do not use `migrate:fresh` or `db:wipe` on an existing application database. The
fresh-database verification above used only a disposable audit instance.
