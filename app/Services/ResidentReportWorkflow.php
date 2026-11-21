<?php

namespace App\Services;

use App\Http\Controllers\Shared\ReportFlagController;
use App\Models\Auth\User;
use App\Policies\ResidentIncidentReportPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ResidentReportWorkflow
{
    public const MAX_PHOTOS = 8;

    public function __construct(private readonly ActivityLogger $logger) {}

    public function rejectForbiddenFields(Request $request): void
    {
        foreach (['building_id', 'household_id', 'report_status', 'status', 'verified_damage_class_id', 'estimated_repair_cost', 'reviewed_by', 'resident_user_id'] as $key) {
            if ($request->exists($key)) {
                abort(422, 'That field cannot be set on a resident report.');
            }
        }
    }

    public function reportingOpen(User $user): bool
    {
        return $user->barangay_id !== null;
    }

    public function create(User $user, array $data): int
    {
        $this->assertReportingOpen($user);
        $customType = $this->customIncidentType($data);
        $incidentId = ! empty($data['disaster_incident_id'])
            ? $this->assertIncident((int) $user->barangay_id, (int) $data['disaster_incident_id'])
            : null;
        if ($incidentId === null && $customType === null) {
            abort(422, 'Choose an incident or type the incident name.');
        }
        $buildingId = array_key_exists('confirm_affected_building', $data)
            ? $this->resolveConfirmedBuildingId($user, $data, (int) $user->barangay_id)
            : null;
        $now = now();
        $id = DB::table('resident_incident_reports')->insertGetId($this->onlyExisting([
            'report_reference' => $this->reference(),
            'incident_description' => $data['incident_description'] ?? null,
            'reported_location' => $data['reported_location'] ?? null,
            'report_status' => 'draft',
            'resident_user_id' => $user->id,
            'disaster_incident_id' => $incidentId,
            'reported_incident_type' => $incidentId ? null : $customType,
            'barangay_id' => $user->barangay_id,
            'building_id' => $buildingId,
            'observed_at' => $data['observed_at'] ?? null,
            'observed_at_approximate' => ! empty($data['observed_at']) && ! empty($data['observed_at_approximate']),
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        $this->writePoint($id, $data['longitude'] ?? null, $data['latitude'] ?? null);
        $this->logger->log($user, 'resident_report_created', 'Saved resident report draft.', 'resident_incident_report', $id);

        return $id;
    }

    public function update(User $user, object $report, array $data): void
    {
        DB::transaction(function () use ($user, $report, $data) {
            $locked = DB::table('resident_incident_reports')->where('id', $report->id)->lockForUpdate()->first();
            if ($locked === null || ! in_array($locked->report_status, ['draft', 'returned'], true)) {
                abort(response()->json([
                    'message' => 'This report was already submitted.',
                    'reference' => $report->report_reference,
                ], 409));
            }
            if (! empty($data['disaster_incident_id'])) {
                $this->assertIncident((int) $locked->barangay_id, (int) $data['disaster_incident_id']);
            }
            $changes = ['updated_at' => now()];
            foreach (['incident_description', 'reported_location', 'observed_at'] as $column) {
                if (array_key_exists($column, $data)) {
                    $changes[$column] = $data[$column];
                }
            }
            if (array_key_exists('disaster_incident_id', $data)) {
                $changes['disaster_incident_id'] = ! empty($data['disaster_incident_id'])
                    ? $this->assertIncident((int) $locked->barangay_id, (int) $data['disaster_incident_id'])
                    : null;
            }
            if (array_key_exists('reported_incident_type', $data)) {
                $changes['reported_incident_type'] = ! empty($changes['disaster_incident_id'])
                    ? null
                    : $this->customIncidentType($data);
            }
            if (array_key_exists('observed_at_approximate', $data) || array_key_exists('observed_at', $data)) {
                $observed = array_key_exists('observed_at', $data) ? $data['observed_at'] : $this->value($locked, 'observed_at');
                $changes['observed_at_approximate'] = $observed !== null && $observed !== '' && ! empty($data['observed_at_approximate']);
            }
            if (array_key_exists('confirm_affected_building', $data)) {
                $changes['building_id'] = $this->resolveConfirmedBuildingId($user, $data, (int) $locked->barangay_id);
            }
            if (array_key_exists('disaster_incident_id', $data) || array_key_exists('reported_incident_type', $data)) {
                $nextId = $changes['disaster_incident_id'] ?? $locked->disaster_incident_id;
                $nextCustom = array_key_exists('reported_incident_type', $changes)
                    ? $changes['reported_incident_type']
                    : $this->value($locked, 'reported_incident_type');
                if (empty($nextId) && trim((string) $nextCustom) === '') {
                    abort(422, 'Choose an incident or type the incident name.');
                }
            }
            DB::table('resident_incident_reports')->where('id', $locked->id)->update($this->onlyExisting($changes));
            if (array_key_exists('longitude', $data) || array_key_exists('latitude', $data)) {
                $this->writePoint((int) $locked->id, $data['longitude'] ?? null, $data['latitude'] ?? null);
            }
        });
        $this->logger->log($user, 'resident_report_updated', 'Updated resident report '.$report->report_reference.'.', 'resident_incident_report', (int) $report->id);
    }

    public function submit(User $user, object $report): void
    {
        if (! in_array($report->report_status, ['draft', 'returned'], true)) {
            abort(response()->json([
                'message' => 'This report was already submitted.',
                'reference' => $report->report_reference,
            ], 409));
        }
        if (trim((string) $report->incident_description) === '') {
            abort(422, 'Describe what happened before submission.');
        }
        if (trim((string) $report->reported_location) === '') {
            abort(422, 'Describe the location before submission.');
        }
        $point = $this->coordinates($report);
        if ($point['latitude'] === null || $point['longitude'] === null) {
            abort(422, 'Confirm the incident location on the map before submission.');
        }
        if ($this->photoCount((int) $report->id) < 2) {
            abort(422, 'Please attach at least 2 photos before submitting your report.');
        }
        $customType = Schema::hasColumn('resident_incident_reports', 'reported_incident_type')
            ? trim((string) ($report->reported_incident_type ?? ''))
            : '';
        if (! empty($report->disaster_incident_id)) {
            $this->assertIncident((int) $report->barangay_id, (int) $report->disaster_incident_id);
        } elseif ($customType === '') {
            abort(422, 'Choose an incident or type the incident name.');
        }
        if ($this->currentSignatureAssetId($user) === null) {
            abort(422, 'Draw your signature before submitting your report.');
        }

        DB::transaction(function () use ($user, $report) {
            $locked = DB::table('resident_incident_reports')->where('id', $report->id)->lockForUpdate()->first();
            if ($locked === null || ! in_array($locked->report_status, ['draft', 'returned'], true)) {
                abort(response()->json([
                    'message' => 'This report was already submitted.',
                    'reference' => $report->report_reference,
                ], 409));
            }
            $assetId = $this->currentSignatureAssetId($user);
            if ($assetId === null) {
                abort(422, 'Draw your signature before submitting your report.');
            }
            $stamp = now()->format('Y-m-d H:i:s');
            DB::table('resident_incident_reports')->where('id', $locked->id)->update([
                'report_status' => 'submitted',
                'submitted_at' => $stamp,
                'updated_at' => $stamp,
            ]);
            $this->recordResidentSignature($user, $locked, $stamp, $assetId);
        });
        $this->logger->log($user, 'resident_report_submitted', 'Submitted '.$report->report_reference.' to BDRRM.', 'resident_incident_report', (int) $report->id);
    }

    public function deleteDraft(User $user, object $report): void
    {
        $photos = DB::table('incident_photos')->where('incident_report_id', $report->id)->get();
        foreach ($photos as $photo) {
            if (! empty($photo->file_path)) {
                Storage::disk('local')->delete($photo->file_path);
            }
        }
        if ($photos->isNotEmpty()) {
            DB::table('incident_photos')->where('incident_report_id', $report->id)->delete();
        }
        DB::table('resident_incident_reports')->where('id', $report->id)->delete();
        $this->logger->log($user, 'resident_report_deleted', 'Deleted resident report draft '.$report->report_reference.'.', 'resident_incident_report', (int) $report->id);
    }

    public function returnSubmission(User $user, object $report, string $reason): void
    {
        $pending = in_array($report->report_status, ['submitted', 'under_review'], true) && empty($this->value($report, 'barangay_report_id'));
        $user->loadMissing('role');
        $supervisor = in_array($user->role?->code, ['barangay_secretary', 'barangay_captain'], true);
        $awaitingSecretary = false;
        $linked = $this->value($report, 'barangay_report_id');
        if (! $pending && $supervisor && $report->report_status === 'reviewed' && ! empty($linked) && Schema::hasColumn('barangay_reports', 'workflow_stage')) {
            $awaitingSecretary = DB::table('barangay_reports')->where('id', (int) $linked)->value('workflow_stage') === 'pending_secretary';
        }
        if (! $pending && ! $awaitingSecretary) {
            abort(409, 'This submission can no longer be returned.');
        }
        DB::table('resident_incident_reports')->where('id', $report->id)->update($this->onlyExisting([
            'report_status' => 'returned',
            'return_reason' => $reason,
            'returned_at' => now(),
            'updated_at' => now(),
        ]));
        $this->logger->log($user, 'resident_report_returned', 'Returned '.$report->report_reference.' for correction.', 'resident_incident_report', (int) $report->id);
    }

    public function acceptSubmission(User $user, object $report, ReportWorkflow $reports, ?array $endorsement = null): int
    {
        $storedPath = null;
        try {
            return DB::transaction(function () use ($user, $report, $reports, $endorsement, &$storedPath) {
                $locked = DB::table('resident_incident_reports')->where('id', $report->id)->lockForUpdate()->first();
                if ($locked === null) {
                    abort(404);
                }
                abort_unless(app(ResidentIncidentReportPolicy::class)->view($user, $locked), 403);
                $linked = $this->value($locked, 'barangay_report_id');
                if (! empty($linked)) {
                    $existing = DB::table('barangay_reports')->where('id', (int) $linked)->lockForUpdate()->first();
                    if ($existing !== null && in_array($existing->report_status, ['draft', 'returned'], true)) {
                        $this->seedBarangayReportFromResident($existing, $locked);
                        if (Schema::hasColumn('barangay_reports', 'workflow_stage') && empty($existing->workflow_stage)) {
                            DB::table('barangay_reports')->where('id', $existing->id)->update([
                                'workflow_stage' => 'pending_secretary',
                                'updated_at' => now(),
                            ]);
                        }
                    }

                    return (int) $linked;
                }
                if (! in_array($locked->report_status, ['submitted', 'under_review'], true)) {
                    abort(409, 'This submission cannot be accepted.');
                }
                if ($this->openFlagCount((int) $locked->id) > 0) {
                    abort(422, 'Resolve open flags before accepting this report.');
                }
                if (! is_array($endorsement)) {
                    abort(422, 'Draw a signature or choose your saved signature before accepting.');
                }
                if (! hash_equals($this->contentRevision($locked), (string) ($endorsement['revision'] ?? ''))) {
                    abort(409, 'This report changed after you opened it. Refresh and review it again before signing.');
                }
                $assetId = $this->endorsementAsset($user, $endorsement, $storedPath);
                $this->recordBdrrmSignature($user, $locked, $assetId);
                $payload = [
                    'report_description' => $locked->incident_description,
                    'disaster_incident_id' => $locked->disaster_incident_id,
                ];
                $buildingId = $this->value($locked, 'building_id');
                if (! empty($buildingId)) {
                    $payload['building_id'] = (int) $buildingId;
                }
                $barangayReportId = $reports->createBarangayReport($user, $payload, (int) $locked->barangay_id);
                $barangayReport = DB::table('barangay_reports')->where('id', $barangayReportId)->first();
                if ($barangayReport !== null) {
                    $this->cloneResidentPhotosToBarangayReport((int) $locked->id, $barangayReportId, (int) $user->id, $buildingId ? (int) $buildingId : null);
                    if (Schema::hasColumn('barangay_reports', 'workflow_stage')) {
                        DB::table('barangay_reports')->where('id', $barangayReportId)->update([
                            'workflow_stage' => 'pending_secretary',
                            'updated_at' => now(),
                        ]);
                    }
                }
                DB::table('resident_incident_reports')->where('id', $locked->id)->update($this->onlyExisting([
                    'report_status' => 'reviewed',
                    'reviewed_at' => now(),
                    'reviewed_by' => $user->id,
                    'barangay_report_id' => $barangayReportId,
                    'updated_at' => now(),
                ]));
                $this->logger->log($user, 'resident_report_accepted', 'Accepted '.$locked->report_reference.' and handed off the barangay report for assessor review.', 'resident_incident_report', (int) $locked->id);

                return $barangayReportId;
            });
        } catch (\Throwable $exception) {
            if (is_string($storedPath) && $storedPath !== '') {
                Storage::disk('local')->delete($storedPath);
            }
            throw $exception;
        }
    }

    private function seedBarangayReportFromResident(object $barangayReport, object $residentReport): void
    {
        $buildingId = $this->value($residentReport, 'building_id');
        if (! empty($buildingId) && Schema::hasTable('barangay_report_buildings')) {
            $linked = DB::table('barangay_report_buildings')
                ->where('barangay_report_id', $barangayReport->id)
                ->where('building_id', (int) $buildingId)
                ->exists();
            if (! $linked) {
                $now = now();
                DB::table('barangay_report_buildings')->insert([
                    'barangay_report_id' => $barangayReport->id,
                    'building_id' => (int) $buildingId,
                    'observations' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
        $this->cloneResidentPhotosToBarangayReport(
            (int) $residentReport->id,
            (int) $barangayReport->id,
            (int) ($barangayReport->prepared_by ?? 0),
            $buildingId ? (int) $buildingId : null,
        );
        if (trim((string) ($barangayReport->report_description ?? '')) === '' && trim((string) ($residentReport->incident_description ?? '')) !== '') {
            DB::table('barangay_reports')->where('id', $barangayReport->id)->update([
                'report_description' => $residentReport->incident_description,
                'updated_at' => now(),
            ]);
        }
    }

    private function cloneResidentPhotosToBarangayReport(int $residentReportId, int $barangayReportId, int $uploadedBy, ?int $buildingId): void
    {
        if (! Schema::hasTable('incident_photos')
            || ! Schema::hasColumn('incident_photos', 'incident_report_id')
            || ! Schema::hasColumn('incident_photos', 'barangay_report_id')) {
            return;
        }
        if (DB::table('incident_photos')->where('barangay_report_id', $barangayReportId)->exists()) {
            return;
        }
        $photos = DB::table('incident_photos')->where('incident_report_id', $residentReportId)->orderBy('id')->get();
        if ($photos->isEmpty()) {
            return;
        }
        $now = now();
        foreach ($photos as $photo) {
            $payload = [
                'file_path' => $photo->file_path,
                'original_filename' => $photo->original_filename,
                'mime_type' => $photo->mime_type,
                'file_size' => $photo->file_size,
                'file_sha256' => $photo->file_sha256,
                'image_source' => $photo->image_source,
                'captured_at' => $photo->captured_at ?? null,
                'incident_report_id' => null,
                'barangay_report_id' => $barangayReportId,
                'field_assessment_id' => null,
                'building_id' => $buildingId ?? ($photo->building_id ?? null),
                'uploaded_by' => $uploadedBy > 0 ? $uploadedBy : (int) $photo->uploaded_by,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $payload = array_filter(
                $payload,
                fn ($column) => Schema::hasColumn('incident_photos', $column),
                ARRAY_FILTER_USE_KEY,
            );
            DB::table('incident_photos')->insert($payload);
        }
    }

    public function contentRevision(object $row): string
    {
        $point = $this->coordinates($row);
        $photos = [];
        if (Schema::hasTable('incident_photos') && Schema::hasColumn('incident_photos', 'incident_report_id')) {
            $photos = DB::table('incident_photos')
                ->where('incident_report_id', $row->id)
                ->orderBy('id')
                ->get(['id', 'file_sha256'])
                ->map(fn ($photo) => ((int) $photo->id).':'.(string) ($photo->file_sha256 ?? ''))
                ->all();
        }

        return hash('sha256', json_encode([
            'description' => trim((string) $this->value($row, 'incident_description', '')),
            'location' => trim((string) $this->value($row, 'reported_location', '')),
            'latitude' => $point['latitude'],
            'longitude' => $point['longitude'],
            'photos' => $photos,
            'status' => (string) $row->report_status,
        ], JSON_THROW_ON_ERROR));
    }

    public function openFlagCount(int $reportId): int
    {
        if (! Schema::hasTable('report_flags')) {
            return 0;
        }

        return DB::table('report_flags')
            ->where('subject_type', 'resident_incident_report')
            ->where('subject_id', $reportId)
            ->where('status', 'open')
            ->count();
    }

    public function markUnderReview(object $report): void
    {
        if ($report->report_status !== 'submitted') {
            return;
        }
        DB::table('resident_incident_reports')->where('id', $report->id)->update([
            'report_status' => 'under_review',
            'updated_at' => now(),
        ]);
    }

    public function startReview(User $user, object $report): void
    {
        if ($report->report_status !== 'submitted' || ! empty($this->value($report, 'barangay_report_id'))) {
            abort(409, 'This submission is not waiting to start review.');
        }
        DB::table('resident_incident_reports')->where('id', $report->id)->update([
            'report_status' => 'under_review',
            'updated_at' => now(),
        ]);
        $this->logger->log($user, 'resident_report_review_started', 'Started review of '.$report->report_reference.'.', 'resident_incident_report', (int) $report->id);
    }

    public function assignedSubmissionQuery(array $barangayIds)
    {
        return DB::table('resident_incident_reports as reports')
            ->whereIn('reports.barangay_id', $barangayIds === [] ? [0] : $barangayIds)
            ->where('reports.report_status', '!=', 'draft');
    }

    public function pendingSubmissionQuery(array $barangayIds)
    {
        $query = $this->assignedSubmissionQuery($barangayIds)
            ->whereIn('reports.report_status', ['submitted', 'under_review']);
        if (Schema::hasColumn('resident_incident_reports', 'barangay_report_id')) {
            $query->whereNull('reports.barangay_report_id');
        }

        return $query;
    }

    public function pendingCount(array $barangayIds): int
    {
        if ($barangayIds === [] || ! Schema::hasTable('resident_incident_reports')) {
            return 0;
        }

        return $this->pendingSubmissionQuery($barangayIds)->count();
    }

    public function queueQuery(array $barangayIds, string $status, ?string $search, ?string $submittedFrom = null, ?string $submittedTo = null)
    {
        if ($status === 'returned') {
            $query = $this->assignedSubmissionQuery($barangayIds)->where('reports.report_status', 'returned');
        } elseif ($status === 'reviewed') {
            $query = $this->assignedSubmissionQuery($barangayIds)->where('reports.report_status', 'reviewed');
        } else {
            $query = $this->pendingSubmissionQuery($barangayIds);
            if (in_array($status, ['submitted', 'under_review'], true)) {
                $query->where('reports.report_status', $status);
            }
        }
        if (Schema::hasTable('users')) {
            $query->leftJoin('users', 'users.id', '=', 'reports.resident_user_id');
        }
        if (Schema::hasTable('disaster_incidents')) {
            $query->leftJoin('disaster_incidents', 'disaster_incidents.id', '=', 'reports.disaster_incident_id');
        }
        if (Schema::hasTable('barangays')) {
            $query->leftJoin('barangays', 'barangays.id', '=', 'reports.barangay_id');
        }
        $query->select('reports.*');
        if (Schema::hasTable('users')) {
            $userColumns = ['users.first_name', 'users.last_name'];
            if (Schema::hasColumn('users', 'middle_name')) {
                $userColumns[] = 'users.middle_name';
            }
            $query->addSelect($userColumns);
        }
        if (Schema::hasTable('disaster_incidents') && Schema::hasColumn('disaster_incidents', 'incident_name')) {
            $query->addSelect('disaster_incidents.incident_name');
        }
        if (Schema::hasTable('barangays') && Schema::hasColumn('barangays', 'barangay_name')) {
            $query->addSelect('barangays.barangay_name');
        }
        $this->selectPointColumns($query);
        $term = trim((string) $search);
        if ($term !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $query->where(function ($inner) use ($like) {
                $inner->whereRaw('reports.report_reference LIKE ? ESCAPE ?', [$like, '\\']);
                if (Schema::hasTable('users')) {
                    $inner->orWhereRaw('users.first_name LIKE ? ESCAPE ?', [$like, '\\'])
                        ->orWhereRaw('users.last_name LIKE ? ESCAPE ?', [$like, '\\']);
                    if (Schema::hasColumn('users', 'middle_name')) {
                        $inner->orWhereRaw('users.middle_name LIKE ? ESCAPE ?', [$like, '\\']);
                    }
                }
                if (Schema::hasTable('disaster_incidents') && Schema::hasColumn('disaster_incidents', 'incident_name')) {
                    $inner->orWhereRaw('disaster_incidents.incident_name LIKE ? ESCAPE ?', [$like, '\\']);
                }
            });
        }
        if ($submittedFrom) {
            $query->whereDate('reports.submitted_at', '>=', $submittedFrom);
        }
        if ($submittedTo) {
            $query->whereDate('reports.submitted_at', '<=', $submittedTo);
        }

        return $query->orderByDesc('reports.submitted_at')->orderByDesc('reports.id');
    }

    public function queueRow(object $row): array
    {
        $point = $this->pointFromSelection($row);
        $assignment = $this->activeAssignment((int) $row->id, (string) $row->report_status);
        $checker = $this->secretaryFromSignature(
            empty($row->barangay_report_id ?? null) ? null : (int) $row->barangay_report_id
        );
        $name = trim(implode(' ', array_filter([
            $row->first_name ?? null,
            $row->middle_name ?? null,
            $row->last_name ?? null,
        ], fn ($part) => is_string($part) && $part !== '')));

        return [
            'id' => (int) $row->id,
            'reference' => $row->report_reference,
            'resident_name' => $name !== '' ? $name : null,
            'incident_name' => $this->incidentLabel($row),
            'reported_incident_type' => Schema::hasColumn('resident_incident_reports', 'reported_incident_type') ? ($row->reported_incident_type ?? null) : null,
            'barangay_id' => isset($row->barangay_id) ? (int) $row->barangay_id : null,
            'barangay_name' => $row->barangay_name ?? null,
            'reported_location' => $row->reported_location ?? null,
            'submitted_at' => $row->submitted_at ?? null,
            'status' => $row->report_status,
            'label' => $this->residentLabel((string) $row->report_status, $this->downstream($row)),
            'has_point' => $point['longitude'] !== null && $point['latitude'] !== null,
            'longitude' => $point['longitude'],
            'latitude' => $point['latitude'],
            'open_flags' => $this->openFlagCount((int) $row->id),
            'reviewable' => in_array($row->report_status, ['submitted', 'under_review'], true) && empty($row->barangay_report_id ?? null),
            'assignee_name' => $assignment['name'],
            'assigned_at' => $assignment['assigned_at'],
            'assignment_label' => $assignment['label'],
            'review_state' => $assignment['state'],
            'secretary_name' => $checker['name'],
            'secretary_checked_at' => $checker['assigned_at'],
            'barangay_workflow_stage' => $this->barangayWorkflowStage(
                empty($row->barangay_report_id ?? null) ? null : (int) $row->barangay_report_id
            ),
        ];
    }

    public function submissionFeatures(array $barangayIds): array
    {
        $empty = ['type' => 'FeatureCollection', 'features' => []];
        if ($barangayIds === [] || ! Schema::hasTable('resident_incident_reports')) {
            return $empty;
        }
        $query = $this->assignedSubmissionQuery($barangayIds);
        if (Schema::hasTable('disaster_incidents')) {
            $query->leftJoin('disaster_incidents', 'disaster_incidents.id', '=', 'reports.disaster_incident_id');
        }
        if (Schema::hasTable('barangays')) {
            $query->leftJoin('barangays', 'barangays.id', '=', 'reports.barangay_id');
        }
        $query->select('reports.*');
        if (Schema::hasTable('disaster_incidents') && Schema::hasColumn('disaster_incidents', 'incident_name')) {
            $query->addSelect('disaster_incidents.incident_name');
        }
        if (Schema::hasTable('barangays') && Schema::hasColumn('barangays', 'barangay_name')) {
            $query->addSelect('barangays.barangay_name');
        }
        $this->selectPointColumns($query);
        $features = [];
        foreach ($query->orderByDesc('reports.submitted_at')->orderByDesc('reports.id')->get() as $row) {
            $point = $this->pointFromSelection($row);
            if ($point['longitude'] === null || $point['latitude'] === null) {
                continue;
            }
            $features[] = [
                'type' => 'Feature',
                'id' => (int) $row->id,
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$point['longitude'], $point['latitude']],
                ],
                'properties' => [
                    'id' => (int) $row->id,
                    'reference' => $row->report_reference,
                    'status' => $row->report_status,
                    'label' => $this->mapDisplayStatus($row),
                    'incident_name' => $row->incident_name ?? null,
                    'barangay_name' => $row->barangay_name ?? null,
                    'reported_location' => $row->reported_location ?? null,
                    'submitted_at' => $row->submitted_at ?? null,
                ],
            ];
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    public function selectionSummary(object $row): array
    {
        $incident = $this->incident($row);

        return [
            'id' => (int) $row->id,
            'reference' => $row->report_reference,
            'resident_name' => $this->reporterName((int) $row->resident_user_id),
            'incident_name' => $incident['name'],
            'incident_type' => $incident['type'],
            'barangay_name' => $this->barangayName((int) $row->barangay_id),
            'reported_location' => $this->value($row, 'reported_location'),
            'submitted_at' => $this->value($row, 'submitted_at'),
            'status' => $row->report_status,
            'label' => $this->mapDisplayStatus($row),
        ];
    }

    public function linkedForHousehold(array $barangayIds, int $householdId): array
    {
        $buildingId = null;
        if (Schema::hasTable('households')) {
            $buildingId = DB::table('households')->where('id', $householdId)->value('building_id');
        }

        return $this->linkedSubmissionSummaries($barangayIds, $this->userIdsForHouseholds([$householdId]), $buildingId ? (int) $buildingId : null);
    }

    public function linkedForBuilding(array $barangayIds, int $buildingId): array
    {
        $householdIds = [];
        if (Schema::hasTable('households')) {
            $householdIds = DB::table('households')->where('building_id', $buildingId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return $this->linkedSubmissionSummaries($barangayIds, $this->userIdsForHouseholds($householdIds), $buildingId);
    }

    private function linkedSubmissionSummaries(array $barangayIds, array $userIds, ?int $buildingId): array
    {
        if ($barangayIds === [] || ! Schema::hasTable('resident_incident_reports')) {
            return [];
        }
        $hasBuilding = $buildingId && Schema::hasColumn('resident_incident_reports', 'building_id');
        if ($userIds === [] && ! $hasBuilding) {
            return [];
        }

        return $this->assignedSubmissionQuery($barangayIds)
            ->where(function ($inner) use ($userIds, $buildingId, $hasBuilding) {
                if ($userIds !== []) {
                    $inner->whereIn('reports.resident_user_id', $userIds);
                }
                if ($hasBuilding) {
                    $method = $userIds === [] ? 'where' : 'orWhere';
                    $inner->{$method}('reports.building_id', $buildingId);
                }
            })
            ->orderByDesc('reports.submitted_at')
            ->orderByDesc('reports.id')
            ->limit(20)
            ->get()
            ->map(fn ($row) => $this->selectionSummary($row))
            ->all();
    }

    private function userIdsForHouseholds(array $householdIds): array
    {
        if ($householdIds === [] || ! Schema::hasTable('residents') || ! Schema::hasTable('account_resident_links')) {
            return [];
        }

        return DB::table('residents')
            ->join('account_resident_links', 'account_resident_links.resident_id', '=', 'residents.id')
            ->whereIn('residents.household_id', $householdIds)
            ->pluck('account_resident_links.user_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function statusBreakdown(array $barangayIds): array
    {
        $counts = ['submitted' => 0, 'under_review' => 0, 'reviewed' => 0, 'returned' => 0];
        if ($barangayIds !== [] && Schema::hasTable('resident_incident_reports')) {
            $rows = $this->assignedSubmissionQuery($barangayIds)
                ->select('reports.report_status', DB::raw('COUNT(*) as total'))
                ->groupBy('reports.report_status')
                ->get();
            foreach ($rows as $row) {
                if (array_key_exists($row->report_status, $counts)) {
                    $counts[$row->report_status] = (int) $row->total;
                }
            }
        }

        return [
            ['key' => 'submitted', 'label' => 'Submitted', 'count' => $counts['submitted']],
            ['key' => 'under_review', 'label' => 'Under review', 'count' => $counts['under_review']],
            ['key' => 'reviewed', 'label' => 'Accepted', 'count' => $counts['reviewed']],
            ['key' => 'returned', 'label' => 'Returned', 'count' => $counts['returned']],
        ];
    }

    public function submissionMonths(array $barangayIds): array
    {
        if (! Schema::hasTable('resident_incident_reports') || ! Schema::hasColumn('resident_incident_reports', 'submitted_at')) {
            return ['available' => false, 'has_history' => false, 'months' => []];
        }
        $timezone = (string) config('app.timezone', 'UTC');
        $start = now($timezone)->startOfMonth()->subMonths(5);
        $end = now($timezone)->startOfMonth()->addMonth();
        $months = [];
        for ($cursor = $start->copy(); $cursor < $end; $cursor->addMonth()) {
            $months[$cursor->format('Y-m')] = [
                'key' => $cursor->format('Y-m'),
                'label' => $cursor->format('M Y'),
                'count' => 0,
            ];
        }
        $hasHistory = false;
        if ($barangayIds !== []) {
            $hasHistory = $this->assignedSubmissionQuery($barangayIds)->whereNotNull('reports.submitted_at')->exists();
            $stamps = $this->assignedSubmissionQuery($barangayIds)
                ->whereNotNull('reports.submitted_at')
                ->where('reports.submitted_at', '>=', $start->copy()->utc())
                ->where('reports.submitted_at', '<', $end->copy()->utc())
                ->pluck('submitted_at');
            foreach ($stamps as $stamp) {
                $key = Carbon::parse($stamp, 'UTC')->timezone($timezone)->format('Y-m');
                if (isset($months[$key])) {
                    $months[$key]['count']++;
                }
            }
        }

        return [
            'available' => true,
            'has_history' => $hasHistory,
            'months' => array_values($months),
        ];
    }

    public function recentSubmissionRows(array $barangayIds, int $limit = 5): array
    {
        if ($barangayIds === [] || ! Schema::hasTable('resident_incident_reports')) {
            return [];
        }
        $query = $this->namedSubmissionQuery($barangayIds);
        if (Schema::hasColumn('resident_incident_reports', 'submitted_at')) {
            $query->orderByDesc('reports.submitted_at');
        }
        $query->orderByDesc('reports.id')->limit($limit);

        return $query->get()->map(fn ($row) => $this->dashboardSubmissionRow($row))->all();
    }

    public function pendingAttentionRows(array $barangayIds, int $limit = 3): array
    {
        if ($barangayIds === [] || ! Schema::hasTable('resident_incident_reports')) {
            return [];
        }

        $query = $this->namedSubmissionQuery($barangayIds)
            ->whereIn('reports.report_status', ['submitted', 'under_review'])
            ->when(
                Schema::hasColumn('resident_incident_reports', 'barangay_report_id'),
                fn ($query) => $query->whereNull('reports.barangay_report_id'),
            );
        if (Schema::hasColumn('resident_incident_reports', 'submitted_at')) {
            $query->orderByDesc('reports.submitted_at');
        }

        return $query
            ->orderByDesc('reports.id')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'reference' => $row->report_reference,
                'kind' => 'resident',
                'type' => 'Resident report',
                'detail' => $row->reported_location ?: ($row->incident_name ?: 'Awaiting BDRRM review'),
                'action' => 'Review',
                'path' => '/submissions/'.$row->id,
            ])
            ->all();
    }

    public function dashboard(User $user): array
    {
        $rows = $this->owned($user->id);
        $counts = ['submitted' => 0, 'under_review' => 0, 'needs_correction' => 0, 'finalized' => 0];
        $recent = [];
        $attention = [];
        $updates = [];
        $submittedAt = [];
        $draft = null;
        foreach ($rows as $row) {
            $presented = $this->present($row);
            if ($presented['status'] === 'draft') {
                $draft ??= ['id' => $presented['id'], 'reference' => $presented['reference']];

                continue;
            }
            $counts['submitted']++;
            $counts[$presented['bucket']]++;
            if (! empty($presented['submitted_at'])) {
                $submittedAt[] = $presented['submitted_at'];
            }
            if (count($recent) < 5) {
                $recent[] = $presented;
            }
            if ($presented['status'] === 'returned') {
                $attention[] = [
                    'id' => $presented['id'],
                    'reference' => $presented['reference'],
                    'reason' => $presented['return_reason'],
                ];
            }
            foreach (['submitted_at' => 'Submitted', 'returned_at' => 'Returned for correction', 'reviewed_at' => 'Accepted by BDRRM'] as $column => $title) {
                if (! empty($presented[$column])) {
                    $updates[] = [
                        'id' => $presented['id'],
                        'reference' => $presented['reference'],
                        'title' => $title,
                        'at' => $presented[$column],
                    ];
                }
            }
        }
        usort($updates, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));

        return [
            'greeting_name' => $user->display_name,
            'first_name' => $user->first_name,
            'barangay_name' => $this->barangayName($user->barangay_id),
            'activity' => $this->activity($submittedAt),
            'reporting_open' => $this->reportingOpen($user),
            'draft' => $draft,
            'counts' => $counts,
            'recent' => $recent,
            'attention' => $attention,
            'updates' => array_slice($updates, 0, 8),
        ];
    }

    private function activity(array $timestamps): array
    {
        $months = [];
        $cursor = now()->startOfMonth()->subMonths(5);
        for ($i = 0; $i < 6; $i++) {
            $point = $cursor->copy()->addMonths($i);
            $months[$point->format('Y-m')] = [
                'label' => $point->format('M Y'),
                'count' => 0,
            ];
        }
        foreach ($timestamps as $timestamp) {
            $key = Carbon::parse($timestamp)->format('Y-m');
            if (isset($months[$key])) {
                $months[$key]['count']++;
            }
        }

        return array_values($months);
    }

    public function profile(User $user): array
    {
        $user->loadMissing('role');
        $home = $this->linkedHome($user);
        $buildings = $this->authorizedBuildings($user);

        return [
            'name' => $user->display_name,
            'email' => $user->email,
            'role' => $user->role?->name,
            'contact_number' => $user->contact_number,
            'barangay_name' => $this->barangayName($user->barangay_id),
            'registry_reference' => $home['registry_reference'],
            'suggested_location' => $home['suggested_location'],
            'home_latitude' => $home['home_latitude'],
            'home_longitude' => $home['home_longitude'],
            'linked_building' => $buildings[0] ?? null,
            'linked_buildings' => $buildings,
            'reporting_open' => $this->reportingOpen($user),
            'profile_photo_url' => $this->profilePhotoUrl($user),
        ];
    }

    /**
     * Verified registry buildings the resident may confirm for a disaster report.
     *
     * @return list<array{building_id: int, building_reference: ?string, household_reference: ?string, barangay_name: ?string, address: ?string}>
     */
    public function authorizedBuildings(User $user, ?int $barangayId = null): array
    {
        $barangayId = $barangayId ?? ($user->barangay_id ? (int) $user->barangay_id : null);
        if ($barangayId === null
            || ! Schema::hasTable('account_resident_links')
            || ! Schema::hasTable('residents')
            || ! Schema::hasTable('households')
            || ! Schema::hasTable('buildings')) {
            return [];
        }

        $query = DB::table('account_resident_links')
            ->join('residents', 'residents.id', '=', 'account_resident_links.resident_id')
            ->join('households', 'households.id', '=', 'residents.household_id')
            ->join('buildings', 'buildings.id', '=', 'households.building_id')
            ->where('account_resident_links.user_id', $user->id)
            ->where('households.barangay_id', $barangayId)
            ->where('buildings.barangay_id', $barangayId)
            ->whereNotNull('households.building_id');

        $columns = [
            'buildings.id as building_id',
            'buildings.building_reference',
        ];
        if (Schema::hasColumn('buildings', 'address_description')) {
            $columns[] = 'buildings.address_description';
        }
        if (Schema::hasColumn('households', 'household_reference')) {
            $columns[] = 'households.household_reference';
        }
        if (Schema::hasTable('barangays')) {
            $query->leftJoin('barangays', 'barangays.id', '=', 'buildings.barangay_id');
            $columns[] = 'barangays.barangay_name';
        }

        $seen = [];
        $buildings = [];
        foreach ($query->get($columns) as $row) {
            $id = (int) $row->building_id;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $buildings[] = [
                'building_id' => $id,
                'building_reference' => $this->value($row, 'building_reference'),
                'household_reference' => $this->value($row, 'household_reference'),
                'barangay_name' => $this->value($row, 'barangay_name') ?: $this->barangayName($barangayId),
                'address' => $this->value($row, 'address_description'),
            ];
        }

        return $buildings;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function resolveConfirmedBuildingId(User $user, array $data, ?int $barangayId = null): ?int
    {
        if (empty($data['confirm_affected_building'])) {
            return null;
        }
        $authorized = $this->authorizedBuildings($user, $barangayId);
        if ($authorized === []) {
            abort(422, 'No verified building association is available to confirm.');
        }
        $requested = isset($data['affected_building_id']) ? (int) $data['affected_building_id'] : null;
        if ($requested) {
            foreach ($authorized as $building) {
                if ($building['building_id'] === $requested) {
                    return $requested;
                }
            }
            abort(422, 'That building is not linked to your verified registry record.');
        }
        if (count($authorized) === 1) {
            return $authorized[0]['building_id'];
        }
        abort(422, 'Select which verified building was affected by the incident.');
    }

    private function profilePhotoUrl(User $user): ?string
    {
        if (! Schema::hasColumn('users', 'profile_photo_path') || ! is_string($user->profile_photo_path) || $user->profile_photo_path === '') {
            return null;
        }

        $version = substr(sha1($user->profile_photo_path), 0, 12);

        return '/resident/api/profile/photo?v='.$version;
    }

    public function incidentsFor(User $user): array
    {
        if (! $this->reportingOpen($user) || ! Schema::hasTable('disaster_incidents') || ! Schema::hasTable('disaster_barangays')) {
            return [];
        }
        $query = DB::table('disaster_incidents as incidents')
            ->join('disaster_barangays as links', 'links.disaster_incident_id', '=', 'incidents.id')
            ->where('links.barangay_id', $user->barangay_id)
            ->where('incidents.incident_status', 'active')
            ->orderByDesc('incidents.occurred_at')
            ->select(['incidents.id', 'incidents.incident_name', 'incidents.occurred_at']);
        if (Schema::hasTable('disaster_types') && Schema::hasColumn('disaster_incidents', 'disaster_type_id')) {
            $query->leftJoin('disaster_types', 'disaster_types.id', '=', 'incidents.disaster_type_id')
                ->addSelect('disaster_types.name as incident_type');
        }

        return $query->get()->map(fn ($row) => [
            'id' => (int) $row->id,
            'name' => $row->incident_name,
            'type' => $row->incident_type ?? null,
            'occurred_at' => $row->occurred_at,
        ])->all();
    }

    public function incidentUpdates(User $user): array
    {
        if (! Schema::hasTable('disaster_incidents')
            || ! Schema::hasColumn('disaster_incidents', 'published_for_residents')
            || ! Schema::hasColumn('disaster_incidents', 'resident_coverage')) {
            return [];
        }

        $query = DB::table('disaster_incidents as incidents')
            ->where('incidents.published_for_residents', true)
            ->whereIn('incidents.resident_coverage', ['barangays', 'citywide']);

        if (Schema::hasTable('disaster_types') && Schema::hasColumn('disaster_incidents', 'disaster_type_id')) {
            $query->leftJoin('disaster_types', 'disaster_types.id', '=', 'incidents.disaster_type_id')
                ->addSelect('disaster_types.name as incident_type');
        }

        $query->where(function ($visible) use ($user) {
            $visible->where('incidents.resident_coverage', 'citywide');
            if ($user->barangay_id && Schema::hasTable('disaster_barangays')) {
                $visible->orWhere(function ($barangayScoped) use ($user) {
                    $barangayScoped->where('incidents.resident_coverage', 'barangays')
                        ->whereExists(function ($link) use ($user) {
                            $link->selectRaw('1')
                                ->from('disaster_barangays')
                                ->whereColumn('disaster_barangays.disaster_incident_id', 'incidents.id')
                                ->where('disaster_barangays.barangay_id', $user->barangay_id);
                        });
                });
            }
        });

        $rows = $query
            ->addSelect('incidents.*')
            ->orderByRaw("case when incidents.incident_status = 'active' then 0 else 1 end")
            ->orderByRaw("case when incidents.resident_coverage = 'barangays' then 0 else 1 end")
            ->orderByDesc('incidents.published_at')
            ->orderByDesc('incidents.id')
            ->get();

        $coverage = $this->publishedCoverage($rows->pluck('id')->all());

        return $rows->map(function ($row) use ($coverage) {
            $status = (string) $row->incident_status;
            $publishedAt = $row->published_at ?? null;
            $updatedAt = $row->updated_at ?? null;

            return [
                'id' => (int) $row->id,
                'name' => $row->incident_name,
                'disaster_type' => $row->incident_type ?? null,
                'status' => $status,
                'status_label' => $status === 'closed' ? 'Closed' : 'Active',
                'coverage' => $row->resident_coverage,
                'coverage_label' => $row->resident_coverage === 'citywide'
                    ? 'City-wide'
                    : implode(', ', $coverage[$row->id] ?? []),
                'occurred_at' => $row->occurred_at ?? null,
                'published_at' => $publishedAt,
                'updated_at' => ($updatedAt && $publishedAt && strcmp((string) $updatedAt, (string) $publishedAt) > 0) ? $updatedAt : null,
                'description' => filled($row->description ?? null) ? trim((string) $row->description) : null,
            ];
        })->all();
    }

    private function publishedCoverage(array $incidentIds): array
    {
        if ($incidentIds === [] || ! Schema::hasTable('disaster_barangays') || ! Schema::hasTable('barangays')) {
            return [];
        }

        $names = [];
        $rows = DB::table('disaster_barangays')
            ->join('barangays', 'barangays.id', '=', 'disaster_barangays.barangay_id')
            ->whereIn('disaster_barangays.disaster_incident_id', $incidentIds)
            ->orderBy('barangays.barangay_name')
            ->get(['disaster_barangays.disaster_incident_id', 'barangays.barangay_name']);
        foreach ($rows as $row) {
            $names[$row->disaster_incident_id][] = $row->barangay_name;
        }

        return $names;
    }

    public function present(object $row, bool $withTimeline = false): array
    {
        $point = $this->coordinates($row);
        $incident = $this->incident($row);
        $downstream = $this->downstream($row);
        $label = $this->residentLabel($row->report_status, $downstream);
        $payload = [
            'id' => (int) $row->id,
            'reference' => $row->report_reference,
            'status' => $row->report_status,
            'label' => $label,
            'bucket' => $this->bucket($row->report_status, $label),
            'incident_name' => $this->incidentLabel((object) array_merge((array) $row, ['incident_name' => $incident['name']])),
            'reported_incident_type' => Schema::hasColumn('resident_incident_reports', 'reported_incident_type') ? ($this->value($row, 'reported_incident_type') ?: null) : null,
            'incident_type' => $incident['type'],
            'occurred_at' => $incident['occurred_at'],
            'disaster_incident_id' => ! empty($row->disaster_incident_id) ? (int) $row->disaster_incident_id : null,
            'reported_location' => $this->value($row, 'reported_location'),
            'incident_description' => $this->value($row, 'incident_description'),
            'latitude' => $point['latitude'],
            'longitude' => $point['longitude'],
            'has_point' => $point['longitude'] !== null && $point['latitude'] !== null,
            'resident_name' => $this->reporterName((int) $row->resident_user_id),
            'barangay_name' => $this->barangayName((int) $row->barangay_id),
            'barangay_report_id' => ($linked = $this->value($row, 'barangay_report_id')) ? (int) $linked : null,
            'barangay_workflow_stage' => $this->barangayWorkflowStage($linked ? (int) $linked : null),
            'review_state' => $this->activeAssignment((int) $row->id, (string) $row->report_status)['state'],
            'reviewable' => in_array($row->report_status, ['submitted', 'under_review'], true) && empty($this->value($row, 'barangay_report_id')),
            'location_note' => 'Awaiting BDRRM verification.',
            'observed_at' => $this->value($row, 'observed_at'),
            'observed_at_approximate' => (bool) $this->value($row, 'observed_at_approximate', false),
            'submitted_at' => $this->value($row, 'submitted_at'),
            'returned_at' => $this->value($row, 'returned_at'),
            'return_reason' => $this->value($row, 'return_reason'),
            'reviewed_at' => $this->value($row, 'reviewed_at'),
            'editable' => in_array($row->report_status, ['draft', 'returned'], true),
            'edit_message' => $this->editMessage($row->report_status, $label),
            'photo_count' => $this->photoCount((int) $row->id),
            'updated_at' => $row->updated_at,
            'content_revision' => $this->contentRevision($row),
            'open_flags' => $this->openFlagCount((int) $row->id),
            'building_id' => ($bid = $this->value($row, 'building_id')) ? (int) $bid : null,
            'confirm_affected_building' => ! empty($this->value($row, 'building_id')),
            'linked_building' => $this->buildingCardForReport($row),
        ];
        if ($withTimeline) {
            $payload['photos'] = $this->photos((int) $row->id);
            $payload['timeline'] = $this->timeline($row, $downstream);
            $payload['signature'] = $this->residentSignature($row);
            $payload['flags'] = ReportFlagController::forSubject('resident_incident_report', (int) $row->id);
            $payload['bdrrm_endorsement'] = $this->bdrrmEndorsement($row);
        }

        return $payload;
    }

    public function photoCount(int $reportId): int
    {
        if (! Schema::hasTable('incident_photos') || ! Schema::hasColumn('incident_photos', 'incident_report_id')) {
            return 0;
        }

        return DB::table('incident_photos')->where('incident_report_id', $reportId)->count();
    }

    private function residentLabel(string $status, array $downstream): string
    {
        if ($status === 'draft') {
            return 'Draft';
        }
        if ($status === 'returned') {
            return 'Needs Correction';
        }
        if (($downstream['consolidated_status'] ?? null) === 'finalized') {
            return 'Finalized';
        }
        if (! empty($downstream['barangay_submitted_at'])) {
            return ($downstream['assessment_status'] ?? null) === 'submitted'
                ? 'Pending CDRRMO Review'
                : 'Pending Assessor Review';
        }

        return 'Pending BDRRM Review';
    }

    private function bucket(string $status, string $label): ?string
    {
        if ($status === 'draft') {
            return null;
        }
        if ($status === 'returned') {
            return 'needs_correction';
        }
        if ($label === 'Finalized') {
            return 'finalized';
        }

        return 'under_review';
    }

    private function downstream(object $row): array
    {
        $result = ['barangay_submitted_at' => null, 'assessment_status' => null, 'consolidated_status' => null, 'assessment_updated_at' => null, 'consolidated_updated_at' => null];
        $barangayId = $this->value($row, 'barangay_report_id');
        if (! $barangayId || ! Schema::hasTable('barangay_reports')) {
            return $result;
        }
        $barangay = DB::table('barangay_reports')->where('id', $barangayId)->first();
        if ($barangay === null) {
            return $result;
        }
        $result['barangay_submitted_at'] = $barangay->submitted_at ?? null;
        if (! Schema::hasTable('field_assessments')) {
            return $result;
        }
        $assessment = DB::table('field_assessments')->where('barangay_report_id', $barangay->id)->orderByDesc('id')->first();
        if ($assessment === null) {
            return $result;
        }
        $result['assessment_status'] = $assessment->assessment_status;
        $result['assessment_updated_at'] = $assessment->updated_at;
        if (! Schema::hasTable('consolidated_report_assessments') || ! Schema::hasTable('consolidated_reports')) {
            return $result;
        }
        $consolidatedId = DB::table('consolidated_report_assessments')->where('field_assessment_id', $assessment->id)->value('consolidated_report_id');
        if ($consolidatedId) {
            $consolidated = DB::table('consolidated_reports')->where('id', $consolidatedId)->first();
            $result['consolidated_status'] = $consolidated->report_status ?? null;
            $result['consolidated_updated_at'] = $consolidated->updated_at ?? null;
        }

        return $result;
    }

    private function timeline(object $row, array $downstream): array
    {
        $events = [];
        $push = function (?string $at, string $title, ?string $detail = null) use (&$events) {
            if ($at) {
                $events[] = ['at' => $at, 'title' => $title, 'detail' => $detail];
            }
        };
        $push($this->value($row, 'created_at'), 'Draft saved');
        $push($this->value($row, 'submitted_at'), 'Submitted to BDRRM');
        $push($this->value($row, 'returned_at'), 'Needs correction', $this->value($row, 'return_reason'));
        $push($this->value($row, 'reviewed_at'), 'Accepted by BDRRM');
        $push($downstream['barangay_submitted_at'], 'Sent for assessor review');
        if (($downstream['assessment_status'] ?? null) === 'submitted') {
            $push($downstream['assessment_updated_at'], 'Assessment sent to CDRRMO');
        }
        if (($downstream['consolidated_status'] ?? null) === 'finalized') {
            $push($downstream['consolidated_updated_at'], 'Finalized');
        }
        usort($events, fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));

        return $events;
    }

    private function photos(int $reportId): array
    {
        if (! Schema::hasTable('incident_photos') || ! Schema::hasColumn('incident_photos', 'incident_report_id')) {
            return [];
        }

        return DB::table('incident_photos')
            ->where('incident_report_id', $reportId)
            ->orderBy('id')
            ->get(['id', 'original_filename', 'mime_type', 'file_size'])
            ->map(fn ($photo) => [
                'id' => (int) $photo->id,
                'name' => $photo->original_filename,
                'mime_type' => $photo->mime_type,
                'file_size' => (int) $photo->file_size,
            ])->all();
    }

    private function owned(int $userId)
    {
        return DB::table('resident_incident_reports')
            ->where('resident_user_id', $userId)
            ->orderByDesc('updated_at')
            ->get();
    }

    private function incident(object $row): array
    {
        if (! Schema::hasTable('disaster_incidents')) {
            return ['name' => null, 'type' => null, 'occurred_at' => null];
        }
        $query = DB::table('disaster_incidents')->where('id', $row->disaster_incident_id);
        $incident = $query->first();
        if ($incident === null) {
            return ['name' => null, 'type' => null, 'occurred_at' => null];
        }
        $type = null;
        if (Schema::hasTable('disaster_types') && isset($incident->disaster_type_id)) {
            $type = DB::table('disaster_types')->where('id', $incident->disaster_type_id)->value('name');
        }

        return [
            'name' => $incident->incident_name ?? null,
            'type' => $type,
            'occurred_at' => $incident->occurred_at ?? null,
        ];
    }

    private function assertReportingOpen(User $user): void
    {
        if (! $this->reportingOpen($user)) {
            abort(422, 'Your barangay is not assigned yet. Ask barangay staff to assign it before reporting.');
        }
    }

    private function assertIncident(int $barangayId, int $incidentId): int
    {
        $incident = DB::table('disaster_incidents')->where('id', $incidentId)->first();
        if ($incident === null || ($incident->incident_status ?? 'active') !== 'active') {
            abort(422, 'Choose an open incident for your barangay.');
        }
        $linked = ! Schema::hasTable('disaster_barangays') || DB::table('disaster_barangays')
            ->where('disaster_incident_id', $incidentId)
            ->where('barangay_id', $barangayId)
            ->exists();
        if (! $linked) {
            abort(422, 'Choose an open incident for your barangay.');
        }

        return $incidentId;
    }

    private function writePoint(int $id, $longitude, $latitude): void
    {
        if ($longitude === null || $latitude === null || $longitude === '' || $latitude === '') {
            if (Schema::hasColumn('resident_incident_reports', 'reported_point')) {
                DB::table('resident_incident_reports')->where('id', $id)->update(['reported_point' => null]);
            }

            return;
        }
        if (! Schema::hasColumn('resident_incident_reports', 'reported_point')) {
            return;
        }
        if (DB::getDriverName() === 'mysql') {
            DB::update(
                "UPDATE resident_incident_reports SET reported_point = ST_GeomFromText(?, 4326, 'axis-order=long-lat') WHERE id = ?",
                [sprintf('POINT(%F %F)', (float) $longitude, (float) $latitude), $id],
            );

            return;
        }
        DB::table('resident_incident_reports')->where('id', $id)->update([
            'reported_point' => ((float) $longitude).' '.((float) $latitude),
        ]);
    }

    private function coordinates(object $row): array
    {
        if (DB::getDriverName() === 'mysql' && Schema::hasColumn('resident_incident_reports', 'reported_point')) {
            $point = DB::selectOne(
                'SELECT ST_Longitude(reported_point) AS lng, ST_Latitude(reported_point) AS lat FROM resident_incident_reports WHERE id = ?',
                [$row->id],
            );
            if ($point && $point->lng !== null && $point->lat !== null) {
                return $this->boundedPoint((float) $point->lng, (float) $point->lat);
            }
        }
        $raw = $this->value($row, 'reported_point');
        if (is_string($raw) && preg_match('/^(-?\d+(?:\.\d+)?) (-?\d+(?:\.\d+)?)$/', trim($raw), $match)) {
            return $this->boundedPoint((float) $match[1], (float) $match[2]);
        }

        return ['longitude' => null, 'latitude' => null];
    }

    private function namedSubmissionQuery(array $barangayIds)
    {
        $query = $this->assignedSubmissionQuery($barangayIds);
        if (Schema::hasTable('users')) {
            $query->leftJoin('users', 'users.id', '=', 'reports.resident_user_id');
        }
        if (Schema::hasTable('disaster_incidents')) {
            $query->leftJoin('disaster_incidents', 'disaster_incidents.id', '=', 'reports.disaster_incident_id');
        }
        $query->select('reports.*');
        if (Schema::hasTable('users')) {
            $userColumns = ['users.first_name', 'users.last_name'];
            if (Schema::hasColumn('users', 'middle_name')) {
                $userColumns[] = 'users.middle_name';
            }
            $query->addSelect($userColumns);
        }
        if (Schema::hasTable('disaster_incidents') && Schema::hasColumn('disaster_incidents', 'incident_name')) {
            $query->addSelect('disaster_incidents.incident_name');
        }

        return $query;
    }

    private function dashboardSubmissionRow(object $row): array
    {
        $name = trim(implode(' ', array_filter([
            $row->first_name ?? null,
            $row->middle_name ?? null,
            $row->last_name ?? null,
        ], fn ($part) => is_string($part) && $part !== '')));
        $pending = in_array($row->report_status, ['submitted', 'under_review'], true)
            && empty($this->value($row, 'barangay_report_id'));

        return [
            'id' => (int) $row->id,
            'reference' => $row->report_reference,
            'resident_name' => $name !== '' ? $name : null,
            'incident_name' => $row->incident_name ?? null,
            'reported_location' => $row->reported_location ?? null,
            'submitted_at' => $row->submitted_at ?? null,
            'status' => $row->report_status,
            'label' => $this->mapDisplayStatus($row),
            'action' => $pending ? 'Review' : 'View',
            'path' => '/submissions/'.$row->id,
        ];
    }

    private function selectPointColumns($query): void
    {
        if (DB::getDriverName() !== 'mysql' || ! Schema::hasColumn('resident_incident_reports', 'reported_point')) {
            return;
        }
        $query->addSelect(DB::raw('ST_Longitude(reports.reported_point) as report_lng'));
        $query->addSelect(DB::raw('ST_Latitude(reports.reported_point) as report_lat'));
    }

    private function pointFromSelection(object $row): array
    {
        if (property_exists($row, 'report_lng') || property_exists($row, 'report_lat')) {
            if ($row->report_lng === null || $row->report_lat === null) {
                return ['longitude' => null, 'latitude' => null];
            }

            return $this->boundedPoint((float) $row->report_lng, (float) $row->report_lat);
        }

        return $this->coordinates($row);
    }

    private function boundedPoint(float $longitude, float $latitude): array
    {
        if ($longitude < -180 || $longitude > 180 || $latitude < -90 || $latitude > 90) {
            return ['longitude' => null, 'latitude' => null];
        }

        return ['longitude' => $longitude, 'latitude' => $latitude];
    }

    private function mapDisplayStatus(object $row): string
    {
        if (in_array($row->report_status, ['submitted', 'under_review'], true) && empty($this->value($row, 'barangay_report_id'))) {
            return 'Pending BDRRM Review';
        }

        return $this->residentLabel($row->report_status, $this->downstream($row));
    }

    private function reporterName(int $userId): ?string
    {
        if ($userId === 0 || ! Schema::hasTable('users')) {
            return null;
        }
        $columns = ['first_name', 'last_name'];
        if (Schema::hasColumn('users', 'middle_name')) {
            $columns[] = 'middle_name';
        }
        $user = DB::table('users')->where('id', $userId)->first($columns);
        if ($user === null) {
            return null;
        }
        $name = trim(implode(' ', array_filter([
            $user->first_name ?? null,
            $user->middle_name ?? null,
            $user->last_name ?? null,
        ], fn ($part) => is_string($part) && $part !== '')));

        return $name !== '' ? $name : null;
    }

    private function linkedHome(User $user): array
    {
        $empty = [
            'registry_reference' => null,
            'suggested_location' => null,
            'home_latitude' => null,
            'home_longitude' => null,
            'building_id' => null,
            'building_reference' => null,
            'household_reference' => null,
            'address' => null,
        ];
        if (! Schema::hasTable('account_resident_links') || ! Schema::hasTable('residents')) {
            return $empty;
        }

        $query = DB::table('account_resident_links')
            ->join('residents', 'residents.id', '=', 'account_resident_links.resident_id')
            ->where('account_resident_links.user_id', $user->id);
        $columns = ['residents.registry_reference'];
        $canPlace = Schema::hasTable('households') && Schema::hasTable('buildings') && Schema::hasTable('barangays');
        if ($canPlace) {
            $query->leftJoin('households', 'households.id', '=', 'residents.household_id')
                ->leftJoin('buildings', 'buildings.id', '=', 'households.building_id')
                ->leftJoin('barangays', 'barangays.id', '=', 'buildings.barangay_id');
            $columns[] = 'buildings.id as building_id';
            $columns[] = 'buildings.building_reference';
            if (Schema::hasColumn('buildings', 'address_description')) {
                $columns[] = 'buildings.address_description';
            }
            if (Schema::hasColumn('households', 'household_reference')) {
                $columns[] = 'households.household_reference';
            }
            $columns[] = 'barangays.barangay_name';
            $columns[] = 'barangays.barangay_code';
            if (Schema::hasColumn('buildings', 'geographic_point') && DB::getDriverName() === 'mysql') {
                $columns[] = DB::raw('ST_Longitude(buildings.geographic_point) as home_lng');
                $columns[] = DB::raw('ST_Latitude(buildings.geographic_point) as home_lat');
            } elseif (Schema::hasColumn('buildings', 'geographic_point')) {
                $columns[] = 'buildings.geographic_point as home_point';
            }
        }

        $row = $query->first($columns);
        if ($row === null) {
            return $empty;
        }

        $latitude = null;
        $longitude = null;
        if ($this->value($row, 'home_lng') !== null && $this->value($row, 'home_lat') !== null) {
            $longitude = (float) $row->home_lng;
            $latitude = (float) $row->home_lat;
        } else {
            $stored = $this->value($row, 'home_point');
            if (is_string($stored) && preg_match('/^(-?\d+(?:\.\d+)?) (-?\d+(?:\.\d+)?)$/', trim($stored), $match)) {
                $longitude = (float) $match[1];
                $latitude = (float) $match[2];
            }
        }

        $house = trim((string) ($this->value($row, 'address_description') ?? ''));
        $barangay = trim((string) ($this->value($row, 'barangay_name') ?? ''));
        $city = $this->cityForBarangayCode($this->value($row, 'barangay_code'));

        return [
            'registry_reference' => $this->value($row, 'registry_reference'),
            'suggested_location' => ($house !== '' && $barangay !== '' && $city !== null) ? $house.', '.$barangay.', '.$city : null,
            'home_latitude' => $latitude,
            'home_longitude' => $longitude,
            'building_id' => ($bid = $this->value($row, 'building_id')) ? (int) $bid : null,
            'building_reference' => $this->value($row, 'building_reference'),
            'household_reference' => $this->value($row, 'household_reference'),
            'address' => $house !== '' ? $house : null,
        ];
    }

    /**
     * @return array{building_id: int, building_reference: ?string, household_reference: ?string, barangay_name: ?string, address: ?string}|null
     */
    private function buildingCardForReport(object $row): ?array
    {
        $buildingId = $this->value($row, 'building_id');
        if (! $buildingId || ! Schema::hasTable('buildings')) {
            return null;
        }
        $query = DB::table('buildings')->where('buildings.id', (int) $buildingId);
        $columns = ['buildings.id as building_id', 'buildings.building_reference'];
        if (Schema::hasColumn('buildings', 'address_description')) {
            $columns[] = 'buildings.address_description';
        }
        if (Schema::hasTable('households')) {
            $query->leftJoin('households', 'households.building_id', '=', 'buildings.id');
            if (Schema::hasColumn('households', 'household_reference')) {
                $columns[] = 'households.household_reference';
            }
        }
        if (Schema::hasTable('barangays')) {
            $query->leftJoin('barangays', 'barangays.id', '=', 'buildings.barangay_id');
            $columns[] = 'barangays.barangay_name';
        }
        $building = $query->first($columns);
        if ($building === null) {
            return null;
        }

        return [
            'building_id' => (int) $building->building_id,
            'building_reference' => $this->value($building, 'building_reference'),
            'household_reference' => $this->value($building, 'household_reference'),
            'barangay_name' => $this->value($building, 'barangay_name') ?: $this->barangayName((int) $row->barangay_id),
            'address' => $this->value($building, 'address_description'),
        ];
    }

    private function cityForBarangayCode(mixed $code): ?string
    {
        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }
        foreach (config('mapr_reference.barangays', []) as $barangay) {
            if (($barangay['barangay_code'] ?? null) === $code) {
                $city = trim((string) ($barangay['city'] ?? ''));

                return $city !== '' ? $city : null;
            }
        }

        return null;
    }

    private function barangayName($barangayId): ?string
    {
        if ($barangayId === null || ! Schema::hasTable('barangays')) {
            return null;
        }

        return DB::table('barangays')->where('id', $barangayId)->value('barangay_name');
    }

    private function customIncidentType(array $data): ?string
    {
        $value = trim((string) ($data['reported_incident_type'] ?? ''));
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > 120) {
            abort(422, 'The specified incident type must be 120 characters or fewer.');
        }

        return $value;
    }

    private function incidentLabel(object $row): ?string
    {
        $official = $row->incident_name ?? null;
        $custom = Schema::hasColumn('resident_incident_reports', 'reported_incident_type')
            ? ($row->reported_incident_type ?? null)
            : null;
        if (is_string($custom) && trim($custom) !== '') {
            return trim((is_string($official) && $official !== '' ? $official.' — ' : '').trim($custom));
        }

        return is_string($official) ? $official : null;
    }

    private function reference(): string
    {
        do {
            $reference = 'RIR-'.now()->year.'-'.Str::upper(Str::random(6));
        } while (DB::table('resident_incident_reports')->where('report_reference', $reference)->exists());

        return $reference;
    }

    private function value(object $row, string $key, mixed $default = null): mixed
    {
        return property_exists($row, $key) ? $row->{$key} : $default;
    }

    private function endorsementAsset(User $user, array $endorsement, ?string &$storedPath): int
    {
        $file = $endorsement['file'] ?? null;
        if ($file instanceof UploadedFile) {
            return $this->storeHeldSignature($user, $file, $storedPath);
        }
        if (! empty($endorsement['use_saved'])) {
            $id = $this->currentSignatureAssetId($user);
            if ($id === null) {
                abort(422, 'No saved signature is available.');
            }

            return $id;
        }

        abort(422, 'Draw a signature or choose your saved signature before accepting.');
    }

    private function storeHeldSignature(User $user, UploadedFile $file, ?string &$storedPath): int
    {
        $path = $file->getRealPath();
        $bytes = $path ? (int) filesize($path) : 0;
        if ($bytes < 1 || $bytes > 2 * 1024 * 1024) {
            abort(422, 'The electronic-signature image must be between 1 byte and 2 MB.');
        }
        $contents = $path ? (string) file_get_contents($path) : '';
        if (preg_match('/<\s*(svg|script|html)\b|<\?xml/i', $contents) === 1) {
            abort(422, 'SVG and other non-image files are not accepted.');
        }
        $info = $path ? @getimagesize($path) : false;
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (! in_array($mime, ['image/png', 'image/jpeg'], true)) {
            abort(422, 'Only a PNG or JPEG electronic-signature image is accepted.');
        }
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 16 || $height < 16 || $width > 4000 || $height > 4000) {
            abort(422, 'The electronic-signature image must be between 16 and 4000 pixels on each side.');
        }
        $extension = $mime === 'image/png' ? 'png' : 'jpg';
        $storedPath = 'signature-images/'.Str::uuid().'.'.$extension;
        Storage::disk('local')->put($storedPath, $contents);
        $stamp = now();

        return (int) DB::table('user_signature_assets')->insertGetId([
            'user_id' => $user->id,
            'storage_path' => $storedPath,
            'mime_type' => $mime,
            'byte_size' => $bytes,
            'width' => $width,
            'height' => $height,
            'sha256' => hash('sha256', $contents),
            'is_current' => false,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);
    }

    private function recordBdrrmSignature(User $user, object $report, int $assetId): void
    {
        if (! Schema::hasTable('report_signatures') || ! Schema::hasColumn('report_signatures', 'resident_incident_report_id')) {
            return;
        }
        $exists = DB::table('report_signatures')
            ->where('resident_incident_report_id', $report->id)
            ->where('signature_role', 'bdrrm_reviewer')
            ->exists();
        if ($exists) {
            return;
        }
        $stamp = now();
        $payload = [
            'signature_reference' => (string) Str::uuid(),
            'document_reference' => $report->report_reference,
            'document_hash' => $this->contentRevision($report),
            'mechanism' => 'authenticated_e_signature',
            'signature_role' => 'bdrrm_reviewer',
            'signed_at' => $stamp,
            'verification_status' => 'verified',
            'signer_user_id' => $user->id,
            'barangay_report_id' => null,
            'field_assessment_id' => null,
            'consolidated_report_id' => null,
            'resident_incident_report_id' => $report->id,
            'user_signature_asset_id' => $assetId,
            'signature_statement' => 'I have reviewed this resident submission and accept it for handoff to RDANA assessor review.',
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];
        $payload = array_filter(
            $payload,
            fn ($column) => Schema::hasColumn('report_signatures', $column),
            ARRAY_FILTER_USE_KEY,
        );
        try {
            DB::table('report_signatures')->insert($payload);
        } catch (QueryException $exception) {
            $message = $exception->getMessage();
            if (str_contains($message, 'UNIQUE') || str_contains($message, 'Duplicate')) {
                return;
            }
            throw $exception;
        }
    }

    private function bdrrmEndorsement(object $row): ?array
    {
        if (! Schema::hasTable('report_signatures') || ! Schema::hasColumn('report_signatures', 'resident_incident_report_id')) {
            return null;
        }
        $signed = DB::table('report_signatures')
            ->where('resident_incident_report_id', $row->id)
            ->where('signature_role', 'bdrrm_reviewer')
            ->first();
        if ($signed === null) {
            return null;
        }
        $signer = Schema::hasTable('users') ? DB::table('users')->where('id', $signed->signer_user_id)->first() : null;

        return [
            'signed_at' => $signed->signed_at,
            'signer_name' => $signer ? trim(implode(' ', array_filter([
                $signer->first_name ?? null,
                $signer->middle_name ?? null,
                $signer->last_name ?? null,
            ]))) : null,
            'statement' => $signed->signature_statement,
            'has_image' => ! empty($signed->user_signature_asset_id),
        ];
    }

    /**
     * @return array{name: ?string, assigned_at: ?string, label: string, state: string}
     */
    private function activeAssignment(int $reportId, string $status): array
    {
        $empty = ['name' => null, 'assigned_at' => null, 'label' => 'Unassigned', 'state' => 'unassigned'];
        if ($status === 'reviewed') {
            $stage = null;
            if (Schema::hasColumn('resident_incident_reports', 'barangay_report_id')) {
                $linked = DB::table('resident_incident_reports')->where('id', $reportId)->value('barangay_report_id');
                $stage = $this->barangayWorkflowStage($linked ? (int) $linked : null);
            }
            $reviewed = match ($stage) {
                'pending_captain', 'handed_to_rdana' => ['label' => 'Secretary checked', 'state' => 'secretary_checked'],
                default => ['label' => 'Next Office — Barangay Secretary', 'state' => 'next_office'],
            };
            $assignee = $this->reviewerFromSignature($reportId) ?? $this->assignmentUser($reportId);

            return [
                'name' => $assignee['name'],
                'assigned_at' => $assignee['assigned_at'],
                'label' => $reviewed['label'],
                'state' => $reviewed['state'],
            ];
        }
        if (! Schema::hasTable('report_assignments')) {
            if ($status === 'under_review') {
                return ['name' => null, 'assigned_at' => null, 'label' => 'Reviewing', 'state' => 'reviewing'];
            }
            if ($status === 'submitted') {
                return ['name' => null, 'assigned_at' => null, 'label' => 'Not Opened', 'state' => 'not_opened'];
            }

            return $empty;
        }
        $row = DB::table('report_assignments')
            ->where('subject_type', 'resident_incident_report')
            ->where('subject_id', $reportId)
            ->where('assignment_role', 'bdrrm')
            ->where('is_active', true)
            ->first();
        if ($row === null) {
            return $empty;
        }
        $name = null;
        if (Schema::hasTable('users')) {
            $user = DB::table('users')->where('id', $row->assignee_user_id)->first();
            $name = $user ? trim(implode(' ', array_filter([$user->first_name ?? null, $user->last_name ?? null]))) : null;
        }
        $state = $status === 'under_review' ? 'reviewing' : 'not_opened';

        return [
            'name' => $name !== '' ? $name : null,
            'assigned_at' => $row->created_at ?? null,
            'label' => $state === 'reviewing' ? 'Reviewing' : 'Not Opened',
            'state' => $state,
        ];
    }

    /**
     * @return array{name: ?string, assigned_at: ?string}
     */
    private function assignmentUser(int $reportId): array
    {
        if (! Schema::hasTable('report_assignments')) {
            return ['name' => null, 'assigned_at' => null];
        }
        $row = DB::table('report_assignments')
            ->where('subject_type', 'resident_incident_report')
            ->where('subject_id', $reportId)
            ->where('assignment_role', 'bdrrm')
            ->where('is_active', true)
            ->first();
        if ($row === null) {
            return ['name' => null, 'assigned_at' => null];
        }
        $name = null;
        if (Schema::hasTable('users')) {
            $user = DB::table('users')->where('id', $row->assignee_user_id)->first();
            $name = $user ? trim(implode(' ', array_filter([$user->first_name ?? null, $user->last_name ?? null]))) : null;
        }

        return [
            'name' => $name !== '' ? $name : null,
            'assigned_at' => $row->created_at ?? null,
        ];
    }

    /**
     * @return array{name: ?string, assigned_at: ?string}|null
     */
    private function reviewerFromSignature(int $reportId): ?array
    {
        if (! Schema::hasTable('report_signatures') || ! Schema::hasColumn('report_signatures', 'resident_incident_report_id')) {
            return null;
        }
        $signed = DB::table('report_signatures')
            ->where('resident_incident_report_id', $reportId)
            ->where('signature_role', 'bdrrm_reviewer')
            ->first();
        if ($signed === null) {
            return null;
        }
        $name = null;
        if (Schema::hasTable('users')) {
            $user = DB::table('users')->where('id', $signed->signer_user_id)->first();
            $name = $user ? trim(implode(' ', array_filter([$user->first_name ?? null, $user->last_name ?? null]))) : null;
        }

        return [
            'name' => $name !== '' ? $name : null,
            'assigned_at' => $signed->signed_at ?? null,
        ];
    }

    /**
     * @return array{name: ?string, assigned_at: ?string}
     */
    private function secretaryFromSignature(?int $barangayReportId): array
    {
        if ($barangayReportId === null || ! Schema::hasTable('report_signatures') || ! Schema::hasColumn('report_signatures', 'barangay_report_id')) {
            return ['name' => null, 'assigned_at' => null];
        }
        $signed = DB::table('report_signatures')
            ->where('barangay_report_id', $barangayReportId)
            ->where('signature_role', 'barangay_secretary')
            ->first();
        if ($signed === null) {
            return ['name' => null, 'assigned_at' => null];
        }
        $name = null;
        if (Schema::hasTable('users')) {
            $user = DB::table('users')->where('id', $signed->signer_user_id)->first();
            $name = $user ? trim(implode(' ', array_filter([$user->first_name ?? null, $user->last_name ?? null]))) : null;
        }

        return [
            'name' => $name !== '' ? $name : null,
            'assigned_at' => $signed->signed_at ?? null,
        ];
    }

    private function barangayWorkflowStage(?int $barangayReportId): ?string
    {
        if ($barangayReportId === null || ! Schema::hasColumn('barangay_reports', 'workflow_stage')) {
            return null;
        }

        $stage = DB::table('barangay_reports')->where('id', $barangayReportId)->value('workflow_stage');

        return $stage === null ? null : (string) $stage;
    }

    private function currentSignatureAssetId(User $user): ?int
    {
        if (! Schema::hasTable('user_signature_assets')) {
            return null;
        }
        $id = DB::table('user_signature_assets')->where('user_id', $user->id)->where('is_current', true)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function recordResidentSignature(User $user, object $report, string $stamp, int $assetId): void
    {
        if (! Schema::hasTable('report_signatures') || ! Schema::hasColumn('report_signatures', 'resident_incident_report_id')) {
            return;
        }
        $exists = DB::table('report_signatures')
            ->where('resident_incident_report_id', $report->id)
            ->where('signature_role', 'resident_signer')
            ->exists();
        if ($exists) {
            return;
        }

        $payload = [
            'signature_reference' => (string) Str::uuid(),
            'document_reference' => $report->report_reference,
            'document_hash' => hash('sha256', 'resident_incident_report|'.$report->id.'|resident_signer|'.$stamp),
            'mechanism' => 'authenticated_e_signature',
            'signature_role' => 'resident_signer',
            'signed_at' => $stamp,
            'verification_status' => 'verified',
            'signer_user_id' => $user->id,
            'barangay_report_id' => null,
            'field_assessment_id' => null,
            'consolidated_report_id' => null,
            'resident_incident_report_id' => $report->id,
            'user_signature_asset_id' => $assetId,
            'signature_statement' => 'The location, description, and photographs are accurate.',
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ];
        $payload = array_filter(
            $payload,
            fn ($column) => Schema::hasColumn('report_signatures', $column),
            ARRAY_FILTER_USE_KEY,
        );

        try {
            DB::table('report_signatures')->insert($payload);
        } catch (QueryException $exception) {
            $message = $exception->getMessage();
            if (str_contains($message, 'UNIQUE') || str_contains($message, 'Duplicate')) {
                return;
            }
            throw $exception;
        }
    }

    private function residentSignature(object $row): ?array
    {
        if (! Schema::hasTable('report_signatures') || ! Schema::hasColumn('report_signatures', 'resident_incident_report_id')) {
            return null;
        }
        $signature = DB::table('report_signatures')
            ->where('resident_incident_report_id', $row->id)
            ->where('signature_role', 'resident_signer')
            ->first();
        if ($signature === null) {
            return null;
        }

        $signedAt = strtotime((string) $signature->signed_at) ?: 0;
        $updatedAt = strtotime((string) ($row->updated_at ?? '')) ?: 0;
        $returned = $this->value($row, 'returned_at');
        $original = ($returned !== null && $returned !== '') || ($signedAt > 0 && $updatedAt > $signedAt + 1);

        return [
            'signed_at' => $signature->signed_at,
            'statement' => $signature->signature_statement ?? null,
            'preview_url' => '/resident/api/reports/'.$row->id.'/signature',
            'original_submission' => $original,
            'caption' => $original
                ? 'This signature belongs to the original submission and does not cover later corrections.'
                : 'Signed with this submission.',
        ];
    }

    private function editMessage(string $status, string $label): ?string
    {
        if (in_array($status, ['draft', 'returned'], true)) {
            return null;
        }
        if ($label === 'Finalized') {
            return 'Editing is unavailable because this report is finalized.';
        }
        if ($label === 'Pending Assessor Review') {
            return 'Editing is unavailable while the barangay report is with the assessor.';
        }
        if ($label === 'Pending CDRRMO Review') {
            return 'Editing is unavailable while the assessment is with CDRRMO.';
        }
        if ($status === 'reviewed') {
            return 'Editing is unavailable after barangay staff accepted this report.';
        }

        return 'Editing is unavailable while this report is under review.';
    }

    private function onlyExisting(array $values): array
    {
        $kept = [];
        foreach ($values as $column => $value) {
            if (Schema::hasColumn('resident_incident_reports', $column)) {
                $kept[$column] = $value;
            }
        }

        return $kept;
    }
}
