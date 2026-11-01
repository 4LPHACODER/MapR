<?php

namespace App\Console\Commands;

use App\Support\ShapefileReader;
use App\Support\SourceName;
use App\Support\Utm;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ImportGeospatialCommand extends Command
{
    protected $signature = 'mapr:import-geospatial';

    protected $description = 'Import four-barangay boundaries, CBMS household points, and flood polygons without truncating existing records.';

    public function handle(): int
    {
        $barangays = collect(config('mapr_reference.barangays', []))->keyBy('source_id');

        if ($barangays->count() !== 4) {
            $this->error('Exactly four approved barangays are required in config/mapr_reference.php');

            return self::FAILURE;
        }

        if (! $this->importBoundaries($barangays)) {
            return self::FAILURE;
        }

        if (! $this->importHouseholds($barangays)) {
            return self::FAILURE;
        }

        $this->importFlood();
        $this->clipFloodToStudyArea();

        $this->info('Geospatial import finished.');

        return self::SUCCESS;
    }

    public static function resolveHouseholdShapefileBase(): ?string
    {
        $preferred = base_path('geospatial_data/CDRA Shapefiles/HOUSEHOLD 2022/CBMS2022HOUSEHOLD');
        $fallback = base_path('geospatial_data/CBMS22/CBMS22');

        if (File::exists($preferred.'.dbf')) {
            return $preferred;
        }

        if (File::exists($fallback.'.dbf')) {
            return $fallback;
        }

        return null;
    }

    private function importBoundaries($barangays): bool
    {
        $base = base_path('geospatial_data/CDRA Shapefiles/Boundary/Tandag_brgy_Bndy_utm');

        if (! File::exists($base.'.dbf')) {
            $this->error('Boundary shapefile not found at geospatial_data/CDRA Shapefiles/Boundary/Tandag_brgy_Bndy_utm.dbf');

            return false;
        }

        $source = 'geospatial_data/CDRA Shapefiles/Boundary/Tandag_brgy_Bndy_utm.shp; EPSG:32651 transformed to EPSG:4326';
        $reader = new ShapefileReader($base);
        $imported = 0;

        foreach ($reader->records() as $record) {
            $attrs = $record['attrs'];
            $psgc = trim((string) ($attrs['PSGC'] ?? ''));
            $match = $barangays->first(fn ($row) => $row['barangay_code'] === $psgc);

            if ($match === null || ($record['geometry']['type'] ?? null) !== 'Polygon') {
                continue;
            }

            $wkt = $this->polygonToWkt($record['geometry']['coordinates'], true);

            if ($wkt === null) {
                continue;
            }

            $existing = DB::table('barangays')->where('barangay_code', $match['barangay_code'])->first();

            if ($existing) {
                DB::update(
                    "UPDATE barangays SET boundary_polygon = ST_GeomFromText(?, 4326, 'axis-order=long-lat'), boundary_source = ?, barangay_name = ?, city = ?, province = ?, region = ?, updated_at = ? WHERE id = ?",
                    [$wkt, $source, $match['barangay_name'], $match['city'], $match['province'], $match['region'], now(), $existing->id]
                );
            } else {
                DB::insert(
                    "INSERT INTO barangays (barangay_code, barangay_name, city, province, region, boundary_polygon, boundary_source, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ST_GeomFromText(?, 4326, 'axis-order=long-lat'), ?, ?, ?)",
                    [$match['barangay_code'], $match['barangay_name'], $match['city'], $match['province'], $match['region'], $wkt, $source, now(), now()]
                );
            }

            $imported++;
        }

        $this->info("Boundaries imported or updated: {$imported}");

        return true;
    }

    private function importHouseholds($barangays): bool
    {
        $base = self::resolveHouseholdShapefileBase();

        if ($base === null) {
            $this->error('Household shapefile not found. Expected one of:');
            $this->line('  - geospatial_data/CDRA Shapefiles/HOUSEHOLD 2022/CBMS2022HOUSEHOLD.dbf');
            $this->line('  - geospatial_data/CBMS22/CBMS22.dbf');

            return false;
        }

        $relative = str_replace(base_path().'/', '', $base.'.shp');
        $source = $relative.'; household lon/lat stored as EPSG:4326; CBMS geotag is not field verification';

        $ids = $barangays->mapWithKeys(fn ($row) => [(string) $row['source_id'] => $row]);
        $barangayRows = DB::table('barangays')->get()->keyBy('barangay_code');
        $reader = new ShapefileReader($base);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $residentsCreated = 0;
        $unresolved = 0;

        foreach ($reader->records() as $record) {
            $attrs = $record['attrs'];
            $sourceId = (string) ($attrs['BARANGAY_C'] ?? '');
            $definition = $ids->get($sourceId);

            if ($definition === null) {
                continue;
            }

            $barangay = $barangayRows->get($definition['barangay_code']);

            if ($barangay === null) {
                continue;
            }

            $id = $attrs['ID'] ?? null;

            if ($id === null || $id === '') {
                continue;
            }

            $buildingRef = 'CBMS22-BLDG-'.$id;
            $householdRef = 'CBMS22-HH-'.$id;

            $lng = (float) ($attrs['LONGITUDE'] ?? 0);
            $lat = (float) ($attrs['LATITUDE'] ?? 0);

            if ($lng === 0.0 || $lat === 0.0) {
                $skipped++;

                continue;
            }

            $exists = DB::table('buildings')->where('building_reference', $buildingRef)->exists();

            DB::transaction(function () use ($buildingRef, $householdRef, $barangay, $lng, $lat, $source, $attrs, $exists, &$created, &$updated, &$residentsCreated, &$unresolved) {
                if (! $exists) {
                    DB::insert(
                        "INSERT INTO buildings (building_reference, address_description, geographic_point, location_source, location_verification_status, barangay_id, created_at, updated_at) VALUES (?, ?, ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 4326, 'axis-order=long-lat'), ?, ?, ?, ?, ?)",
                        [$buildingRef, $attrs['HOUSENUM'] ?? null, $lng, $lat, $source, 'unverified', $barangay->id, now(), now()]
                    );
                } else {
                    DB::update(
                        "UPDATE buildings SET address_description = ?, geographic_point = ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 4326, 'axis-order=long-lat'), barangay_id = ?, updated_at = ? WHERE building_reference = ?",
                        [$attrs['HOUSENUM'] ?? null, $lng, $lat, $barangay->id, now(), $buildingRef]
                    );
                }

                $buildingId = (int) DB::table('buildings')->where('building_reference', $buildingRef)->value('id');
                $householdId = (int) DB::table('households')->where('household_reference', $householdRef)->value('id');

                $householdRow = [
                    'household_status' => 'active',
                    'record_source' => $source,
                    'building_id' => $buildingId,
                    'barangay_id' => $barangay->id,
                    'updated_at' => now(),
                ];

                if (Schema::hasColumn('households', 'source_household_size')) {
                    $householdRow['source_household_size'] = $this->intOrNull($attrs['FAMILYSIZE'] ?? null);
                    $householdRow['source_nuclear_families'] = $this->intOrNull($attrs['NO_NUCFAM'] ?? null);
                    $householdRow['source_no_males'] = $this->intOrNull($attrs['NO_MALES'] ?? null);
                    $householdRow['source_no_females'] = $this->intOrNull($attrs['NO_FEMALES'] ?? null);
                    $householdRow['source_line_number'] = isset($attrs['LINENO_01']) ? trim((string) $attrs['LINENO_01']) : null;
                }

                if ($householdId === 0) {
                    $householdRow['household_reference'] = $householdRef;
                    $householdRow['created_at'] = now();
                    DB::table('households')->insert($householdRow);
                    $householdId = (int) DB::table('households')->where('household_reference', $householdRef)->value('id');
                } else {
                    DB::table('households')->where('id', $householdId)->update($householdRow);
                }

                $unresolved += $this->syncMembers($attrs, $householdId, $source, $residentsCreated);

                if ($exists) {
                    $updated++;
                } else {
                    $created++;
                }
            });
        }

        $this->info("Households created: {$created}; updated: {$updated}; skipped: {$skipped}; residents created: {$residentsCreated}; unresolved names: {$unresolved}");

        return true;
    }

    private function importFlood(): void
    {
        $base = base_path('geospatial_data/Flood/flood');

        if (! File::exists($base.'.shp') || ! File::exists($base.'.prj')) {
            $this->warn('Flood shapefile is incomplete; skipped.');

            return;
        }

        $source = 'geospatial_data/Flood/flood.shp; EPSG:32651 transformed to EPSG:4326; classification from FloodSusc';
        $reader = new ShapefileReader($base);
        $created = 0;
        $skipped = 0;

        foreach ($reader->records() as $record) {
            $objectId = (string) ($record['attrs']['OBJECTID'] ?? '');
            $class = (string) ($record['attrs']['FloodSusc'] ?? '');

            if ($objectId === '' || $class === '' || ($record['geometry']['type'] ?? null) !== 'Polygon') {
                continue;
            }

            if (DB::table('flood_hazard_polygons')->where('source_object_id', $objectId)->exists()) {
                $skipped++;

                continue;
            }

            $wkt = $this->polygonToWkt($record['geometry']['coordinates'], true);

            if ($wkt === null) {
                continue;
            }

            try {
                DB::insert(
                    "INSERT INTO flood_hazard_polygons (source_object_id, classification, geometry, source, created_at, updated_at) VALUES (?, ?, ST_GeomFromText(?, 4326, 'axis-order=long-lat'), ?, ?, ?)",
                    [$objectId, $class, $wkt, $source, now(), now()]
                );
                $created++;
            } catch (Throwable $exception) {
                $this->warn('Skipped flood polygon '.$objectId.': '.$exception->getMessage());
            }
        }

        $this->info("Flood polygons created: {$created}; existing skipped: {$skipped}");
    }

    private function clipFloodToStudyArea(): void
    {
        $deleted = DB::delete(
            'DELETE FROM flood_hazard_polygons
             WHERE id NOT IN (
                SELECT id FROM (
                    SELECT f.id
                    FROM flood_hazard_polygons f
                    JOIN barangays b ON ST_Intersects(f.geometry, b.boundary_polygon)
                ) keepers
             )'
        );

        $this->info("Flood polygons outside the four barangays removed: {$deleted}");
    }

    private function polygonToWkt(array $rings, bool $fromUtm): ?string
    {
        if ($rings === []) {
            return null;
        }

        $wktRings = [];

        foreach ($rings as $ring) {
            if (count($ring) < 4) {
                continue;
            }

            $pairs = [];

            foreach ($ring as $point) {
                [$x, $y] = $point;
                [$lng, $lat] = $fromUtm ? Utm::zone51nToLonLat((float) $x, (float) $y) : [(float) $x, (float) $y];
                $pairs[] = $lng.' '.$lat;
            }

            if ($pairs[0] !== $pairs[array_key_last($pairs)]) {
                $pairs[] = $pairs[0];
            }

            $wktRings[] = '('.implode(', ', $pairs).')';
        }

        if ($wktRings === []) {
            return null;
        }

        return 'MULTIPOLYGON(('.implode(', ', $wktRings).'))';
    }

    private function syncMembers(array $attrs, int $householdId, string $source, int &$residentsCreated): int
    {
        $unresolved = 0;
        $indexes = ['01'];

        foreach (array_keys($attrs) as $name) {
            if (preg_match('/^A1FNAME(\d+)$/', $name, $match)) {
                $indexes[] = $match[1];
            }
        }

        $indexes = array_values(array_unique($indexes));
        sort($indexes);

        foreach ($indexes as $index) {
            $first = SourceName::part($attrs['A1FNAME'.$index] ?? null);
            $middle = SourceName::part($attrs['A1MNAME'.$index] ?? null);
            $last = SourceName::part($attrs['A1LNAME'.$index] ?? null);

            if ($first['value'] === null && $last['value'] === null && ! $first['needs_review'] && ! $last['needs_review']) {
                continue;
            }

            if ($first['value'] === null || $last['value'] === null) {
                $unresolved++;

                continue;
            }

            $reference = 'CBMS22-RES-'.$attrs['ID'].'-'.$index;
            $row = [
                'first_name' => $first['value'],
                'middle_name' => $middle['value'],
                'last_name' => $last['value'],
                'sex' => $this->sexLabel($attrs['A5_SEX'.$index] ?? null),
                'residency_status' => 'active',
                'record_source' => $source,
                'household_id' => $householdId,
                'updated_at' => now(),
            ];

            $existingId = DB::table('residents')->where('registry_reference', $reference)->value('id');

            if ($existingId) {
                DB::table('residents')->where('id', $existingId)->update($row);
            } else {
                $row['registry_reference'] = $reference;
                $row['created_at'] = now();
                DB::table('residents')->insert($row);
                $residentsCreated++;
            }
        }

        return $unresolved;
    }

    private function sexLabel(?string $code): ?string
    {
        return match ($code) {
            '1' => 'male',
            '2' => 'female',
            default => null,
        };
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
