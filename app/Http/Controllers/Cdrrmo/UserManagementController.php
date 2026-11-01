<?php

namespace App\Http\Controllers\Cdrrmo;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserManagementController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::query()
            ->with('role:id,name,code')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'email', 'account_status', 'role_id', 'barangay_id']);

        return response()->json(['data' => $users]);
    }

    public function updateStatus(Request $request, User $user, ActivityLogger $logger): JsonResponse
    {
        $data = $request->validate([
            'account_status' => ['required', 'in:active,inactive'],
        ]);

        $user->forceFill(['account_status' => $data['account_status']])->save();

        $logger->log(
            $request->user(),
            'user_status_updated',
            'Updated account status for '.$user->email.' to '.$data['account_status'],
            'user',
            $user->id,
        );

        return response()->json(['ok' => true]);
    }

    public function assignBarangay(Request $request, User $user, ActivityLogger $logger): JsonResponse
    {
        $data = $request->validate([
            'barangay_id' => ['nullable', 'integer', 'exists:barangays,id'],
        ]);

        $user->forceFill(['barangay_id' => $data['barangay_id']])->save();

        if ($data['barangay_id']) {
            $exists = DB::table('user_barangay_assignments')
                ->where('user_id', $user->id)
                ->where('barangay_id', $data['barangay_id'])
                ->whereNull('revoked_at')
                ->exists();

            if (! $exists) {
                DB::table('user_barangay_assignments')->insert([
                    'user_id' => $user->id,
                    'barangay_id' => $data['barangay_id'],
                    'assigned_by' => $request->user()->id,
                    'assigned_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $logger->log($request->user(), 'user_barangay_assigned', 'Updated barangay assignment for '.$user->email, 'user', $user->id);

        return response()->json(['ok' => true]);
    }
}
