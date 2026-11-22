<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Emits spatial SQL that works on both MySQL 8 and Hostinger MariaDB.
 *
 * Coordinate contract: WKT POINT(lon lat). ST_X = longitude, ST_Y = latitude.
 */
final class SpatialSql
{
    private static ?bool $supportsLongitudeLatitude = null;

    public static function resetCapabilityCache(): void
    {
        self::$supportsLongitudeLatitude = null;
    }

    /** @internal For unit tests. */
    public static function forceSupportsLongitudeLatitude(?bool $value): void
    {
        self::$supportsLongitudeLatitude = $value;
    }

    public static function supportsLongitudeLatitude(): bool
    {
        if (self::$supportsLongitudeLatitude !== null) {
            return self::$supportsLongitudeLatitude;
        }

        if (DB::getDriverName() !== 'mysql') {
            return self::$supportsLongitudeLatitude = false;
        }

        try {
            DB::selectOne("SELECT ST_Longitude(ST_GeomFromText('POINT(1 2)')) AS lng");

            return self::$supportsLongitudeLatitude = true;
        } catch (Throwable) {
            return self::$supportsLongitudeLatitude = false;
        }
    }

    public static function longitudeExpr(string $column): string
    {
        return self::supportsLongitudeLatitude()
            ? "ST_Longitude({$column})"
            : "ST_X({$column})";
    }

    public static function latitudeExpr(string $column): string
    {
        return self::supportsLongitudeLatitude()
            ? "ST_Latitude({$column})"
            : "ST_Y({$column})";
    }

    /**
     * SQL fragment with a single ? placeholder for a WKT string.
     */
    public static function geomFromTextPlaceholder(): string
    {
        return self::supportsLongitudeLatitude()
            ? "ST_GeomFromText(?, 4326, 'axis-order=long-lat')"
            : 'ST_GeomFromText(?)';
    }

    /**
     * SQL fragment that embeds an already-safe SQL expression (not user input)
     * as the WKT argument, e.g. CONCAT('POINT(', ?, ' ', ?, ')').
     */
    public static function geomFromTextExpr(string $wktSqlExpression): string
    {
        return self::supportsLongitudeLatitude()
            ? "ST_GeomFromText({$wktSqlExpression}, 4326, 'axis-order=long-lat')"
            : "ST_GeomFromText({$wktSqlExpression})";
    }
}
