<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class PolygonSpatial
{
    public const MAX_VERTICES = 500;

    public function spatialPredicate(string $spatialRule): string
    {
        return $spatialRule === 'strict_interior' ? 'ST_Within' : 'ST_Intersects';
    }

    public function polygonWkt(array $coordinates): string
    {
        if (count($coordinates) > self::MAX_VERTICES) {
            throw new InvalidArgumentException('The polygon exceeds the maximum number of vertices.');
        }

        if (count($coordinates) < 4) {
            throw new InvalidArgumentException('A polygon needs at least four positions.');
        }

        $pairs = [];

        foreach ($coordinates as $position) {
            if (! isset($position[0], $position[1])) {
                throw new InvalidArgumentException('Each position needs longitude and latitude.');
            }

            $lng = (float) $position[0];
            $lat = (float) $position[1];

            if (! is_finite($lng) || ! is_finite($lat)) {
                throw new InvalidArgumentException('Coordinates must be finite numbers.');
            }

            if ($lng < 115 || $lng > 130 || $lat < 4 || $lat > 15) {
                throw new InvalidArgumentException('The polygon is outside the expected geographic range.');
            }

            $pairs[] = $lng.' '.$lat;
        }

        if ($pairs[0] !== $pairs[array_key_last($pairs)]) {
            $pairs[] = $pairs[0];
        }

        if (count($pairs) < 4) {
            throw new InvalidArgumentException('A polygon needs at least four positions.');
        }

        $wkt = 'POLYGON(('.implode(', ', $pairs).'))';
        $this->assertValidPolygon($wkt);

        return $wkt;
    }

    public function polygonWktFromGeoJson(array $rings): string
    {
        if ($rings === [] || ! is_array($rings[0] ?? null)) {
            throw new InvalidArgumentException('A polygon geometry is required.');
        }

        return $this->polygonWkt($rings[0]);
    }

    public function assertValidPolygon(string $wkt): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        $row = DB::selectOne(
            "SELECT ST_IsValid(ST_GeomFromText(?, 4326, 'axis-order=long-lat')) AS valid",
            [$wkt],
        );

        if (! $row || ! (bool) $row->valid) {
            throw new InvalidArgumentException('The polygon geometry is invalid.');
        }
    }

    public function fingerprintBuilding(int $buildingId): string
    {
        $row = DB::selectOne(
            'SELECT location_verification_status, ST_AsText(geographic_point) AS point_text, updated_at
             FROM buildings WHERE id = ?',
            [$buildingId],
        );

        if ($row === null) {
            throw new InvalidArgumentException('Building not found.');
        }

        return hash('sha256', implode('|', [
            (string) $buildingId,
            (string) $row->location_verification_status,
            (string) $row->point_text,
            (string) $row->updated_at,
        ]));
    }
}
