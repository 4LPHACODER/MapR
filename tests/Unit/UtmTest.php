<?php

namespace Tests\Unit;

use App\Support\Utm;
use PHPUnit\Framework\TestCase;

class UtmTest extends TestCase
{
    public function test_tandag_utm_converts_near_known_longitude_latitude(): void
    {
        [$lng, $lat] = Utm::zone51nToLonLat(848599.3387477652, 1002387.7877419062);

        $this->assertEqualsWithDelta(126.17, $lng, 0.02);
        $this->assertEqualsWithDelta(9.05, $lat, 0.02);
    }
}
