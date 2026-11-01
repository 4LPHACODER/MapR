<?php

namespace App\Http\Controllers\Cdrrmo;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! Schema::hasTable('activity_events')) {
            return response()->json([
                'data' => [],
                'meta' => ['total' => 0, 'current_page' => 1, 'last_page' => 1],
            ]);
        }

        $page = max(1, $request->integer('page', 1));
        $perPage = min(50, max(1, $request->integer('per_page', 25)));

        $query = DB::table('activity_events as e')
            ->join('users as u', 'u.id', '=', 'e.user_id')
            ->when($request->filled('action_type'), fn ($q) => $q->where('e.action_type', $request->string('action_type')))
            ->orderByDesc('e.created_at');

        $total = (clone $query)->count();
        $rows = $query
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get([
                'e.id',
                'e.action_type',
                'e.summary',
                'e.subject_type',
                'e.subject_id',
                'e.created_at',
                DB::raw("CONCAT(u.first_name, ' ', u.last_name) as actor_name"),
            ]);

        return response()->json([
            'data' => $rows,
            'meta' => [
                'total' => $total,
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ]);
    }
}
