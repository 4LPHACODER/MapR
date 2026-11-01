<?php

namespace App\Services;

use App\Support\ResidentHazardCatalog;
use App\Support\RingGeometry;
use App\Support\ShapefileReader;
use App\Support\Utm;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ResidentHazardExporter
{
    public function export(): array
    {
        $directory = Storage::disk('local')->path('resident-hazards');
        File::ensureDirectoryExists($directory);

        $layers = [];
        foreach (ResidentHazardCatalog::definitions() as $definition) {
            $layers[] = $this->exportLayer($definition, $directory);
        }
        $community = $this->exportCommunity($directory);

        $catalog = [
            'note' => ResidentHazardCatalog::NOTE,
            'initial_bounds' => $this->boundsFor(base_path('geospatial_data/CDRA Shapefiles/Hazard/MULTIHAZARD.shp')),
            'layers' => $layers,
            'critical_points' => $community['critical_points'],
            'areas' => $community['areas'],
            'references' => $community['references'],
        ];
        File::put($directory.'/catalog.json', json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return $catalog;
    }

    /**
     * @return array{critical_points: array<string, mixed>, areas: list<array<string, mixed>>, references: array<string, bool>}
     */
    public function exportCommunity(string $directory): array
    {
        File::ensureDirectoryExists($directory);
        $points = [];
        $categories = [];
        foreach (ResidentHazardCatalog::criticalPoints() as $definition) {
            $exported = $this->criticalFeatures($definition);
            $points = array_merge($points, $exported);
            $categories[] = [
                'id' => $definition['id'],
                'label' => $definition['label'],
                'count' => count($exported),
                'notice' => $definition['notice'],
            ];
        }
        $this->writeCollection($directory.'/critical-points.geojson', $points);

        $areas = $this->exportBoundaries($directory);
        $this->exportRivers($directory);

        return [
            'critical_points' => [
                'source' => 'CDRA Critical Point intersect with MULTIHAZARD',
                'dataset_date' => '2021-07-22',
                'count' => count($points),
                'categories' => $categories,
            ],
            'areas' => $areas,
            'references' => ['boundaries' => true, 'rivers' => true],
        ];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<array<string, mixed>>
     */
    private function criticalFeatures(array $definition): array
    {
        $base = base_path(substr($definition['source'], 0, -4));
        $this->requireShapefile($base, $definition['source']);
        $geographic = ! str_contains((string) file_get_contents($base.'.prj'), 'PROJCS');
        $features = [];
        $index = 0;
        foreach ((new ShapefileReader($base))->records() as $record) {
            $geometry = $record['geometry'] ?? null;
            if (! is_array($geometry) || ($geometry['type'] ?? '') !== 'Point') {
                continue;
            }
            [$x, $y] = $geometry['coordinates'];
            $coordinates = $geographic
                ? [round((float) $x, 6), round((float) $y, 6)]
                : $this->projectPoint((float) $x, (float) $y);
            $name = trim((string) ($record['attrs'][$definition['name_field']] ?? ''));
            $barangayField = $definition['barangay_field'];
            $barangay = $barangayField ? trim((string) ($record['attrs'][$barangayField] ?? '')) : '';
            $subtypeField = $definition['subtype_field'];
            $subtype = $subtypeField ? trim((string) ($record['attrs'][$subtypeField] ?? '')) : '';
            $properties = [
                'name' => $name !== '' ? $name : 'Unnamed facility',
                'category' => $definition['id'],
                'category_label' => $definition['label'],
                'source' => 'CDRA Critical Point intersect with MULTIHAZARD',
                'dataset_date' => '2021-07-22',
            ];
            if ($barangay !== '') {
                $properties['source_barangay'] = $barangay;
            }
            if ($subtype !== '') {
                $properties['subtype'] = $subtype;
            }
            if (is_string($definition['notice']) && $definition['notice'] !== '') {
                $properties['notice'] = $definition['notice'];
            }
            $features[] = [
                'type' => 'Feature',
                'id' => $definition['id'].'-'.$index,
                'properties' => $properties,
                'geometry' => ['type' => 'Point', 'coordinates' => $coordinates],
            ];
            $index++;
        }

        return $features;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exportBoundaries(string $directory): array
    {
        $base = base_path('geospatial_data/CDRA Shapefiles/Boundary/Tandag_brgy_Bndy_utm');
        $this->requireShapefile($base, 'Tandag_brgy_Bndy_utm.shp');
        $features = [];
        $areas = [];
        foreach ((new ShapefileReader($base))->records() as $record) {
            $geometry = $record['geometry'] ?? null;
            if (! is_array($geometry) || ($geometry['type'] ?? '') !== 'Polygon') {
                continue;
            }
            $polygons = $this->projectPolygons($geometry['coordinates'], 0);
            if ($polygons === []) {
                continue;
            }
            $name = trim((string) ($record['attrs']['Brgy_'] ?? ''));
            $psgc = trim((string) ($record['attrs']['PSGC'] ?? ''));
            $feature = [
                'type' => 'Feature',
                'id' => 'barangay-'.$psgc,
                'properties' => ['name' => $name !== '' ? $name : 'Unnamed barangay', 'psgc' => $psgc],
                'geometry' => count($polygons) === 1
                    ? ['type' => 'Polygon', 'coordinates' => $polygons[0]]
                    : ['type' => 'MultiPolygon', 'coordinates' => $polygons],
            ];
            $features[] = $feature;
            $areas[] = ['name' => $feature['properties']['name'], 'psgc' => $psgc, 'bounds' => $this->extendBounds(null, $polygons)];
        }
        $this->writeCollection($directory.'/barangay-boundaries.geojson', $features);
        usort($areas, fn (array $left, array $right) => strcasecmp($left['name'], $right['name']));

        return $areas;
    }

    private function exportRivers(string $directory): void
    {
        $base = base_path('geospatial_data/CDRA Shapefiles/River Creek/River_utm');
        $this->requireShapefile($base, 'River_utm.shp');
        $features = [];
        $index = 0;
        foreach ((new ShapefileReader($base))->records() as $record) {
            $geometry = $record['geometry'] ?? null;
            if (! is_array($geometry) || ($geometry['type'] ?? '') !== 'Polygon') {
                continue;
            }
            $polygons = $this->projectPolygons($geometry['coordinates'], 0);
            if ($polygons === []) {
                continue;
            }
            $name = trim((string) ($record['attrs']['Name'] ?? ''));
            $features[] = [
                'type' => 'Feature',
                'id' => 'river-'.$index,
                'properties' => ['name' => $name !== '' ? $name : 'River'],
                'geometry' => count($polygons) === 1
                    ? ['type' => 'Polygon', 'coordinates' => $polygons[0]]
                    : ['type' => 'MultiPolygon', 'coordinates' => $polygons],
            ];
            $index++;
        }
        $this->writeCollection($directory.'/rivers.geojson', $features);
    }

    private function requireShapefile(string $base, string $label): void
    {
        if (! is_file($base.'.shp') || ! is_file($base.'.dbf') || ! is_file($base.'.prj')) {
            throw new RuntimeException('Shapefile is incomplete: '.$label);
        }
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function projectPoint(float $easting, float $northing): array
    {
        [$lng, $lat] = Utm::zone51nToLonLat($easting, $northing);

        return [round($lng, 6), round($lat, 6)];
    }

    /**
     * @param  list<array<string, mixed>>  $features
     */
    private function writeCollection(string $path, array $features): void
    {
        File::put($path, json_encode([
            'type' => 'FeatureCollection',
            'features' => $features,
        ], JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function exportLayer(array $definition, string $directory): array
    {
        $base = base_path(substr($definition['source'], 0, -4));
        if (! is_file($base.'.shp') || ! is_file($base.'.dbf') || ! is_file($base.'.prj')) {
            throw new RuntimeException('Hazard shapefile is incomplete: '.$definition['source']);
        }

        $output = fopen($directory.'/'.$definition['id'].'.geojson', 'wb');
        if ($output === false) {
            throw new RuntimeException('Unable to write '.$definition['id'].' hazard layer.');
        }

        fwrite($output, '{"type":"FeatureCollection","features":[');
        $counts = [];
        $bounds = null;
        $first = true;
        $index = 0;

        foreach ((new ShapefileReader($base))->records() as $record) {
            $geometry = $record['geometry'] ?? null;
            if (! is_array($geometry) || ($geometry['type'] ?? '') !== 'Polygon') {
                continue;
            }

            $raw = trim((string) ($record['attrs'][$definition['category_field']] ?? ''));
            $category = $raw === '' ? 'Unclassified' : $raw;
            $polygons = $this->projectPolygons($geometry['coordinates'], (float) $definition['simplify_meters']);
            if ($polygons === []) {
                continue;
            }

            $properties = ['category' => $category];
            foreach ($definition['details'] as $sourceField => $outputField) {
                $value = trim((string) ($record['attrs'][$sourceField] ?? ''));
                if ($value !== '') {
                    $properties[$outputField] = $value;
                }
            }

            $feature = [
                'type' => 'Feature',
                'id' => $definition['id'].'-'.$index,
                'properties' => $properties,
                'geometry' => count($polygons) === 1
                    ? ['type' => 'Polygon', 'coordinates' => $polygons[0]]
                    : ['type' => 'MultiPolygon', 'coordinates' => $polygons],
            ];
            fwrite($output, ($first ? '' : ',').json_encode($feature, JSON_UNESCAPED_SLASHES));
            $first = false;
            $index++;
            $counts[$category] = ($counts[$category] ?? 0) + 1;
            $bounds = $this->extendBounds($bounds, $polygons);
        }

        fwrite($output, ']}');
        fclose($output);

        return [
            'id' => $definition['id'],
            'name' => $definition['name'],
            'source' => $definition['source'],
            'date' => ResidentHazardCatalog::DATE,
            'bounds' => $bounds ?? [[0, 0], [0, 0]],
            'legend' => $this->legend($definition, $counts),
        ];
    }

    /**
     * @param  list<list<array{0: float, 1: float}>>  $rings
     * @return list<list<list<array{0: float, 1: float}>>>
     */
    private function projectPolygons(array $rings, float $simplifyMeters): array
    {
        $prepared = [];
        foreach ($rings as $ring) {
            $simplified = RingGeometry::simplify($ring, $simplifyMeters);
            $projected = [];
            foreach ($simplified as $point) {
                [$lng, $lat] = Utm::zone51nToLonLat((float) $point[0], (float) $point[1]);
                $projected[] = [round($lng, 6), round($lat, 6)];
            }
            if (count($projected) >= 4) {
                $prepared[] = $projected;
            }
        }

        return RingGeometry::polygonsFromRings($prepared);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, int>  $counts
     * @return list<array{value: string, label: string, color: string}>
     */
    private function legend(array $definition, array $counts): array
    {
        $legend = [];
        foreach ($definition['colors'] as $value => $color) {
            if (($counts[$value] ?? 0) < 1) {
                continue;
            }
            $legend[] = ['value' => $value, 'label' => $value, 'color' => $color];
        }
        if (($counts['Unclassified'] ?? 0) > 0) {
            $legend[] = ['value' => 'Unclassified', 'label' => 'Unclassified', 'color' => '#94a3b8'];
        }

        return $legend;
    }

    /**
     * @param  array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}}|null  $bounds
     * @param  list<list<list<array{0: float, 1: float}>>>  $polygons
     * @return array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}}
     */
    private function extendBounds(?array $bounds, array $polygons): array
    {
        foreach ($polygons as $polygon) {
            foreach ($polygon[0] as $point) {
                if ($bounds === null) {
                    $bounds = [$point, $point];
                    continue;
                }
                $bounds[0][0] = min($bounds[0][0], $point[0]);
                $bounds[0][1] = min($bounds[0][1], $point[1]);
                $bounds[1][0] = max($bounds[1][0], $point[0]);
                $bounds[1][1] = max($bounds[1][1], $point[1]);
            }
        }

        return $bounds ?? [[0, 0], [0, 0]];
    }

    /**
     * @return array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}}
     */
    private function boundsFor(string $shp): array
    {
        $handle = fopen($shp, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to read hazard extent.');
        }
        $header = fread($handle, 68);
        fclose($handle);
        $values = unpack('dxmin/dymin/dxmax/dymax', substr((string) $header, 36, 32));
        $corners = [
            [(float) $values['xmin'], (float) $values['ymin']],
            [(float) $values['xmin'], (float) $values['ymax']],
            [(float) $values['xmax'], (float) $values['ymin']],
            [(float) $values['xmax'], (float) $values['ymax']],
        ];
        $bounds = null;
        foreach ($corners as [$easting, $northing]) {
            [$lng, $lat] = Utm::zone51nToLonLat($easting, $northing);
            $point = [round($lng, 6), round($lat, 6)];
            if ($bounds === null) {
                $bounds = [$point, $point];
                continue;
            }
            $bounds[0][0] = min($bounds[0][0], $point[0]);
            $bounds[0][1] = min($bounds[0][1], $point[1]);
            $bounds[1][0] = max($bounds[1][0], $point[0]);
            $bounds[1][1] = max($bounds[1][1], $point[1]);
        }

        return $bounds ?? [[0, 0], [0, 0]];
    }
}
