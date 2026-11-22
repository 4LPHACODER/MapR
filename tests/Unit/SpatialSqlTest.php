<?php

namespace Tests\Unit;

use App\Support\SpatialSql;
use PHPUnit\Framework\TestCase;

class SpatialSqlTest extends TestCase
{
    protected function tearDown(): void
    {
        SpatialSql::resetCapabilityCache();
        parent::tearDown();
    }

    public function test_maria_db_mode_uses_st_x_st_y_and_plain_geom_from_text(): void
    {
        SpatialSql::forceSupportsLongitudeLatitude(false);

        $this->assertSame('ST_X(buildings.geographic_point)', SpatialSql::longitudeExpr('buildings.geographic_point'));
        $this->assertSame('ST_Y(buildings.geographic_point)', SpatialSql::latitudeExpr('buildings.geographic_point'));
        $this->assertSame('ST_GeomFromText(?)', SpatialSql::geomFromTextPlaceholder());
        $this->assertSame('ST_GeomFromText(wkt_expr)', SpatialSql::geomFromTextExpr('wkt_expr'));
        $this->assertStringNotContainsString('axis-order', SpatialSql::geomFromTextPlaceholder());
        $this->assertStringNotContainsString('ST_Longitude', SpatialSql::longitudeExpr('col'));
        $this->assertStringNotContainsString('ST_Latitude', SpatialSql::latitudeExpr('col'));
    }

    public function test_mysql8_mode_uses_st_longitude_st_latitude_and_axis_order(): void
    {
        SpatialSql::forceSupportsLongitudeLatitude(true);

        $this->assertSame('ST_Longitude(col)', SpatialSql::longitudeExpr('col'));
        $this->assertSame('ST_Latitude(col)', SpatialSql::latitudeExpr('col'));
        $this->assertSame("ST_GeomFromText(?, 4326, 'axis-order=long-lat')", SpatialSql::geomFromTextPlaceholder());
        $this->assertSame(
            "ST_GeomFromText(wkt_expr, 4326, 'axis-order=long-lat')",
            SpatialSql::geomFromTextExpr('wkt_expr')
        );
    }
}
