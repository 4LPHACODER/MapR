<?php

namespace App\Http\Controllers\Shared;

use App\Http\Controllers\Controller;

use App\Policies\BarangayReportPolicy;
use App\Policies\FieldAssessmentPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportFlagController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_unless(Schema::hasTable('report_flags'), 404);
        $data = $request->validate([
            'subject_type' => ['required', 'in:barangay_report,field_assessment,consolidated_report'],
            'subject_id' => ['required', 'integer'],
            'category' => ['required', 'string', 'max:80'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $this->authorizeSubject($request, $data['subject_type'], (int) $data['subject_id']);
        DB::table('report_flags')->insert([
            'subject_type' => $data['subject_type'],
            'subject_id' => $data['subject_id'],
            'category' => $data['category'],
            'reason' => $data['reason'],
            'status' => 'open',
            'opened_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['data' => self::forSubject($data['subject_type'], (int) $data['subject_id'])], 201);
    }

    public function resolve(Request $request, int $flag): JsonResponse
    {
        abort_unless(Schema::hasTable('report_flags'), 404);
        abort_unless(in_array($request->user()?->role?->code, ['cdrrmo_admin', 'rdana'], true), 403, 'Only a reviewer can resolve a flag.');
        $row = DB::table('report_flags')->where('id', $flag)->first();
        abort_if($row === null, 404);
        abort_if($row->status === 'resolved', 409, 'This flag is already resolved.');
        $data = $request->validate([
            'resolution' => ['required', 'string', 'min:10', 'max:2000'],
        ]);
        $this->authorizeSubject($request, $row->subject_type, (int) $row->subject_id);
        DB::table('report_flags')->where('id', $flag)->update([
            'status' => 'resolved',
            'resolved_by' => $request->user()->id,
            'resolved_at' => now(),
            'resolution' => $data['resolution'],
            'updated_at' => now(),
        ]);

        return response()->json(['data' => self::forSubject($row->subject_type, (int) $row->subject_id)]);
    }

    public static function forSubject(string $type, int $id): array
    {
        if (! Schema::hasTable('report_flags')) {
            return [];
        }

        return DB::table('report_flags')
            ->where('subject_type', $type)
            ->where('subject_id', $id)
            ->orderByDesc('id')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'category' => $row->category,
                'reason' => $row->reason,
                'status' => $row->status,
                'resolution' => $row->resolution,
                'opened_by' => $row->opened_by,
                'resolved_by' => $row->resolved_by,
                'created_at' => $row->created_at,
                'resolved_at' => $row->resolved_at,
            ])
            ->all();
    }

    private function authorizeSubject(Request $request, string $type, int $id): void
    {
        $user = $request->user();
        if ($type === 'barangay_report') {
            $row = DB::table('barangay_reports')->where('id', $id)->first();
            abort_if($row === null, 404);
            abort_unless(app(BarangayReportPolicy::class)->view($user, $row), 403);

            return;
        }
        if ($type === 'field_assessment') {
            $row = DB::table('field_assessments')->where('id', $id)->first();
            abort_if($row === null, 404);
            abort_unless(app(FieldAssessmentPolicy::class)->view($user, $row), 403);

            return;
        }
        $row = DB::table('consolidated_reports')->where('id', $id)->first();
        abort_if($row === null, 404);
        abort_unless($user?->role?->code === 'cdrrmo_admin', 403);
    }
}
