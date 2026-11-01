<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Services\ResidentReportWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(private readonly ResidentReportWorkflow $reports) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->reports->profile($request->user())]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'contact_number' => ['nullable', 'string', 'max:32'],
        ]);
        $request->user()->forceFill([
            'contact_number' => $data['contact_number'] ?? null,
        ])->save();

        return response()->json(['data' => $this->reports->profile($request->user()->fresh())]);
    }
}
