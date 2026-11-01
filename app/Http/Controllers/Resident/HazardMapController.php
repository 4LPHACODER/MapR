<?php

namespace App\Http\Controllers\Resident;

use App\Http\Controllers\Controller;
use App\Services\MapboxRouteClient;
use App\Support\EvacuationSites;
use App\Support\ResidentHazardCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HazardMapController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! Storage::disk('local')->exists('resident-hazards/catalog.json')) {
            return response()->json([
                'message' => 'Hazard layers are not available.',
                'note' => ResidentHazardCatalog::NOTE,
                'layers' => [],
            ], 404);
        }

        $catalog = json_decode((string) Storage::disk('local')->get('resident-hazards/catalog.json'), true);

        if (! is_array($catalog)) {
            return response()->json([
                'message' => 'Hazard layers are not available.',
                'note' => ResidentHazardCatalog::NOTE,
                'layers' => [],
            ], 404);
        }

        $catalog['assigned_psgc'] = $this->assignedPsgc($request);
        $catalog['coverage'] = 'Tandag City';

        return response()->json($catalog);
    }

    public function places(): StreamedResponse|JsonResponse
    {
        return $this->file('critical-points.geojson', 'Critical points are not available.');
    }

    public function evacuationSites(): JsonResponse
    {
        $collection = $this->criticalCollection();
        if ($collection === null) {
            return response()->json(['message' => EvacuationSites::MISSING], 404);
        }

        return response()->json(EvacuationSites::onlyListed($collection));
    }

    public function nearestSite(Request $request, MapboxRouteClient $routes): JsonResponse
    {
        $origin = $this->origin($request);
        $profile = $this->profile($request);
        $listed = $this->listedSites();
        if ($listed instanceof JsonResponse) {
            return $listed;
        }
        $restricted = $request->exists('site_ids');
        $searchArea = 'Tandag City';
        if ($restricted) {
            $selection = $request->validate([
                'site_ids' => ['present', 'array', 'max:100'],
                'site_ids.*' => ['string', 'max:80'],
                'area_name' => ['nullable', 'string', 'max:80'],
            ]);
            $allowed = array_fill_keys($selection['site_ids'], true);
            $listed = array_values(array_filter(
                $listed,
                fn (array $feature): bool => isset($allowed[(string) ($feature['id'] ?? '')]),
            ));
            if ($listed === []) {
                return response()->json(['message' => 'No listed evacuation sites are in the selected area.'], 422);
            }
            $searchArea = trim(strip_tags((string) ($selection['area_name'] ?? '')));
            if ($searchArea === '') {
                $searchArea = 'the selected barangay';
            }
        }
        $bounds = $this->coverageBounds();
        if ($bounds !== null && ! EvacuationSites::inside($bounds, $origin[0], $origin[1])) {
            return response()->json(['message' => 'Your position is outside the available map coverage.'], 422);
        }
        $ranked = EvacuationSites::ranked($listed, $origin[0], $origin[1], $bounds);
        if ($ranked === []) {
            return response()->json(['message' => 'No listed evacuation sites are inside the available map coverage.'], 422);
        }
        $shortlist = array_slice($ranked, 0, 5);
        $choice = $shortlist[0];
        $method = 'straight-line';
        $summary = 'Nearest by straight-line distance.';
        $route = null;
        $best = null;
        try {
            $matrix = $routes->matrix($origin, $shortlist, $profile);
            foreach ($matrix as $index => $measurement) {
                if ($measurement['distance'] === null) {
                    continue;
                }
                if ($best === null || $measurement['distance'] < $best['distance']) {
                    $best = ['index' => $index, 'distance' => $measurement['distance'], 'duration' => $measurement['duration']];
                }
            }
            if ($best !== null) {
                $choice = $shortlist[$best['index']];
                $method = 'route-shortlist';
                $summary = 'Closest route among nearby listed sites.';
            }
        } catch (RuntimeException) {
            $choice = $shortlist[0];
            $method = 'straight-line';
            $summary = 'Nearest by straight-line distance.';
        }
        if ($method === 'route-shortlist' && $best !== null) {
            try {
                $route = $routes->directions($origin, $choice['geometry']['coordinates'], $profile);
            } catch (RuntimeException) {
                $route = [
                    'profile' => $profile,
                    'distance' => $best['distance'],
                    'duration' => $best['duration'],
                    'geometry' => null,
                    'steps' => [],
                    'message' => 'The route line is unavailable.',
                ];
            }
        }
        unset($choice['straight_line_meters']);

        return response()->json([
            'site' => $choice,
            'method' => $method,
            'summary' => $summary,
            'search_area' => $searchArea,
            'restricted_to_barangay' => $restricted,
            'live_closures' => false,
            'closure_note' => 'Live road-closure data is not available.',
            'candidates' => count($shortlist),
            'straight_line_meters' => round(EvacuationSites::meters($origin[0], $origin[1], (float) $choice['geometry']['coordinates'][0], (float) $choice['geometry']['coordinates'][1])),
            'route' => $route,
            'client_token' => $request->input('client_token'),
        ]);
    }

    public function route(Request $request, MapboxRouteClient $routes): JsonResponse
    {
        $origin = $this->origin($request);
        $profile = $this->profile($request);
        $destination = $request->validate([
            'destination' => ['required', 'array', 'size:2'],
            'destination.0' => ['required', 'numeric', 'between:-180,180'],
            'destination.1' => ['required', 'numeric', 'between:-90,90'],
        ]);
        $listed = $this->listedSites();
        if ($listed instanceof JsonResponse) {
            return $listed;
        }
        $match = EvacuationSites::matching($listed, (float) $destination['destination'][0], (float) $destination['destination'][1]);
        if ($match === null) {
            return response()->json(['message' => 'Choose a listed evacuation site before requesting directions.'], 422);
        }
        try {
            $route = $routes->directions($origin, $match['geometry']['coordinates'], $profile);
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'client_token' => $request->input('client_token'),
            ], $exception->getMessage() === 'No route was returned for this site.' ? 422 : 503);
        }

        return response()->json([
            ...$route,
            'site_id' => $match['id'],
            'live_closures' => false,
            'closure_note' => 'Live road-closure data is not available.',
            'advisory' => 'Suggested route only. Road conditions and site availability may change. Follow CDRRMO instructions.',
            'client_token' => $request->input('client_token'),
        ]);
    }

    public function boundaries(): StreamedResponse|JsonResponse
    {
        return $this->file('barangay-boundaries.geojson', 'Barangay boundaries are not available.');
    }

    public function rivers(): StreamedResponse|JsonResponse
    {
        return $this->file('rivers.geojson', 'Rivers are not available.');
    }

    public function show(string $layer): StreamedResponse|JsonResponse
    {
        if (ResidentHazardCatalog::find($layer) === null || ! Storage::disk('local')->exists('resident-hazards/'.$layer.'.geojson')) {
            return response()->json(['message' => 'This hazard layer is not available.'], 404);
        }

        return $this->file($layer.'.geojson', 'This hazard layer is not available.');
    }

    private function file(string $name, string $message): StreamedResponse|JsonResponse
    {
        if (! Storage::disk('local')->exists('resident-hazards/'.$name)) {
            return response()->json(['message' => $message], 404);
        }

        return Storage::disk('local')->response('resident-hazards/'.$name, $name, [
            'Content-Type' => 'application/geo+json',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * @return list<array<string, mixed>>|JsonResponse
     */
    private function listedSites(): array|JsonResponse
    {
        $collection = $this->criticalCollection();
        if ($collection === null) {
            return response()->json(['message' => EvacuationSites::MISSING], 404);
        }
        $listed = EvacuationSites::onlyListed($collection);
        if ($listed['features'] === []) {
            return response()->json(['message' => EvacuationSites::MISSING], 404);
        }

        return $listed['features'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function criticalCollection(): ?array
    {
        if (! Storage::disk('local')->exists('resident-hazards/critical-points.geojson')) {
            return null;
        }
        $collection = json_decode((string) Storage::disk('local')->get('resident-hazards/critical-points.geojson'), true);

        return is_array($collection) ? $collection : null;
    }

    /**
     * @return array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}}|null
     */
    private function coverageBounds(): ?array
    {
        if (! Storage::disk('local')->exists('resident-hazards/catalog.json')) {
            return null;
        }
        $catalog = json_decode((string) Storage::disk('local')->get('resident-hazards/catalog.json'), true);
        $bounds = is_array($catalog) ? ($catalog['initial_bounds'] ?? null) : null;

        return is_array($bounds) && isset($bounds[0][0], $bounds[1][1]) ? $bounds : null;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function origin(Request $request): array
    {
        $origin = $request->validate([
            'origin' => ['required', 'array', 'size:2'],
            'origin.0' => ['required', 'numeric', 'between:-180,180'],
            'origin.1' => ['required', 'numeric', 'between:-90,90'],
            'client_token' => ['nullable', 'integer'],
        ]);

        return [(float) $origin['origin'][0], (float) $origin['origin'][1]];
    }

    private function profile(Request $request): string
    {
        $profile = $request->validate([
            'profile' => ['required', 'in:walking,driving'],
        ])['profile'];

        return $profile;
    }

    private function assignedPsgc(Request $request): ?string
    {
        $barangayId = $request->user()?->barangay_id;
        if (! $barangayId || ! Schema::hasTable('barangays')) {
            return null;
        }
        $code = DB::table('barangays')->where('id', $barangayId)->value('barangay_code');

        return is_string($code) && $code !== '' ? $code : null;
    }
}
