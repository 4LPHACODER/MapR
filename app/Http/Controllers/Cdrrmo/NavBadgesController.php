<?php

namespace App\Http\Controllers\Cdrrmo;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NavBadgesController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'barangay_reports' => $this->countIfTable('barangay_reports', fn ($q) => $q->whereIn('report_status', ['draft', 'review', 'returned'])),
            'field_assessments' => $this->countIfTable('field_assessments', fn ($q) => $q->whereIn('assessment_status', ['draft', 'review', 'returned'])),
            'consolidated_reports' => $this->countIfTable('consolidated_reports', fn ($q) => $q->whereIn('report_status', ['draft', 'review', 'returned'])),
            'badge_meaning' => 'Drafts, returned reports, and reports awaiting review. Submitted, signed, and finalized reports stay in the list and are not included.',
        ]);
    }

    private function countIfTable(string $table, callable $callback): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $query = DB::table($table);
        $callback($query);

        return (int) $query->count();
    }
}
