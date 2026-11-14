<?php

namespace App\Http\Controllers\Bdrrm;

use App\Http\Controllers\Controller;
use App\Services\ResidentMapRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResidentMapController extends Controller
{
    public function __construct(private readonly ResidentMapRegistry $registry) {}

    public function markers(Request $request): JsonResponse
    {
        return response()->json($this->registry->markers($request));
    }

    public function group(Request $request, string $group): JsonResponse
    {
        return response()->json($this->registry->group($request, $group));
    }

    public function resident(Request $request, int $resident): JsonResponse
    {
        return response()->json($this->registry->resident($request, $resident));
    }
}
