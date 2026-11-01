<?php

namespace App\Support;

class EvacuationSites
{
    public const AVAILABILITY = 'Availability not confirmed.';

    public const MISSING = 'No verified evacuation-site list is available.';

    /**
     * @param  array<string, mixed>  $collection
     * @return array{type: string, features: list<array<string, mixed>>, message?: string}
     */
    public static function onlyListed(array $collection): array
    {
        $features = [];
        foreach ($collection['features'] ?? [] as $feature) {
            if (! is_array($feature) || ($feature['properties']['category'] ?? '') !== 'evacuation') {
                continue;
            }
            $geometry = $feature['geometry'] ?? null;
            if (! is_array($geometry) || ($geometry['type'] ?? '') !== 'Point' || ! isset($geometry['coordinates'][0], $geometry['coordinates'][1])) {
                continue;
            }
            $source = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
            $id = is_string($feature['id'] ?? null) && $feature['id'] !== ''
                ? $feature['id']
                : 'evacuation-'.count($features);
            $properties = [
                'id' => $id,
                'name' => trim((string) ($source['name'] ?? '')) !== '' ? trim((string) $source['name']) : 'Unnamed site',
                'category' => 'evacuation',
                'category_label' => 'Listed evacuation site',
                'source' => (string) ($source['source'] ?? 'CDRA Critical Point intersect with MULTIHAZARD'),
                'dataset_date' => (string) ($source['dataset_date'] ?? '2021-07-22'),
                'availability' => self::AVAILABILITY,
            ];
            $barangay = trim((string) ($source['source_barangay'] ?? ''));
            if ($barangay !== '') {
                $properties['source_barangay'] = $barangay;
            }
            $features[] = [
                'type' => 'Feature',
                'id' => $id,
                'properties' => $properties,
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [(float) $geometry['coordinates'][0], (float) $geometry['coordinates'][1]],
                ],
            ];
        }

        $result = ['type' => 'FeatureCollection', 'features' => $features];
        if ($features === []) {
            $result['message'] = self::MISSING;
        }

        return $result;
    }

    /**
     * @param  array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}}|null  $bounds
     */
    public static function inside(?array $bounds, float $lng, float $lat): bool
    {
        if ($bounds === null) {
            return true;
        }

        return $lng >= (float) $bounds[0][0] && $lng <= (float) $bounds[1][0]
            && $lat >= (float) $bounds[0][1] && $lat <= (float) $bounds[1][1];
    }

    /**
     * @param  list<array<string, mixed>>  $features
     * @param  array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}}|null  $bounds
     * @return list<array<string, mixed>>
     */
    public static function ranked(array $features, float $lng, float $lat, ?array $bounds): array
    {
        $ranked = [];
        foreach ($features as $feature) {
            $coordinates = $feature['geometry']['coordinates'] ?? null;
            if (! is_array($coordinates)) {
                continue;
            }
            $siteLng = (float) $coordinates[0];
            $siteLat = (float) $coordinates[1];
            if (! self::inside($bounds, $siteLng, $siteLat)) {
                continue;
            }
            $feature['straight_line_meters'] = self::meters($lng, $lat, $siteLng, $siteLat);
            $ranked[] = $feature;
        }
        usort($ranked, fn (array $left, array $right) => $left['straight_line_meters'] <=> $right['straight_line_meters']);

        return $ranked;
    }

    /**
     * @param  list<array<string, mixed>>  $features
     * @return array<string, mixed>|null
     */
    public static function matching(array $features, float $lng, float $lat, float $toleranceMeters = 50): ?array
    {
        foreach ($features as $feature) {
            $coordinates = $feature['geometry']['coordinates'] ?? null;
            if (! is_array($coordinates)) {
                continue;
            }
            if (self::meters($lng, $lat, (float) $coordinates[0], (float) $coordinates[1]) <= $toleranceMeters) {
                return $feature;
            }
        }

        return null;
    }

    public static function meters(float $lng1, float $lat1, float $lng2, float $lat2): float
    {
        $earth = 6371000;
        $latDelta = deg2rad($lat2 - $lat1);
        $lngDelta = deg2rad($lng2 - $lng1);
        $a = sin($latDelta / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($lngDelta / 2) ** 2;

        return 2 * $earth * asin(min(1, sqrt($a)));
    }
}
