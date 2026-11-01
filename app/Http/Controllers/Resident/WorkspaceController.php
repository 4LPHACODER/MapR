<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $user?->loadMissing('role');

        return view('resident.app', [
            'user' => $user,
            'roleName' => $user?->role?->name,
            'mapboxToken' => config('mapr.mapbox.token'),
            'mapboxStyle' => config('mapr.mapbox.style'),
        ]);
    }
}
