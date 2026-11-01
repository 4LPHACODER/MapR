<?php

namespace App\Support;

class Utm
{
    public static function zone51nToLonLat(float $easting, float $northing): array
    {
        $k0 = 0.9996;
        $a = 6378137.0;
        $e2 = 0.0066943799901413165;
        $ep2 = $e2 / (1 - $e2);
        $x = $easting - 500000.0;
        $y = $northing;
        $m = $y / $k0;
        $mu = $m / ($a * (1 - $e2 / 4 - 3 * $e2 * $e2 / 64 - 5 * $e2 ** 3 / 256));
        $e1 = (1 - sqrt(1 - $e2)) / (1 + sqrt(1 - $e2));
        $phi1 = $mu
            + (3 * $e1 / 2 - 27 * $e1 ** 3 / 32) * sin(2 * $mu)
            + (21 * $e1 ** 2 / 16 - 55 * $e1 ** 4 / 32) * sin(4 * $mu)
            + (151 * $e1 ** 3 / 96) * sin(6 * $mu);
        $n1 = $a / sqrt(1 - $e2 * sin($phi1) ** 2);
        $t1 = tan($phi1) ** 2;
        $c1 = $ep2 * cos($phi1) ** 2;
        $r1 = $a * (1 - $e2) / (1 - $e2 * sin($phi1) ** 2) ** 1.5;
        $d = $x / ($n1 * $k0);
        $lat = $phi1 - ($n1 * tan($phi1) / $r1) * (
            $d ** 2 / 2
            - (5 + 3 * $t1 + 10 * $c1 - 4 * $c1 ** 2 - 9 * $ep2) * $d ** 4 / 24
            + (61 + 90 * $t1 + 298 * $c1 + 45 * $t1 ** 2 - 252 * $ep2 - 3 * $c1 ** 2) * $d ** 6 / 720
        );
        $lon = deg2rad(123) + (
            $d
            - (1 + 2 * $t1 + $c1) * $d ** 3 / 6
            + (5 - 2 * $c1 + 28 * $t1 - 3 * $c1 ** 2 + 8 * $ep2 + 24 * $t1 ** 2) * $d ** 5 / 120
        ) / cos($phi1);

        return [rad2deg($lon), rad2deg($lat)];
    }
}
