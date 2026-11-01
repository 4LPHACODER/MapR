<?php

namespace Tests\Unit;

use App\Support\MapLocationEligibility;
use PHPUnit\Framework\TestCase;

class MapLocationEligibilityTest extends TestCase
{
    public function test_unverified_building_with_point_is_not_map_eligible(): void
    {
        $this->assertFalse(MapLocationEligibility::isMapEligible('unverified', true));
    }

    public function test_verified_building_with_point_is_map_eligible(): void
    {
        $this->assertTrue(MapLocationEligibility::isMapEligible('verified', true));
    }

    public function test_rejected_building_is_not_map_eligible(): void
    {
        $this->assertFalse(MapLocationEligibility::isMapEligible('rejected', true));
    }

    public function test_sql_constraint_requires_verified_status(): void
    {
        $this->assertStringContainsString("location_verification_status = 'verified'", MapLocationEligibility::buildingSqlConstraint('b'));
    }
}
