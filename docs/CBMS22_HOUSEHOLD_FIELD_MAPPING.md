# CBMS22 household shapefile field mapping

Primary source: `geospatial_data/CDRA Shapefiles/HOUSEHOLD 2022/CBMS2022HOUSEHOLD.shp` (fallback: `geospatial_data/CBMS22/CBMS22.shp`).

| Source field | Meaning | MapR usage |
|--------------|---------|------------|
| `ID` | CBMS geotag / structure identifier | `CBMS22-BLDG-{ID}`, `CBMS22-HH-{ID}` |
| `BARANGAY_C` | Barangay source code | Matched via `config/mapr_reference.php` `source_id` |
| `LONGITUDE`, `LATITUDE` | WGS84 coordinates | `buildings.geographic_point` |
| `HOUSENUM` | Address / house number text | `buildings.address_description` |
| `FAMILYSIZE` | Reported household size | `households.source_household_size` |
| `NO_NUCFAM` | Number of nuclear families | `households.source_nuclear_families` |
| `NO_MALES`, `NO_FEMALES` | Sex totals in household | `households.source_no_males`, `source_no_females` |
| `LINENO_01` | Line number in CBMS listing | `households.source_line_number` |
| `A1LNAME01`, `A1FNAME01`, `A1MNAME01` | First listed person | One `residents` row when both names present (person 01—not inferred household head) |
| `A5_SEX01`, `A7_AGE01` | Sex / age of person 01 | `residents.sex`; age not imported unless extended |

Import creates one building and one household per geotag, plus one resident row for each documented member slot (`A1FNAME01`, `A1FNAME02`, and so on) when both first and last names remain after invalid tokens such as `#NAME?` are removed. It does not invent people to match `FAMILYSIZE`. Re-running the import updates existing rows and does not change location verification status.

Registry map markers use valid source coordinates. Official impact estimates still require `location_verification_status = verified`.

## UI display mapping

| UI label | Source |
|----------|--------|
| Household reference | `households.household_reference` |
| Building reference | `buildings.building_reference` |
| Reported household size | `households.source_household_size` (`FAMILYSIZE`) |
| Nuclear family count | `households.source_nuclear_families` (`NO_NUCFAM`) or “Family count not available in source data.” |
| Resident members list | `residents` rows linked to household (import: person 01 only when names present) |
| “CBMS listed member (person 01)” | First member in list — not inferred household head |
| Map marker | Eligible verified building point; one marker per household at building coordinates |

Backfill: `php artisan mapr:backfill-household-cbms-attrs`
