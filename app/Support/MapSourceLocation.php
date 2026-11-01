<?php

namespace App\Support;

final class MapSourceLocation
{
    public static function isValidCoordinate(?float $lng, ?float $lat): bool
    {
        if ($lng === null || $lat === null || ! is_finite($lng) || ! is_finite($lat)) {
            return false;
        }

        if ($lng === 0.0 && $lat === 0.0) {
            return false;
        }

        return $lng >= 115 && $lng <= 130 && $lat >= 4 && $lat <= 15;
    }

    public static function buildingSqlConstraint(string $alias = 'buildings'): string
    {
        return "{$alias}.geographic_point IS NOT NULL"
            ." AND ST_Longitude({$alias}.geographic_point) BETWEEN 115 AND 130"
            ." AND ST_Latitude({$alias}.geographic_point) BETWEEN 4 AND 15"
            ." AND NOT (ST_Longitude({$alias}.geographic_point) = 0 AND ST_Latitude({$alias}.geographic_point) = 0)";
    }

    public static function unavailableReason(bool $hasBuilding, ?float $lng, ?float $lat, bool $inStudyArea = true): ?string
    {
        if (! $hasBuilding) {
            return 'no_building';
        }

        if ($lng === null || $lat === null) {
            return 'missing_coordinates';
        }

        if (! self::isValidCoordinate($lng, $lat)) {
            return 'invalid_geometry';
        }

        if (! $inStudyArea) {
            return 'outside_scope';
        }

        return null;
    }

    public static function reasonMessage(?string $reason): ?string
    {
        return match ($reason) {
            'no_building' => 'Map unavailable — no linked household or building.',
            'missing_coordinates' => 'Map unavailable — the linked building has no coordinates.',
            'invalid_geometry' => 'Map unavailable — the stored coordinates are not a valid study-area point.',
            'outside_scope' => 'Map unavailable — the location is outside the four authorized barangays.',
            default => null,
        };
    }
}
