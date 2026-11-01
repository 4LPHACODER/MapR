<?php

namespace App\Http\Controllers\Cdrrmo;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspacePageController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $user?->loadMissing('role');

        return view('cdrrmo.app', [
            'user' => $user,
            'mapboxToken' => config('mapr.mapbox.token'),
            'mapboxStyle' => config('mapr.mapbox.style'),
            'floodClasses' => config('mapr_reference.flood_classes'),
        ]);
    }
}
