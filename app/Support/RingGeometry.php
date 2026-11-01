<?php

namespace App\Support;

class RingGeometry
{
    /**
     * @param  list<array{0: float, 1: float}>  $ring
     * @return list<array{0: float, 1: float}>
     */
    public static function simplify(array $ring, float $tolerance): array
    {
        if ($tolerance <= 0 || count($ring) < 5) {
            return $ring;
        }

        $closed = self::samePoint($ring[0], $ring[array_key_last($ring)]);
        $open = $closed ? array_slice($ring, 0, -1) : $ring;
        if (count($open) < 4) {
            return $ring;
        }

        $kept = self::douglasPeucker($open, $tolerance);
        if (count($kept) < 3) {
            return $ring;
        }

        $kept[] = $kept[0];

        return $kept;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $ring
     */
    public static function contains(array $ring, float $x, float $y): bool
    {
        $inside = false;
        $count = count($ring);
        if ($count < 4) {
            return false;
        }

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = $ring[$i][0];
            $yi = $ring[$i][1];
            $xj = $ring[$j][0];
            $yj = $ring[$j][1];
            $intersects = (($yi > $y) !== ($yj > $y))
                && ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12) + $xi);
            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * @param  list<list<array{0: float, 1: float}>>  $rings
     * @return list<list<list<array{0: float, 1: float}>>>
     */
    public static function polygonsFromRings(array $rings): array
    {
        $polygons = [];
        $current = null;

        foreach ($rings as $ring) {
            if (count($ring) < 4) {
                continue;
            }

            $outer = self::signedArea($ring) <= 0 || $current === null;
            if ($outer) {
                if ($current !== null) {
                    $polygons[] = $current;
                }
                $current = [$ring];
            } else {
                $current[] = $ring;
            }
        }

        if ($current !== null) {
            $polygons[] = $current;
        }

        return $polygons;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $ring
     */
    public static function signedArea(array $ring): float
    {
        $sum = 0.0;
        $count = count($ring);
        for ($i = 0; $i < $count - 1; $i++) {
            $sum += $ring[$i][0] * $ring[$i + 1][1] - $ring[$i + 1][0] * $ring[$i][1];
        }

        return $sum / 2;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $points
     * @return list<array{0: float, 1: float}>
     */
    private static function douglasPeucker(array $points, float $tolerance): array
    {
        $last = count($points) - 1;
        if ($last < 2) {
            return $points;
        }

        $farthest = 0;
        $distance = 0.0;
        for ($i = 1; $i < $last; $i++) {
            $candidate = self::perpendicularDistance($points[$i], $points[0], $points[$last]);
            if ($candidate > $distance) {
                $distance = $candidate;
                $farthest = $i;
            }
        }

        if ($distance <= $tolerance) {
            return [$points[0], $points[$last]];
        }

        $left = self::douglasPeucker(array_slice($points, 0, $farthest + 1), $tolerance);
        $right = self::douglasPeucker(array_slice($points, $farthest), $tolerance);

        return array_merge(array_slice($left, 0, -1), $right);
    }

    /**
     * @param  array{0: float, 1: float}  $point
     * @param  array{0: float, 1: float}  $start
     * @param  array{0: float, 1: float}  $end
     */
    private static function perpendicularDistance(array $point, array $start, array $end): float
    {
        $dx = $end[0] - $start[0];
        $dy = $end[1] - $start[1];
        $length = hypot($dx, $dy);
        if ($length < 1e-9) {
            return hypot($point[0] - $start[0], $point[1] - $start[1]);
        }

        return abs($dy * $point[0] - $dx * $point[1] + $end[0] * $start[1] - $end[1] * $start[0]) / $length;
    }

    /**
     * @param  array{0: float, 1: float}  $left
     * @param  array{0: float, 1: float}  $right
     */
    private static function samePoint(array $left, array $right): bool
    {
        return abs($left[0] - $right[0]) < 1e-6 && abs($left[1] - $right[1]) < 1e-6;
    }
}
