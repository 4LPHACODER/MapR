<?php

namespace App\Http\Controllers\Cdrrmo;

use App\Http\Controllers\Controller;
use App\Services\ImpactEstimator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use InvalidArgumentException;

class ReportController extends Controller
{
    public function preview(Request $request, ImpactEstimator $estimator): View|Response
    {
        $payload = $this->summary($request, $estimator);

        if ($request->query('format') === 'pdf') {
            $pdf = Pdf::loadView('cdrrmo.report', $payload)
                ->setOption('isRemoteEnabled', false)
                ->setOption('fontDir', base_path('resources/fonts'))
                ->setOption('fontCache', storage_path('fonts'))
                ->setOption('chroot', base_path())
                ->setOption('defaultFont', 'Roboto');

            return $pdf->download('mapr-cdrrmo-draft-report.pdf');
        }

        return view('cdrrmo.report', $payload);
    }

    private function summary(Request $request, ImpactEstimator $estimator): array
    {
        $sessionContext = $request->session()->get('cdrrmo.report_context');

        if (is_array($sessionContext) && is_array($sessionContext['estimate'] ?? null)) {
            return [
                'status' => 'Draft / Unsigned',
                'generated_at' => now(),
                'estimate' => $sessionContext['estimate'],
                'error' => null,
                'signatories' => config('mapr_reference.signatories'),
                'sources' => $this->sources(),
            ];
        }

        $coordinates = $request->input('coordinates');

        if (is_string($coordinates)) {
            $coordinates = json_decode($coordinates, true);
        }

        $estimate = null;
        $error = null;

        if (is_array($coordinates) && count($coordinates) >= 4) {
            try {
                $estimate = $estimator->preview(
                    $coordinates,
                    $request->input('spatial_rule', 'includes_boundary'),
                );
            } catch (InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }

        return [
            'status' => 'Draft / Unsigned',
            'generated_at' => now(),
            'estimate' => $estimate,
            'error' => $error,
            'signatories' => config('mapr_reference.signatories'),
            'sources' => $this->sources(),
        ];
    }

    private function sources(): array
    {
        return [
            'boundaries' => 'geospatial_data/CDRA Shapefiles/Boundary/Tandag_brgy_Bndy_utm.shp (EPSG:32651 → EPSG:4326)',
            'households' => 'geospatial_data/CDRA Shapefiles/HOUSEHOLD 2022/CBMS2022HOUSEHOLD.shp',
            'flood' => 'geospatial_data/Flood/flood.shp (FloodSusc)',
        ];
    }
}
