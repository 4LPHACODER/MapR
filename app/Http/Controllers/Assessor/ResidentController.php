<?php

namespace App\Http\Controllers\Assessor;

use App\Http\Controllers\Controller;
use App\Models\Registry\Resident;
use App\Services\ResidentRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResidentController extends Controller
{
    public function __construct(private ResidentRegistry $registry) {}

    public function summary(Request $request): JsonResponse
    {
        return $this->registry->summary($request);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->registry->index($request);
    }

    public function show(Request $request, Resident $resident): JsonResponse
    {
        return $this->registry->show($request, $resident);
    }

    public function reports(Request $request, Resident $resident): JsonResponse
    {
        return $this->registry->reports($request, $resident);
    }

    public function storeMapFocus(Request $request): JsonResponse
    {
        return $this->registry->storeMapFocus($request);
    }

    public function showMapFocus(Request $request): JsonResponse
    {
        return $this->registry->showMapFocus($request);
    }
}
