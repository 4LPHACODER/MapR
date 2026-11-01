<?php

namespace Tests\Unit;

use App\Support\RingGeometry;
use PHPUnit\Framework\TestCase;

class RingGeometryTest extends TestCase
{
    public function test_simplification_keeps_an_interior_point_inside_the_ring(): void
    {
        $ring = [[0.0, 0.0], [100.0, 0.0], [100.0, 50.0], [102.0, 51.0], [100.0, 52.0], [100.0, 100.0], [0.0, 100.0], [0.0, 0.0]];

        $simplified = RingGeometry::simplify($ring, 8.0);

        $this->assertTrue(RingGeometry::contains($ring, 50.0, 20.0));
        $this->assertTrue(RingGeometry::contains($simplified, 50.0, 20.0));
        $this->assertLessThan(count($ring), count($simplified));
    }
}
