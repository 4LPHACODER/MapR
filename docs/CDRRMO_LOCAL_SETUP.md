# CDRRMO local setup

Follow the [local setup in the README](../README.md#local-setup-without-docker) for PHP, MySQL, and environment requirements.

```bash
composer install
php artisan migrate
php artisan db:seed
composer run dev
```

Sign in as `cdrrmo.admin@mapr.test` with `password`. The local seed creates one account per role and the lookup tables the workspaces use.

To populate the registry, provide the [geospatial datasets](../README.md#4-supply-geospatial-data) and run `php artisan mapr:import-geospatial`. The Assessment Map shows building locations once they are verified. Report lists fill as users submit reports.
