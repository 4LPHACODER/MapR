<?php

namespace App\Http\Controllers\Cdrrmo;

use App\Http\Controllers\Controller;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BuildingLocationCorrectionController extends Controller
{
    public function apply(Request $request, int $correction, ActivityLogger $logger): JsonResponse
    {
        $row = DB::table('building_location_corrections')->where('id', $correction)->first();

        if ($row === null || $row->status !== 'pending') {
            return response()->json(['message' => 'Correction not found or already processed.'], 422);
        }

        DB::transaction(function () use ($row) {
            $proposed = DB::selectOne('SELECT ST_AsText(proposed_point) AS wkt FROM building_location_corrections WHERE id = ?', [$row->id]);
            DB::update(
                "UPDATE buildings SET geographic_point = ST_GeomFromText(?, 4326, 'axis-order=long-lat'),
                 location_verification_status = 'unverified', location_verified_by = NULL, location_verified_at = NULL, updated_at = ? WHERE id = ?",
                [$proposed->wkt, now(), $row->building_id],
            );

            DB::table('building_location_corrections')->where('id', $row->id)->update([
                'status' => 'applied',
                'applied_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $logger->log($request->user(), 'location_correction_applied', 'Applied location correction #'.$correction, 'building', (int) $row->building_id);

        return response()->json(['ok' => true]);
    }
}
