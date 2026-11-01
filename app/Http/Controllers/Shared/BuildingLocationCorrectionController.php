<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;
use App\Models\Registry\Building;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BuildingLocationCorrectionController extends Controller
{
    public function propose(Request $request, Building $building, ActivityLogger $logger): JsonResponse
    {
        $scoped = $request->attributes->get('scoped_barangay_ids');
        if (is_array($scoped) && ! in_array((int) $building->barangay_id, array_map('intval', $scoped), true)) {
            abort(403, 'That record is outside your assignment.');
        }

        $data = $request->validate([
            'longitude' => ['required', 'numeric', 'between:115,130'],
            'latitude' => ['required', 'numeric', 'between:4,15'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'source_reference' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! DB::getSchemaBuilder()->hasTable('building_location_corrections')) {
            return response()->json(['message' => 'Location correction storage is not migrated yet.'], 422);
        }

        $original = DB::selectOne(
            'SELECT ST_Longitude(geographic_point) AS lng, ST_Latitude(geographic_point) AS lat FROM buildings WHERE id = ?',
            [$building->id],
        );

        if ($original === null) {
            return response()->json(['message' => 'This building has no original geographic point.'], 422);
        }

        DB::insert(
            'INSERT INTO building_location_corrections (building_id, proposed_point, original_point, reason, source_reference, status, proposed_by, created_at, updated_at)
             VALUES (?, ST_GeomFromText(CONCAT(\'POINT(\', ?, \' \', ?, \')\'), 4326, \'axis-order=long-lat\'),
                     ST_GeomFromText(CONCAT(\'POINT(\', ?, \' \', ?, \')\'), 4326, \'axis-order=long-lat\'),
                     ?, ?, ?, ?, ?, ?)',
            [
                $building->id,
                $data['longitude'], $data['latitude'],
                $original->lng, $original->lat,
                $data['reason'],
                $data['source_reference'] ?? null,
                'pending',
                $request->user()->id,
                now(), now(),
            ],
        );

        $logger->log($request->user(), 'location_correction_proposed', 'Proposed location correction for building '.$building->building_reference, 'building', $building->id);

        return response()->json(['ok' => true], 201);
    }
}
