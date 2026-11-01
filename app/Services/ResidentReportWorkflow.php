<?php

namespace App\Services;

use App\Models\Auth\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
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
        $incidentId = $this->assertIncident((int) $user->barangay_id, (int) $data['disaster_incident_id']);
        $now = now();
        $id = DB::table('resident_incident_reports')->insertGetId($this->onlyExisting([
            'report_reference' => $this->reference(),
            'incident_description' => $data['incident_description'] ?? null,
            'reported_location' => $data['reported_location'] ?? null,
            'report_status' => 'draft',
            'resident_user_id' => $user->id,
            'disaster_incident_id' => $incidentId,
            'barangay_id' => $user->barangay_id,
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
        DB::transaction(function () use ($report, $data) {
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
                $changes['disaster_incident_id'] = (int) $data['disaster_incident_id'];
            }
            if (array_key_exists('observed_at_approximate', $data) || array_key_exists('observed_at', $data)) {
                $observed = array_key_exists('observed_at', $data) ? $data['observed_at'] : $this->value($locked, 'observed_at');
                $changes['observed_at_approximate'] = $observed !== null && $observed !== '' && ! empty($data['observed_at_approximate']);
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
        $this->assertIncident((int) $report->barangay_id, (int) $report->disaster_incident_id);
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
        if (! in_array($report->report_status, ['submitted', 'under_review'], true) || ! empty($this->value($report, 'barangay_report_id'))) {
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

    public function acceptSubmission(User $user, object $report, ReportWorkflow $reports): int
    {
        $linked = $this->value($report, 'barangay_report_id');
        if (! empty($linked)) {
            return (int) $linked;
        }
        if (! in_array($report->report_status, ['submitted', 'under_review'], true)) {
            abort(409, 'This submission cannot be accepted.');
        }
        $barangayReportId = $reports->createBarangayReport($user, [
            'report_description' => $report->incident_description,
            'disaster_incident_id' => $report->disaster_incident_id,
        ], (int) $report->barangay_id);
        DB::table('resident_incident_reports')->where('id', $report->id)->update($this->onlyExisting([
            'report_status' => 'reviewed',
            'reviewed_at' => now(),
            'reviewed_by' => $user->id,
            'barangay_report_id' => $barangayReportId,
            'updated_at' => now(),
        ]));
        $this->logger->log($user, 'resident_report_accepted', 'Accepted '.$report->report_reference.' and opened a barangay report draft.', 'resident_incident_report', (int) $report->id);

        return $barangayReportId;
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
            $key = \Illuminate\Support\Carbon::parse($timestamp)->format('Y-m');
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
            'reporting_open' => $this->reportingOpen($user),
            'profile_photo_url' => $this->profilePhotoUrl($user),
        ];
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
            'incident_name' => $incident['name'],
            'incident_type' => $incident['type'],
            'occurred_at' => $incident['occurred_at'],
            'disaster_incident_id' => (int) $row->disaster_incident_id,
            'reported_location' => $this->value($row, 'reported_location'),
            'incident_description' => $this->value($row, 'incident_description'),
            'latitude' => $point['latitude'],
            'longitude' => $point['longitude'],
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
        ];
        if ($withTimeline) {
            $payload['photos'] = $this->photos((int) $row->id);
            $payload['timeline'] = $this->timeline($row, $downstream);
            $payload['signature'] = $this->residentSignature($row);
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
            if ($point && $point->lng !== null) {
                return ['longitude' => (float) $point->lng, 'latitude' => (float) $point->lat];
            }
        }
        $raw = $this->value($row, 'reported_point');
        if (is_string($raw) && preg_match('/^(-?\d+(?:\.\d+)?) (-?\d+(?:\.\d+)?)$/', trim($raw), $match)) {
            return ['longitude' => (float) $match[1], 'latitude' => (float) $match[2]];
        }

        return ['longitude' => null, 'latitude' => null];
    }

    private function linkedHome(User $user): array
    {
        $empty = [
            'registry_reference' => null,
            'suggested_location' => null,
            'home_latitude' => null,
            'home_longitude' => null,
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
            $columns[] = 'buildings.address_description';
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
