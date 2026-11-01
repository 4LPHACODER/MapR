<?php

namespace App\Support;

final class MapLocationEligibility
{
    public const STATUS_VERIFIED = 'verified';

    public static function isMapEligible(?string $locationVerificationStatus, bool $hasGeographicPoint): bool
    {
        return $hasGeographicPoint && $locationVerificationStatus === self::STATUS_VERIFIED;
    }

    public static function buildingSqlConstraint(string $alias = 'buildings'): string
    {
        return "{$alias}.geographic_point IS NOT NULL AND {$alias}.location_verification_status = '".self::STATUS_VERIFIED."'";
    }
}
