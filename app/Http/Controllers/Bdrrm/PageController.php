<?php

namespace App\Http\Controllers\Bdrrm;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PageController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $user?->loadMissing('role');

        return view('bdrrm.app', [
            'user' => $user,
            'roleName' => $user?->role?->name,
            'mapboxToken' => config('mapr.mapbox.token'),
            'mapboxStyle' => config('mapr.mapbox.style'),
            'floodClasses' => config('mapr_reference.flood_classes'),
        ]);
    }
}
