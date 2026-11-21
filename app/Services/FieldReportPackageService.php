<?php

namespace App\Services;

use App\Models\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FieldReportPackageService
{
    public function __construct(
        private readonly FieldConsolidationService $consolidation,
        private readonly FieldAssessmentExcelExporter $exporter,
        private readonly ReportWorkflow $workflow,
        private readonly ActivityLogger $logger,
    ) {}

    public function preview(User $user, string $scope, ?int $barangayId = null): array
    {
        abort_unless($this->workflow->canSubmitFieldPackage($user), 403, 'Only the city assessor can submit field packages.');
        $assessments = $this->eligibleForScope($scope, $barangayId);
        abort_if($assessments->isEmpty(), 422, 'No eligible reviewed assessments are available for this package.');

        $reportIds = $assessments->pluck('barangay_report_id')->map(fn ($id) => (int) $id)->all();
        $residents = $this->consolidation->residentSubmissionStats($reportIds);
        $latest = $assessments->sortByDesc('updated_at')->first();
        $signatures = $latest ? $this->consolidation->signatureNames((int) $latest->id) : [];

        return [
            'scope' => $scope,
            'barangay_id' => $barangayId,
            'barangay_name' => $scope === 'barangay'
                ? collect($this->consolidation->resolveTargetBarangays())->firstWhere('id', $barangayId)['name'] ?? null
                : 'All four barangays',
            'assessment_count' => $assessments->count(),
            'resident_submission_count' => $residents['total'],
            'latest_verification_at' => $signatures['verified_at'] ?? null,
            'latest_review_at' => $signatures['approved_at'] ?? null,
            'destination' => 'CDRRMO',
            'assessment_ids' => $assessments->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'assessment_references' => $assessments->pluck('assessment_reference')->values()->all(),
        ];
    }

    public function submit(User $user, string $scope, ?int $barangayId = null): array
    {
        abort_unless($this->workflow->canSubmitFieldPackage($user), 403, 'Only the city assessor can submit field packages.');
        abort_unless(Schema::hasTable('field_report_packages'), 503, 'Field report packages are not available.');

        return DB::transaction(function () use ($user, $scope, $barangayId) {
            $ids = $this->eligibleForScope($scope, $barangayId)->pluck('id')->map(fn ($id) => (int) $id)->all();
            abort_if($ids === [], 422, 'No eligible reviewed assessments are available for this package.');

            $locked = DB::table('field_assessments')->whereIn('id', $ids)->lockForUpdate()->get();
            abort_if($locked->count() !== count($ids), 422, 'One or more assessments are no longer eligible.');
            foreach ($locked as $row) {
                if ((string) ($row->workflow_stage ?? '') !== 'ready_for_consolidation') {
                    abort(422, 'Only assessments ready for consolidation can be submitted.');
                }
            }

            $already = DB::table('field_report_package_items as items')
                ->join('field_report_packages as packages', 'packages.id', '=', 'items.field_report_package_id')
                ->whereIn('items.field_assessment_id', $ids)
                ->whereIn('packages.package_status', ['submitted', 'acknowledged'])
                ->exists();
            abort_if($already, 409, 'One or more assessments were already submitted in another package.');

            $hash = hash('sha256', implode(',', $ids).'|'.now()->format('c'));
            $reference = $this->reference();
            $packageId = DB::table('field_report_packages')->insertGetId([
                'package_reference' => $reference,
                'scope' => $scope,
                'barangay_id' => $barangayId,
                'package_status' => 'submitted',
                'destination' => 'cdrrmo',
                'prepared_by' => $user->id,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
                'meta' => json_encode([
                    'assessment_count' => count($ids),
                    'barangay_names' => $scope === 'consolidated'
                        ? $this->consolidation->targetBarangayNames()
                        : [collect($this->consolidation->resolveTargetBarangays())->firstWhere('id', $barangayId)['name'] ?? null],
                ]),
                'snapshot_hash' => $hash,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $rows = DB::table('field_assessments')->whereIn('id', $ids)->get(['id', 'assessment_reference']);
            foreach ($rows as $row) {
                DB::table('field_report_package_items')->insert([
                    'field_report_package_id' => $packageId,
                    'field_assessment_id' => $row->id,
                    'assessment_reference' => $row->assessment_reference,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $export = $this->exporter->generate($user, $scope, $barangayId, $packageId);
            DB::table('field_report_exports')->where('id', $export['id'])->update([
                'field_report_package_id' => $packageId,
                'updated_at' => now(),
            ]);

            $this->workflow->markAssessmentsForwardedToCdrrmo($user, $ids, $reference);
            $this->logger->log(
                $user,
                'field_report_package_submitted',
                'Submitted field package '.$reference.' to CDRRMO with '.count($ids).' assessment(s).',
                'field_report_package',
                $packageId,
            );

            return [
                'id' => $packageId,
                'package_reference' => $reference,
                'assessment_count' => count($ids),
                'export' => $export,
            ];
        });
    }

    public function acknowledge(User $user, int $packageId, ?string $remarks = null): void
    {
        $package = DB::table('field_report_packages')->where('id', $packageId)->first();
        abort_if($package === null, 404);
        abort_unless((string) $package->package_status === 'submitted', 422, 'Only submitted packages can be acknowledged.');

        DB::table('field_report_packages')->where('id', $packageId)->update([
            'package_status' => 'acknowledged',
            'acknowledged_by' => $user->id,
            'acknowledged_at' => now(),
            'updated_at' => now(),
            'meta' => json_encode(array_merge(
                json_decode((string) ($package->meta ?: '{}'), true) ?: [],
                ['ack_remarks' => $remarks],
            )),
        ]);

        $this->logger->log(
            $user,
            'field_report_package_acknowledged',
            'Acknowledged field package '.$package->package_reference.'.',
            'field_report_package',
            $packageId,
        );
    }

    public function returnPackage(User $user, int $packageId, string $reason): void
    {
        $reason = trim($reason);
        abort_if(strlen($reason) < 10, 422, 'Provide a clear return reason.');
        $package = DB::table('field_report_packages')->where('id', $packageId)->first();
        abort_if($package === null, 404);
        abort_unless(in_array((string) $package->package_status, ['submitted', 'acknowledged'], true), 422, 'This package cannot be returned.');

        DB::table('field_report_packages')->where('id', $packageId)->update([
            'package_status' => 'returned',
            'return_reason' => $reason,
            'updated_at' => now(),
        ]);

        $this->logger->log(
            $user,
            'field_report_package_returned',
            'Returned field package '.$package->package_reference.'. '.$reason,
            'field_report_package',
            $packageId,
        );
    }

    private function eligibleForScope(string $scope, ?int $barangayId)
    {
        abort_unless(in_array($scope, ['barangay', 'consolidated'], true), 422, 'Choose a barangay or consolidated package scope.');
        if ($scope === 'barangay') {
            abort_if($barangayId === null, 422, 'Choose a barangay for this package.');
            $allowed = collect($this->consolidation->resolveTargetBarangays())->pluck('id')->filter()->all();
            abort_unless(in_array($barangayId, $allowed, true), 422, 'That barangay is outside the consolidation set.');
        }

        return $this->consolidation->eligibleAssessments($scope === 'barangay' ? $barangayId : null, false);
    }

    private function reference(): string
    {
        return 'FP-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
    }
}
