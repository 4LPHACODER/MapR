# MapR implementation plan

Thesis: “MapR: Evaluating Fine-Tuned EfficientNetB0 with AdamW Optimization for Post-Disaster Building Damage Classification and Geospatial-Based Resident Impact Estimation.”

This plan describes the repository as inspected on 29 October 2026. It does not claim a reviewed data-flow diagram or use-case diagram. None are in the repository. Diagram-level validation is pending.

Status labels used below: **Implemented**, **Partial**, **Missing**, **Awaiting confirmation**.

Out of scope for the product: disaster prediction, rescue deployment, relief allocation, and casualty confirmation.

## 1. Current architecture, versions, and working features

**Partial.** The repository is a Laravel application with a Vue entry prepared, a complete MySQL schema, and almost no application behavior.

| Item | Evidence | Status |
|---|---|---|
| PHP | 8.5.12 installed. `composer.json` requires `^8.2`. | Implemented runtime |
| Laravel | `laravel/framework` v12.70.2 in `composer.lock` | Implemented |
| Vue | 3.5.44, `@vitejs/plugin-vue` 6.x | Installed, not previously mounted |
| Vite | 7.3.7 with `laravel-vite-plugin` | Implemented |
| Tailwind CSS | 4.3.3, `@tailwindcss/vite` | Implemented |
| HTTP client | Axios in `resources/js/bootstrap.js` | Implemented |
| Inertia, Vue Router, Pinia, Breeze, Fortify, Sanctum | Not in `package.json` or `composer.json` | Missing |
| Public route | `routes/web.php` `GET /` | Implemented |
| Health route | `bootstrap/app.php` registers `/up` | Implemented |
| API routes | No `routes/api.php` | Missing |
| Auth routes | No `login` or `register` route. `Route::has('login')` is false. | Missing |
| Controllers, policies, jobs, services | Only `app/Http/Controllers/Controller.php` and `app/Providers/AppServiceProvider.php` | Missing |
| Frontend before this landing page | `resources/js/app.js` loaded Axios only. `resources/js/components/App.vue` was an unmounted counter. `resources/views/welcome.blade.php` was the Laravel starter page. | Replaced for `/` only |
| README | Stock Laravel readme. It does not describe MapR. | Missing project documentation besides this plan |
| Tests | `tests/Feature/ExampleTest.php` expects `GET /` to return 200. `tests/Unit/ExampleTest.php` is the framework example. PHPUnit forces SQLite `:memory:` in `phpunit.xml`. | Partial |
| Queue, cache, session | `.env` uses `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, and `SESSION_DRIVER=database`. `jobs` and `cache` tables are still absent. `sessions` was added in `database/migrations/2026_10_29_000001_create_sessions_table.php` because the web middleware could not render `/` without it. That migration only creates the session table. | Partial |
| External APIs | `.env` defines `OPENWEATHER_API_KEY`, `OPENWEATHER_BASE_URL`, `MAPBOX_ACCESS_TOKEN`, `GEOAPIFY_API_KEY`, and `GEOAPIFY_BASE_URL`. `config/services.php` does not read them. No service class calls them. | Configured in the environment only |
| Database engine | `.env` sets `DB_CONNECTION=mysql` and database name `mapr_db`. Migrations are InnoDB SQL. `.env.example` still documents SQLite and omits the map and weather keys. `php artisan migrate:status` shows the 34 schema migrations have run. Domain row counts were not inspected. | Schema migrated; contents awaiting confirmation |
| Model artifacts | No `.h5`, `.keras`, `.pt`, `.pth`, `.onnx`, or `.pkl` files | Missing |
| AGENTS.md | Not present | Missing |

The landing page added in this session is a public Vue page. It does not read the database and does not call weather or map services.

Working product features today are the public landing page and the schema definition. Authenticated workflows are not implemented.

## 2. Database relationships and model coverage

**Partial.** Thirty-four migrations under `database/migrations/` and the parallel script `database/mapr_db.sql` define the schema. `app/Models/User.php` is the only Eloquent model, and it does not match `users`.

`users` stores `first_name`, `middle_name`, `last_name`, `email`, `password`, `contact_number`, `account_status` (`active` or `inactive`), `role_id`, and nullable `barangay_id`. The model still fills and expects `name`.

Relationships encoded in foreign keys:

- `roles` 1—N `users`. `roles.code` is constrained to `resident`, `bdrrm`, `rdana`, `cdrrmo_admin`.
- `barangays` 1—N `users`, `buildings`, `households`, and reports.
- `user_barangay_assignments` records who assigned a user to a barangay, with `revoked_at`. `active_barangay_id` is a stored generated column, unique with `user_id` while the assignment is active.
- `buildings` belong to one barangay. `geographic_point` is an optional `POINT SRID 4326`. A verified location requires a point, `location_source`, `location_verified_by`, and `location_verified_at`.
- `households` belong to a barangay and optionally to a building in that same barangay.
- `residents` belong to one household.
- `account_resident_links` is one user to one resident, and requires `verified_by`. A resident account is not automatically a registry record.
- `resident_vulnerabilities` is unique per resident and category. Categories overlap by design; unique-person totals must not sum category counts.
- `disaster_types` 1—N `disaster_incidents`. Incidents are `active` or `closed`.
- `disaster_barangays` is the incident-to-barangay scope. Resident reports and barangay reports must reference that pair.
- `resident_incident_reports` belong to the reporting user, an incident, a barangay, and optionally a building in that barangay.
- `incident_photos` attach to exactly one of a resident report, a barangay report, or a field assessment. MIME types are JPEG, PNG, or WebP, from 1 byte through 10 MiB. SHA-256 is required.
- `ai_models` rows must use architecture `EfficientNetB0` and optimizer `AdamW`, with a file reference and SHA-256.
- `damage_classes` are a lookup. No approved rows are stored in the repository.
- `building_damage_predictions` belong to a photo, a model, and the requesting user. Building and predicted class are nullable. Success requires a class and `predicted_at`.
- `barangay_reports` are prepared by one user for one incident barangay. Buildings on the report are `barangay_report_buildings`, optionally linked to a prediction for the same building.
- `field_assessments` belong to one barangay report and one `assigned_assessor_id`.
- `building_verifications` are unique per field assessment and building. They store a verified damage class and may point at a prediction without replacing that prediction row.
- `assessment_areas` store a `MULTIPOLYGON SRID 4326` and `spatial_rule` of `includes_boundary` or `strict_interior`.
- `resident_impact_estimates` snapshot the polygon, rule, criteria, exclusions, and counts. Child tables snapshot buildings, households, residents, and vulnerability membership. `resident_impact_vulnerability_summaries` stores a count per category.
- `consolidated_reports` optionally point at one estimate and link field assessments through `consolidated_report_assessments`.
- `report_signatures` point at exactly one of a barangay report, field assessment, or consolidated report.
- `activity_logs` optionally record `user_id`.

`DatabaseSeeder` calls only `RoleSeeder`. `BarangaySeeder`, `DamageClassSeeder`, `DisasterTypeSeeder`, and `VulnerabilityCategorySeeder` read `config/mapr_reference.php`, which does not exist. Running them would throw.

Eloquent models, factories aligned to this schema, and policies are **Missing**.

## 3. Schema and workflow mismatches

### Multi-signatory reports

**Partial, with a confirmed gap.** `report_signatories.md` is the name-and-title source. It lists four barangay captains, two RDANA signatories (“Verified by” and “Approved by”), and two CDRRMO signatories (“Noted by” and “Approved by”).

`report_signatures` has:

- `signer_user_id` required
- exactly one document foreign key
- `UNIQUE` on `barangay_report_id`, on `field_assessment_id`, and on `consolidated_report_id`
- no signature-role column
- `mechanism` default `authenticated_e_signature`
- `document_hash` as `CHAR(64)`
- append-only triggers that reject update and delete

MySQL allows many NULL values in those unique columns, so different documents can each have a signature. One document cannot have two signatures.

| Document | Names in `report_signatories.md` | Schema capacity | Fit |
|---|---|---|---|
| Barangay report | One captain per barangay | One signature, plus `prepared_by` | One captain signature can fit. A separate preparer signature cannot. |
| Field assessment | Verified by and Approved by | One signature, plus `assigned_assessor_id` | Does not fit both signatories. |
| Consolidated report | Noted by and Approved by | One signature, plus `prepared_by` | Does not fit both signatories. |

A listed signatory is not an application account. Do not create accounts or signature images for those names. `authenticated_e_signature` plus a document hash is not a PKI digital-signature guarantee.

Proposed non-destructive migration, not applied in this session:

1. Confirm whether `report_signatures` already has rows. This session did not connect to MySQL.
2. Add nullable `signature_role VARCHAR(32)`.
3. Drop the three single-column unique indexes.
4. Add unique indexes on `(barangay_report_id, signature_role)`, `(field_assessment_id, signature_role)`, and `(consolidated_report_id, signature_role)`.
5. Check `signature_role` in `signed`, `verified_by`, `approved_by`, and `noted_by`, or NULL for an unclassified legacy row.
6. Do not update existing rows inside normal requests. The append-only triggers block updates. A reviewed migration may drop those triggers, set a role only where the meaning is known, and recreate the triggers. Unknown legacy rows stay NULL.
7. Application rules: barangay reports accept `signed`; field assessments accept `verified_by` and `approved_by`; consolidated reports accept `noted_by` and `approved_by`. New rows require a role and an active signer account.

`prepared_by` remains the drafting user. It is not a signature.

### Other mismatches

- `building_damage_predictions.prediction_status` allows `queued`, but an update trigger rejects every update. A queued row cannot later become `succeeded` or `failed`. Until that trigger is narrowed, insert only a terminal row, or change the trigger so a queued row may move once to `succeeded` or `failed` and no other column may change afterward.
- `building_verifications` may be updated while the field assessment is still `draft` or `review`. That can overwrite the verification. It does not overwrite `building_damage_predictions`, which are append-only. Correction history is still **Missing**.
- Barangay and field status checks allow `draft`, `review`, `signed`, and `submitted`. Their immutability triggers also mention `finalized`, which the check constraint rejects. Consolidated reports allow `finalized` and not `submitted`, while their trigger mentions `submitted`.
- `users.barangay_id` and `user_barangay_assignments` are two sources of barangay scope. **Awaiting confirmation** which source applies to each role. This plan uses `users.barangay_id` for residents and active assignment rows for BDRRM and RDANA.
- Damage labels are **Awaiting confirmation**. `Assessor_Building_Assessment_Criteria.md` classifies construction type and unit cost. It states that it is not a damage-severity standard and must not be mapped to damage classes. No model card or label file is in the repository. Do not invent `no damage`, `minor`, `major`, or `destroyed` as approved classes until a methodology source is added to `config/mapr_reference.php`.

## 4. Role and record-access matrix

**Missing** in code. The matrix below is the authorization target. Navigation hiding is not sufficient. Enforce it with policies on the server.

| Action | Resident | BDRRM | RDANA | CDRRMO administrator |
|---|---|---|---|---|
| Register and edit own profile | Own row only | No public self-registration assumed | No | Creates and manages accounts |
| Submit incident report and photos | Own barangay, own rows | No | No | No |
| Read resident reports | Own rows only | Active assigned barangays | Assigned assessments only | Incident oversight |
| Record buildings and barangay-report photos | No | Assigned barangays | No | No |
| Request AI classification | No | Assigned barangays | No | No |
| Prepare, sign, and submit barangay report | No | Assigned barangay, `prepared_by` | No | Reviews submitted reports |
| Verify building, location, and household link | No | No | `assigned_assessor_id` and assigned barangay | No |
| Correct a field finding | No | No | Same assessor, before sign | No |
| Draw an assessment polygon | No | No | No | Yes |
| Generate an impact estimate | No | No | No | Yes |
| Consolidate, sign, and finalize | No | No | No | Yes |
| Read activity logs | No | No | No | Yes |

Inactive accounts (`account_status = inactive`) authenticate only if a later design explicitly allows it. Default: deny.

Residents do not receive other residents’ photos, registry rows, or estimates. Polygon geometry for public marketing is not part of this matrix; the landing page does not query it.

## 5. Planned pages, routes, controllers, services, jobs, policies, and components

**Missing** except `/` and `/up`.

Keep Laravel as the server and Vue 3, without adding Inertia or a router until a second public page needs it. Authenticated screens can be additional Blade mounts or a later Vue router. Do not introduce that dependency for the landing page.

| Area | Planned files |
|---|---|
| Routes | `routes/web.php`, later `routes/api.php` only for inference or map tokens that must stay off the public page |
| Auth | `app/Http/Controllers/Auth`, `app/Models/User.php`, `database/migrations` for sessions, password resets, jobs, and cache |
| Policies | `app/Policies` for reports, photos, predictions, assessments, areas, estimates, consolidated reports, and activity logs |
| Resident UI | `resources/js/components/resident/` |
| BDRRM UI | `resources/js/components/bdrrm/` |
| RDANA UI | `resources/js/components/rdana/` |
| CDRRMO UI | `resources/js/components/cdrrmo/` |
| Services | `app/Services/DamageInferenceClient.php`, `app/Services/ImpactEstimator.php`, `app/Services/ReportHasher.php`, `app/Services/GeospatialImporter.php`, `app/Services/WeatherContext.php` |
| Jobs | `app/Jobs/ClassifyBuildingPhoto.php` |
| Config | `config/services.php`, `config/mapr_reference.php` after labels are approved |
| Landing | `resources/js/components/landing/`, `resources/views/welcome.blade.php` |

Suggested route names, all authenticated except the landing page:

- `resident.reports.*`
- `bdrrm.reports.*`
- `bdrrm.predictions.store`
- `rdana.assessments.*`
- `cdrrmo.incidents.*`
- `cdrrmo.areas.*`
- `cdrrmo.estimates.*`
- `cdrrmo.reports.*`
- `cdrrmo.activity-logs.index`
- `admin.users.*`

## 6. Resident reporting and private evidence uploads

**Missing** in application code. Schema is **Implemented**.

A resident creates `resident_incident_reports` in `draft`, then `submitted`. BDRRM review moves the row through `under_review`, `reviewed`, or `returned`. The resident reads only rows where `resident_user_id` is their user id.

Photos use the `local` disk, whose root is `storage/app/private`. Store the path, original name, MIME type, size, and SHA-256 on `incident_photos`, with `incident_report_id` set and the other parent ids null. Do not put evidence in `public/` or on the `public` disk. `config/filesystems.php` sets `'serve' => true` on the local disk; before uploads, confirm that serving still requires an authorized controller and does not expose a public URL.

The report’s `barangay_id` must match the resident’s barangay and a `disaster_barangays` row. `reported_point`, when present, is WGS 84 longitude/latitude because the column is SRID 4326.

## 7. BDRRM preparation and submission

**Missing** in application code. Schema is **Implemented**.

BDRRM personnel see resident reports only for barangays with an active `user_barangay_assignments` row. They record `buildings`, attach photos to a `barangay_reports` row, and link buildings through `barangay_report_buildings`.

Report status moves `draft` to `review` to `signed` to `submitted`. After `signed`, triggers allow only the transition toward `submitted` and reject other edits and deletes. Child building rows and photos used by a signed report are immutable.

The single current signature slot can record one authenticated signature, which is the practical place for the barangay captain once that person has an account. `prepared_by` stores the BDRRM drafter. Do not treat the captain’s name in `report_signatories.md` as a user id.

## 8. EfficientNetB0 inference

**Partial** schema, **Missing** service and weights.

`ai_models` can store only EfficientNetB0 with AdamW. Each prediction stores `ai_model_id`, `confidence_score` from 0 to 1, `inference_time_ms`, status, `failure_reason`, and the requesting user. Higgsfield is not this model. It was considered only as a development-time illustration tool and did not generate the landing artwork.

Implementation rules:

- Run inference in a queue job against a private service or local weights identified by `model_file_reference` and `artifact_sha256`.
- Insert a new prediction row for every attempt. Do not update an existing prediction while the append-only trigger remains unconditional.
- On failure, store `prediction_status = failed` and `failure_reason`. Leave `predicted_damage_class_id` null.
- Show the result as preliminary. Personnel still review it. Field verification writes `building_verifications` and does not change the prediction.
- The class list comes from `damage_classes` after an approved label source is added. There is no label mapping to implement until then.
- Do not publish accuracy, confidence thresholds, or deployment claims. `deployment_status` may be `inactive`, `active`, or `retired`, and no row exists yet.

## 9. RDANA field verification and correction history

**Partial** schema, **Missing** application and history table.

An assessor may open a field assessment when `assigned_assessor_id` is their user and they have an active assignment for the parent report’s barangay. They verify building identity, location, visible damage, and household linkage. `location_verified` and `household_link_verified` are explicit booleans.

The verified damage class is authoritative for the field report. The prediction row remains the preliminary record.

Because verification rows can still be updated before signature, add `building_verification_corrections` in a later migration: previous class, new class, note, `corrected_by`, and `corrected_at`, append-only. Write that row in the same transaction as any change to `building_verifications`. Do not add the table until this phase. Signing freezes verifications through the existing triggers.

Field reports need two signature roles after the signature migration: `verified_by` and `approved_by`. Those roles match the headings in `report_signatories.md`. The named officers still need real accounts before either role can be stored.

## 10. Geospatial import, CRS, and polygon rules

**Partial** files on disk, **Missing** importer. The landing page does not render them.

| Location | CRS from `.prj` | Sensitivity |
|---|---|---|
| `geospatial_data/CBMS22` | WGS 84 / UTM zone 51N | Registry-scale. Do not publish. |
| `geospatial_data/CDRA Shapefiles/HOUSEHOLD 2022` | WGS 84 geographic for the sampled household and population layers | Private. Do not publish. |
| `geospatial_data/CDRA Shapefiles/Boundary/Tandag_brgy_Bndy_utm.prj` | WGS 84 / UTM zone 51N | Administrative boundary. Not verified in the database. Not EPSG:4326. |
| `geospatial_data/CDRA Shapefiles/River Creek` | UTM zone 51N | Context |
| `geospatial_data/CDRA Shapefiles/Hazard` and `EXPOSURE_MAP` | Mixed. Sampled exposure layers are UTM zone 51N. `EXPOSURE_MAP/SAMPLE2.prj` is Luzon 1911 Transverse Mercator. | Hazard context, not a resident estimate |
| `geospatial_data/Flood` | `intersection flood` has no `.prj` | Incomplete |

54 shapefile sets have `.shp`, `.shx`, `.dbf`, and `.prj`. These four are incomplete:

- `CDRA Shapefiles/EXPOSURE_MAP/FLOOD/POPULATION_EXPOSURE_TO_FLOOD` lacks `.prj` and `.shx`
- `CDRA Shapefiles/EXPOSURE_MAP/GROUND SHAKING/POPULATION_EXPOSED_TO_GROUND SHAKING` lacks `.dbf`
- `CDRA Shapefiles/EXPOSURE_MAP/LIQUEFACTION/POPULATION_EXPOSED_TO_LIQUEFACTION` lacks `.dbf`
- `Flood/intersection flood` lacks `.prj`

Import rules for a later phase:

- Read each `.prj`. Transform to EPSG:4326 before insert. Do not assume a file is already 4326.
- Store source path, source CRS, transform, importer version, and verification time. `buildings.location_source` and `barangays.boundary_source` exist for that provenance.
- Reject incomplete shapefile sets.
- Keep household points and CBMS attributes on the private disk and in authorized queries.
- `assessment_areas.polygon_geometry` is SRID 4326. Selection is a separate operation from image classification.
- `includes_boundary` uses covers/intersects-boundary semantics. `strict_interior` uses contains and excludes the boundary. Document the exact spatial predicate in the estimator and snapshot it on the estimate.
- A person inside a selected polygon is potentially affected and still needs field validation.

`barangays.boundary_polygon` is nullable. No boundary has been loaded by application code.

## 11. Reproducible impact estimation

**Partial** schema, **Missing** estimator.

`resident_impact_estimates` is append-only and stores `estimation_method` of `area_population` or `verified_damaged_buildings`, plus `criteria`, `polygon_snapshot`, `spatial_rule`, `exclusions`, and `record_snapshot_at`.

Count buildings only when `location_verification_status` is `verified` and the point meets the spatial rule. Count households only when they link to those buildings and the household is active. Count residents only through those households, and only when an authorized `account_resident_links` row is required by the chosen method. The schema allows household linkage without an account link; the estimator must record which link rule it used inside `criteria`.

Vulnerability category counts come from `resident_vulnerabilities` with `verification_status = verified`. `unique_vulnerable_residents` counts distinct residents. Category summaries may overlap and must not be added together to produce a second population total.

`exclusions` records buildings without a verified point, households without a building, residents without a household link, and unverified vulnerability rows. Missing data stays visible on the estimate. Do not fill gaps with invented counts.

Every included building, household, resident, and vulnerability membership is copied to the snapshot tables so a later registry edit does not change a generated estimate.

## 12. Report rendering, signatories, hashes, and integrity

**Partial** schema, **Missing** renderer.

Render barangay reports, field assessments, and consolidated reports from stored rows and snapshots. Show preliminary predictions and verified classes in separate fields.

Hash the canonical report payload with SHA-256 and store it on `report_signatures.document_hash` before the signer confirms. The stored mechanism remains `authenticated_e_signature` unless a later design adds a different implemented mechanism. Do not label this PKI.

After the signature-role migration:

- Barangay report: captain account signs as `signed`, if that account exists.
- Field assessment: `verified_by`, then `approved_by`, matching the RDANA headings.
- Consolidated report: `noted_by`, then `approved_by`, matching the CDRRMO headings.

`signed_at` on the parent row is the time the document becomes immutable. Consolidated `finalized_at` is the later close. Triggers already reject edits after those states, with the status-check mismatches listed in section 3.

Individual signatories stay off the public landing page.

## 13. CDRRMO consolidation and audit logging

**Partial** schema, **Missing** screens.

CDRRMO administrators manage users, roles, assignments, and incidents. They select polygons, generate estimates, attach submitted field assessments, and finalize consolidated reports.

`activity_logs` stores action, module, record type, record id, description, IP address, and user agent. Triggers make the log append-only. Write a row for account changes, assignments, report submission, signature, estimate generation, and finalization. The log viewer is limited to `cdrrmo_admin`.

Do not copy resident names or photo bytes into the log description.

## 14. Authentication, authorization, private storage, and exports

**Missing** except password hashing on the unused `User` model and the private disk root.

- Replace the stock `name` assumption with the real user columns before any registration screen.
- Seed the four roles through the existing `RoleSeeder`.
- Authorize with policies from section 4 on every read and write.
- Keep evidence on the private disk. Exports of reports should be generated for an authorized user and should not include raw CBMS tables.
- A browser may receive a Mapbox token only after it is a public, URL-restricted token served from a deliberate endpoint. Until then, no token is sent to the client. The landing page does not use Mapbox.
- Weather, when added, is contextual. It is not an official warning and not a prediction. OpenWeather and Geoapify keys stay in `config/services.php` on the server.
- `.env.example` should later gain the key names without values. Do not commit `.env`.

## 15. Validation as separate concerns

**Missing** beyond the framework’s example tests.

Keep three suites separate:

1. Model behavior: label set, failure rows, and the rule that a new prediction does not change an older one. This requires weights and approved labels, which are absent.
2. Geospatial functions: CRS transform samples, boundary versus interior predicates, and exclusion counts. Use fixtures, not the private household extract, in automated tests.
3. Application workflows: HTTP tests per role for ownership, assignment, and assessor assignment. These need MySQL or a test database that can execute the spatial SQL. SQLite memory, as configured in `phpunit.xml`, cannot run the current migrations. Do not point feature tests at `mapr_db` with `RefreshDatabase` or `migrate:fresh`.

The current feature test only asserts that `/` returns 200 and does not migrate.

## 16. Deployment requirements

**Awaiting confirmation** for the live server. Local evidence:

- MySQL with InnoDB, `CHECK`, generated columns, triggers, and SRID 4326 spatial types. MariaDB compatibility was not tested.
- PHP 8.5.12 is installed. The project accepts PHP 8.2 and newer.
- `npm run build` produces `public/build` for the Vue page.
- A queue worker is required once inference or report jobs exist. The `jobs` table is not migrated yet. `QUEUE_CONNECTION=database` will fail until that table exists.
- The inference service is separate from the web process and must read model files that are not in this repository.
- Session and cache tables are required before database-backed session login will work.
- Do not describe the current repository as a production deployment.

## 17. Development phases

No calendar dates. A phase is done when its acceptance checks pass. Later phases depend on earlier ones. This session completes only the landing page and these documents.

### Phase 0 — Public landing page

Status: this session.

Files: `resources/views/welcome.blade.php`, `resources/js/app.js`, `resources/js/components/App.vue`, `resources/js/components/landing/`, `resources/css/app.css`, `public/images/mapr-hero-assessment.svg`, `docs/MAPR_IMPLEMENTATION_PLAN.md`, `docs/MAPR_VISUAL_ASSETS.md`.

Acceptance: `/` renders the white-and-blue page, the MapR logo, the four named sections, and no invented statistics. Authentication stays absent, so the primary action is “Explore MapR”.

### Phase 1 — Identity, reference data, and policies

Depends on approved damage labels, vulnerability categories, disaster types, and the four barangay codes.

Files: `app/Models/User.php`, `app/Models/*`, `app/Policies/*`, `config/mapr_reference.php`, `config/services.php`, `database/migrations` for cache, jobs, and password resets, `database/seeders/DatabaseSeeder.php`, `routes/web.php`. The `sessions` table is already added.

Acceptance: a user of each role can sign in; a resident cannot read another resident’s report; inactive users are denied; reference seeders insert only approved rows. Do not run `migrate:fresh`.

### Phase 2 — Resident reports and private photos

Depends on phase 1.

Files: resident controllers, form requests, `app/Policies/ResidentIncidentReportPolicy.php`, resident Vue components.

Acceptance: a resident submits a report and a private image for their barangay and can see only their own status.

### Phase 3 — BDRRM assessment

Depends on phases 1 and 2.

Files: BDRRM controllers and components, building and barangay-report policies.

Acceptance: personnel outside the assignment receive 403. A signed report rejects further building edits.

### Phase 4 — Inference

Depends on phase 3, approved labels, and a model artifact with a recorded SHA-256.

Files: `app/Jobs/ClassifyBuildingPhoto.php`, `app/Services/DamageInferenceClient.php`, `ai_models` seed or admin registration.

Acceptance: a successful call inserts a preliminary prediction; a failure inserts `failed` and no class; the prediction row is unchanged by later verification.

### Phase 5 — RDANA verification and signature roles

Depends on phases 3 and 4, and on the signature-role migration in section 3.

Files: new migration for `signature_role` and `building_verification_corrections`, RDANA controllers and components.

Acceptance: the original prediction remains; a correction inserts history; verified-by and approved-by are separate signature rows.

### Phase 6 — Geospatial import

Depends on phase 1. Can proceed beside phases 2–5.

Files: `app/Services/GeospatialImporter.php`, an artisan command, provenance columns only if the current text fields are not enough.

Acceptance: a UTM zone 51N sample becomes SRID 4326; an incomplete shapefile is rejected; household extracts are not publicly readable.

### Phase 7 — Impact estimates

Depends on phases 5 and 6.

Files: `app/Services/ImpactEstimator.php`, CDRRMO area and estimate controllers.

Acceptance: the same polygon and snapshot time reproduce the same counts; overlapping vulnerability categories do not increase `unique_vulnerable_residents`; missing locations appear in `exclusions`.

### Phase 8 — Rendered reports and finalization

Depends on phases 5 and 7.

Files: report views or a PDF renderer, `app/Services/ReportHasher.php`.

Acceptance: the stored hash matches the canonical payload; a finalized consolidated report cannot be edited; the page does not claim PKI.

### Phase 9 — Audit review and safe export

Depends on phases 1–8.

Files: activity-log writer, CDRRMO log screen, export controller.

Acceptance: sensitive actions append a log row; an export omits raw registry tables; another role receives 403 on the log.

### Phase 10 — Separated test suites and deployment notes

Depends on the phase under test.

Files: `tests/Feature`, `tests/Unit`, a MySQL test database configuration that is not `mapr_db`.

Acceptance: model, spatial, and workflow tests can fail independently. `phpunit.xml` no longer implies that SQLite can migrate this schema.

## Landing page note

The public page is descriptive. It does not show signatory names, counts, model accuracy, weather, or a live map. Sign-in is omitted because no authentication route exists.
