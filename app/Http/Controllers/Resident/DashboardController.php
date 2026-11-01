<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Services\ResidentReportWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private readonly ResidentReportWorkflow $reports) {}

    public function __invoke(Request $request): JsonResponse
    {
        return response()->json($this->reports->dashboard($request->user()));
    }
}
