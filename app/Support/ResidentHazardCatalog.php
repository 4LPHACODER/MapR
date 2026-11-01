<?php

namespace App\Support;

class ResidentHazardCatalog
{
    public const NOTE = 'These layers show mapped hazard areas, not live disaster conditions. Follow current CDRRMO advisories.';

    public const DATE = 'Date not provided';

    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            self::layer('flood', 'Flood', 'FLOOD', 'FloodSusc', [
                'LF' => '#bfdbfe',
                'MF' => '#60a5fa',
                'HF' => '#2563eb',
                'VHF' => '#1e3a8a',
            ], [], 8.0),
            self::layer('storm-surge', 'Storm surge', 'STORMSURGE', 'Suscept', [
                'Low' => '#ccfbf1',
                'Moderate' => '#2dd4bf',
                'High' => '#0f766e',
            ], ['Height' => 'height', 'Distance' => 'distance']),
            self::layer('tsunami', 'Tsunami', 'TSUNAMI', 'Hazcode', [
                'Low' => '#e0e7ff',
                'Moderate' => '#818cf8',
                'High' => '#4f46e5',
                'Very High' => '#312e81',
            ]),
            self::layer('rain-landslide', 'Rain-induced landslide', 'RAIN_INDUCED_LANDSLIDE', 'DESCRIPTIO', [
                'LOW' => '#d9f99d',
                'MODERATE' => '#65a30d',
                'HIGH' => '#3f6212',
            ]),
            self::layer('earthquake-landslide', 'Earthquake-induced landslide', 'EARTHQUAKE_INDUCED_LANDSLIDE', 'DESCRIPTIO', [
                'NONE' => '#e2e8f0',
                'LOW' => '#fde68a',
                'MODERATE' => '#f59e0b',
                'HIGH' => '#b45309',
            ]),
            self::layer('liquefaction', 'Liquefaction', 'LIQUEFACTION', 'CLASS', [
                'LOW' => '#fae8ff',
                'MODERATE' => '#e879f9',
                'HIGH' => '#a21caf',
            ]),
            self::layer('ground-shaking', 'Ground shaking', 'GROUNDSHAKING', 'LAYER', [
                'PEIS VI' => '#f1f5f9',
                'PEIS VII' => '#94a3b8',
                'PEIS VIII' => '#334155',
            ], ['Hazcode' => 'recorded_code']),
            self::layer('typhoon', 'Typhoon', 'TYPHOON', 'Suscept', [
                'High' => '#9a3412',
            ]),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function criticalPoints(): array
    {
        $root = 'geospatial_data/CDRA Shapefiles/Critical Point/';

        return [
            self::point('barangay-hall', 'Barangay hall', $root.'BARANGAY_HALL_HAZ.shp', 'Name', 'Brgy_Name'),
            self::point('evacuation', 'Listed evacuation site', $root.'BRGY_EVACUATION_HAZ.shp', 'evac_name', null, 'This CDRA listing does not confirm that the site is currently active.'),
            self::point('health-station', 'Barangay health station', $root.'BRGY_HEALTH_STATION_HAZ.shp', 'bhc_name', 'Barangay'),
            self::point('government-office', 'Government office', $root.'GOVERNMENT_OFC_HAZ.shp', 'Name_1', 'BRGY'),
            self::point('gym-court', 'Gymnasium or covered court', $root.'GYM_COVERED_COURT_HAZ.shp', 'Name', 'BRGY', null, 'Classifica'),
            self::point('health-facility', 'Health facility', $root.'HEALTH_FAC_HAZ.shp', 'Name', 'BRGY'),
            self::point('school', 'School', $root.'SCHOOLS_HAZ.shp', 'Name', 'Brgy', null, 'Type'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function point(string $id, string $label, string $source, string $nameField, ?string $barangayField, ?string $notice = null, ?string $subtypeField = null): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'source' => $source,
            'name_field' => $nameField,
            'barangay_field' => $barangayField,
            'subtype_field' => $subtypeField,
            'notice' => $notice,
        ];
    }

    /**
     * @param  array<string, string>  $colors
     * @param  array<string, string>  $details
     * @return array<string, mixed>
     */
    private static function layer(string $id, string $name, string $file, string $field, array $colors, array $details = [], float $simplifyMeters = 0): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'file' => $file,
            'category_field' => $field,
            'colors' => $colors,
            'details' => $details,
            'simplify_meters' => $simplifyMeters,
            'source' => 'geospatial_data/CDRA Shapefiles/Hazard/'.$file.'.shp',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $id): ?array
    {
        foreach (self::definitions() as $layer) {
            if ($layer['id'] === $id) {
                return $layer;
            }
        }

        return null;
    }
}
