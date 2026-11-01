<?php

namespace App\Services;

use App\Models\Registry\Building;
use App\Models\Registry\BuildingLocationReview;
use App\Models\Auth\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BuildingLocationReviewService
{
    public const MAX_BATCH = 100;

    public function __construct(
        private PolygonSpatial $spatial,
        private ActivityLogger $activityLogger,
    ) {}

    public function applyBulk(
        User $reviewer,
        string $selectionId,
        array $buildingIds,
        string $action,
        string $basisOrReason,
        array $expectedFingerprints,
        string $wkt,
        string $spatialRule,
    ): array {
        $buildingIds = array_values(array_unique(array_map('intval', $buildingIds)));

        if ($buildingIds === []) {
            throw new InvalidArgumentException('Select at least one building.');
        }

        if (count($buildingIds) > self::MAX_BATCH) {
            throw new InvalidArgumentException('Too many buildings selected for one batch.');
        }

        if (! in_array($action, ['verify', 'reject'], true)) {
            throw new InvalidArgumentException('Invalid review action.');
        }

        $basisOrReason = trim($basisOrReason);

        if (strlen($basisOrReason) < 10) {
            throw new InvalidArgumentException('A verification basis or rejection reason of at least 10 characters is required.');
        }

        $predicate = $this->spatial->spatialPredicate($spatialRule);
        $targetStatus = $action === 'verify' ? 'verified' : 'rejected';

        return DB::transaction(function () use (
            $reviewer, $selectionId, $buildingIds, $basisOrReason, $expectedFingerprints, $wkt, $predicate, $targetStatus,
        ) {
            $changed = 0;
            $unchanged = 0;
            $results = [];
            $errors = [];

            foreach ($buildingIds as $buildingId) {
                try {
                    $result = $this->applyOne(
                        $reviewer,
                        $selectionId,
                        $buildingId,
                        $targetStatus,
                        $basisOrReason,
                        $expectedFingerprints[$buildingId] ?? null,
                        $wkt,
                        $predicate,
                    );
                    $results[] = $result;
                    $result['changed'] ? $changed++ : $unchanged++;
                } catch (InvalidArgumentException $exception) {
                    $errors[$buildingId] = $exception->getMessage();
                }
            }

            if ($errors !== []) {
                throw new InvalidArgumentException('One or more buildings could not be reviewed: '.json_encode($errors));
            }

            return [
                'changed' => $changed,
                'unchanged' => $unchanged,
                'results' => array_map(fn ($row) => ['id' => $row['id'], 'status' => $row['status']], $results),
            ];
        });
    }

    private function applyOne(
        User $reviewer,
        string $selectionId,
        int $buildingId,
        string $targetStatus,
        string $basisOrReason,
        ?string $expectedFingerprint,
        string $wkt,
        string $predicate,
    ): array {
        $building = Building::query()->lockForUpdate()->find($buildingId);

        if ($building === null) {
            throw new InvalidArgumentException("Building {$buildingId} was not found.");
        }

        $currentFingerprint = $this->spatial->fingerprintBuilding($buildingId);

        if ($expectedFingerprint !== null && ! hash_equals($expectedFingerprint, $currentFingerprint)) {
            throw new InvalidArgumentException("Building {$buildingId} changed since preview.");
        }

        $hasPoint = (bool) DB::selectOne(
            'SELECT geographic_point IS NOT NULL AS has_point FROM buildings WHERE id = ?',
            [$buildingId],
        )?->has_point;

        if (! $hasPoint) {
            throw new InvalidArgumentException("Building {$buildingId} has no geographic point.");
        }

        $inSelection = (bool) DB::selectOne(
            "SELECT 1 AS ok FROM buildings WHERE id = ? AND geographic_point IS NOT NULL
             AND {$predicate}(geographic_point, ST_GeomFromText(?, 4326, 'axis-order=long-lat')) LIMIT 1",
            [$buildingId, $wkt],
        )?->ok;

        if (! $inSelection) {
            throw new InvalidArgumentException("Building {$buildingId} is outside the confirmed selection.");
        }

        $previous = $building->location_verification_status;

        if ($previous === $targetStatus) {
            return ['id' => $buildingId, 'status' => $targetStatus, 'changed' => false];
        }

        if ($targetStatus === 'verified') {
            if (! is_string($building->location_source) || trim($building->location_source) === '') {
                throw new InvalidArgumentException("Building {$buildingId} is missing location source metadata.");
            }

            $building->forceFill([
                'location_verification_status' => 'verified',
                'location_verified_by' => $reviewer->id,
                'location_verified_at' => now(),
            ])->save();
        } else {
            $building->forceFill([
                'location_verification_status' => 'rejected',
                'location_verified_by' => null,
                'location_verified_at' => null,
            ])->save();
        }

        BuildingLocationReview::query()->create([
            'building_id' => $buildingId,
            'previous_status' => $previous,
            'new_status' => $targetStatus,
            'basis_or_reason' => $basisOrReason,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'selection_id' => $selectionId,
        ]);

        $this->activityLogger->log(
            $reviewer,
            'building_location_'.$targetStatus,
            'Building #'.$buildingId.' location marked '.$targetStatus,
            'building',
            $buildingId,
        );

        return ['id' => $buildingId, 'status' => $targetStatus, 'changed' => true];
    }
}
