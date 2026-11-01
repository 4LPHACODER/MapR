<?php

namespace Tests\Feature;

use App\Models\Auth\User;
use App\Services\ResidentHazardExporter;
use App\Support\EvacuationSites;
use App\Support\ResidentHazardCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesMaprAuthSchema;
use Tests\TestCase;

class ResidentHazardMapTest extends TestCase
{
    use CreatesMaprAuthSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMaprAuthSchema();
        Storage::fake('local');
    }

    public function test_a_resident_receives_the_catalog_and_one_cached_layer(): void
    {
        Storage::disk('local')->put('resident-hazards/catalog.json', json_encode([
            'note' => ResidentHazardCatalog::NOTE,
            'initial_bounds' => [[126.1, 9.0], [126.3, 9.2]],
            'layers' => [[
                'id' => 'flood',
                'name' => 'Flood',
                'source' => 'geospatial_data/CDRA Shapefiles/Hazard/FLOOD.shp',
                'date' => 'Date not provided',
                'bounds' => [[126.1, 9.0], [126.3, 9.2]],
                'legend' => [['value' => 'LF', 'label' => 'LF', 'color' => '#bfdbfe']],
            ]],
        ]));
        Storage::disk('local')->put('resident-hazards/flood.geojson', '{"type":"FeatureCollection","features":[]}');
        $resident = $this->user('resident', 'Map Resident');

        $catalog = $this->actingAs($resident)->getJson('/resident/api/map/hazards')->assertOk();
        $catalog->assertJsonPath('note', ResidentHazardCatalog::NOTE);
        $catalog->assertJsonPath('layers.0.legend.0.label', 'LF');
        $this->assertStringNotContainsString($resident->email, $catalog->getContent());

        $this->actingAs($resident)->get('/resident/api/map/hazards/flood')
            ->assertOk()
            ->assertHeader('content-type', 'application/geo+json');

        $this->actingAs($resident)->getJson('/resident/api/map/hazards/typhoon')
            ->assertNotFound()
            ->assertJsonPath('message', 'This hazard layer is not available.');
        $this->actingAs($resident)->getJson('/resident/api/map/hazards/multihazard')->assertNotFound();
    }

    public function test_critical_points_keep_public_fields_and_land_near_tandag(): void
    {
        $directory = storage_path('framework/testing/resident-hazards');
        $community = (new ResidentHazardExporter)->exportCommunity($directory);
        $collection = json_decode((string) file_get_contents($directory.'/critical-points.geojson'), true);
        $encoded = json_encode($collection);

        $this->assertSame(7, count($community['critical_points']['categories']));
        $this->assertStringNotContainsString('Population', $encoded);
        $this->assertStringNotContainsString('HHCBMS', $encoded);
        $this->assertStringNotContainsString('contact', strtolower($encoded));
        $this->assertStringNotContainsString('active evacuation center', strtolower($encoded));

        $school = collect($collection['features'])->first(fn (array $feature) => $feature['properties']['category'] === 'school');
        $evacuation = collect($collection['features'])->first(fn (array $feature) => $feature['properties']['category'] === 'evacuation');
        $this->assertNotNull($school);
        $this->assertNotNull($evacuation);
        $this->assertGreaterThan(125.9, $school['geometry']['coordinates'][0]);
        $this->assertLessThan(126.5, $school['geometry']['coordinates'][0]);
        $this->assertGreaterThan(8.8, $school['geometry']['coordinates'][1]);
        $this->assertLessThan(9.4, $school['geometry']['coordinates'][1]);
        $this->assertGreaterThan(125.9, $evacuation['geometry']['coordinates'][0]);
        $this->assertLessThan(126.5, $evacuation['geometry']['coordinates'][0]);
        $this->assertSame('This CDRA listing does not confirm that the site is currently active.', $evacuation['properties']['notice']);
        $this->assertArrayNotHasKey('source_barangay', $evacuation['properties']);
        $evacuationNames = collect($collection['features'])
            ->filter(fn (array $feature) => $feature['properties']['category'] === 'evacuation')
            ->map(fn (array $feature) => $feature['properties']['name']);
        $this->assertTrue($evacuationNames->contains('Barangay Dagocdoc Covered Court'));
        $this->assertFalse($evacuationNames->contains('Brgy Dagocdoc Health Station'));
        $healthStation = collect($collection['features'])->first(fn (array $feature) => $feature['properties']['name'] === 'Brgy Dagocdoc Health Station');
        $this->assertSame('health-station', $healthStation['properties']['category']);

        $boundaries = json_decode((string) file_get_contents($directory.'/barangay-boundaries.geojson'), true);
        $this->assertStringNotContainsString('HHCBMS', json_encode($boundaries));
        $this->assertNotEmpty($community['areas']);
    }

    public function test_place_routes_follow_the_same_resident_authorization(): void
    {
        Storage::disk('local')->put('resident-hazards/catalog.json', json_encode([
            'note' => ResidentHazardCatalog::NOTE,
            'layers' => [],
        ]));
        Storage::disk('local')->put('resident-hazards/critical-points.geojson', '{"type":"FeatureCollection","features":[{"type":"Feature","properties":{"name":"Mabua hall","category":"barangay-hall"},"geometry":{"type":"Point","coordinates":[126.2,9.07]}}]}');
        $barangayId = DB::table('barangays')->insertGetId([
            'barangay_code' => '166819008',
            'barangay_name' => 'Mabua',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $resident = $this->user('resident', 'Map Resident');
        $resident->barangay_id = $barangayId;
        $resident->save();

        $this->actingAs($resident)->getJson('/resident/api/map/hazards')
            ->assertOk()
            ->assertJsonPath('assigned_psgc', '166819008')
            ->assertJsonPath('coverage', 'Tandag City');
        $this->actingAs($resident)->get('/resident/api/map/critical-points')
            ->assertOk()
            ->assertHeader('content-type', 'application/geo+json');
        auth()->logout();
        $this->getJson('/resident/api/map/critical-points')->assertUnauthorized();
        $this->actingAs($this->user('bdrrm', 'Other Place'))
            ->getJson('/resident/api/map/boundaries')
            ->assertForbidden();
    }

    public function test_evacuation_sites_exclude_unlisted_facilities_and_private_fields(): void
    {
        Storage::disk('local')->put('resident-hazards/critical-points.geojson', json_encode([
            'type' => 'FeatureCollection',
            'features' => [
                $this->point('evacuation-1', 'Barangay Dagocdoc Covered Court', 'evacuation', [126.2, 9.07]),
                $this->point('health-1', 'Brgy Dagocdoc Health Station', 'health-station', [126.21, 9.08]),
                $this->point('school-1', 'Dagocdoc Elementary School', 'school', [126.22, 9.06]),
            ],
        ]));
        $resident = $this->user('resident', 'Evacuation Resident');

        $response = $this->actingAs($resident)->getJson('/resident/api/map/evacuation-sites')->assertOk();
        $response->assertJsonPath('features.0.properties.name', 'Barangay Dagocdoc Covered Court');
        $response->assertJsonPath('features.0.properties.availability', EvacuationSites::AVAILABILITY);
        $response->assertJsonMissingPath('message');
        $encoded = $response->getContent();
        $this->assertStringNotContainsString('Brgy Dagocdoc Health Station', $encoded);
        $this->assertStringNotContainsString('Dagocdoc Elementary School', $encoded);
        $this->assertStringNotContainsString('RISK_FL', $encoded);
        $this->assertStringNotContainsString('Population', $encoded);
        $this->assertCount(1, $response->json('features'));

        Storage::disk('local')->put('resident-hazards/critical-points.geojson', json_encode([
            'type' => 'FeatureCollection',
            'features' => [$this->point('health-1', 'Brgy Dagocdoc Health Station', 'health-station', [126.21, 9.08])],
        ]));
        $this->actingAs($resident)->getJson('/resident/api/map/evacuation-sites')
            ->assertOk()
            ->assertJsonPath('message', EvacuationSites::MISSING)
            ->assertJsonCount(0, 'features');
    }

    public function test_nearest_site_uses_citywide_listed_sites_and_handles_routing_failures(): void
    {
        config(['mapr.mapbox.token' => 'test-token']);
        Storage::disk('local')->put('resident-hazards/catalog.json', json_encode([
            'initial_bounds' => [[126.0, 8.9], [126.4, 9.2]],
        ]));
        Storage::disk('local')->put('resident-hazards/critical-points.geojson', json_encode([
            'type' => 'FeatureCollection',
            'features' => [
                $this->point('health-1', 'Brgy Dagocdoc Health Station', 'health-station', [126.201, 9.061]),
                $this->point('evacuation-near', 'Nearby covered court', 'evacuation', [126.205, 9.065]),
                $this->point('evacuation-far', 'Farther barangay hall', 'evacuation', [126.28, 9.12]),
            ],
        ]));
        $resident = $this->user('resident', 'Nearest Resident');
        $origin = ['origin' => [126.2, 9.06], 'profile' => 'walking', 'client_token' => 7];

        Http::fake([
            'https://api.mapbox.com/*' => Http::sequence()
                ->push(['code' => 'Ok', 'distances' => [[0, 4000, 700]], 'durations' => [[0, 3000, 500]]])
                ->push(['routes' => [[
                    'distance' => 700,
                    'duration' => 500,
                    'geometry' => ['type' => 'LineString', 'coordinates' => [[126.2, 9.06], [126.28, 9.12]]],
                    'legs' => [['steps' => [['distance' => 700, 'duration' => 500, 'maneuver' => ['instruction' => 'Walk north']]]]],
                ]]])
                ->push(['message' => 'unavailable'], 503)
                ->push(['message' => 'unavailable'], 503),
        ]);
        $routed = $this->actingAs($resident)->postJson('/resident/api/map/nearest-site', $origin)->assertOk();
        $routed->assertJsonPath('site.id', 'evacuation-far');
        $routed->assertJsonPath('method', 'route-shortlist');
        $routed->assertJsonPath('summary', 'Closest route among nearby listed sites.');
        $routed->assertJsonPath('search_area', 'Tandag City');
        $routed->assertJsonPath('restricted_to_barangay', false);
        $routed->assertJsonPath('live_closures', false);
        $routed->assertJsonPath('route.distance', 700);
        $routed->assertJsonPath('route.duration', 500);
        $routed->assertJsonPath('client_token', 7);
        $this->assertStringNotContainsString('Brgy Dagocdoc Health Station', $routed->getContent());

        $fallback = $this->actingAs($resident)->postJson('/resident/api/map/nearest-site', $origin)->assertOk();
        $fallback->assertJsonPath('site.id', 'evacuation-near');
        $fallback->assertJsonPath('method', 'straight-line');
        $fallback->assertJsonPath('summary', 'Nearest by straight-line distance.');
        $fallback->assertJsonPath('route', null);

        $failed = $this->actingAs($resident)->postJson('/resident/api/map/routes', [
            'origin' => [126.2, 9.06],
            'destination' => [126.205, 9.065],
            'profile' => 'walking',
            'client_token' => 8,
        ])->assertStatus(503);
        $failed->assertJsonPath('message', 'Directions are unavailable right now.');
        $failed->assertJsonPath('client_token', 8);
        $this->assertArrayNotHasKey('geometry', $failed->json());

        $this->actingAs($resident)->postJson('/resident/api/map/routes', [
            'origin' => [126.2, 9.06],
            'destination' => [126.201, 9.061],
            'profile' => 'walking',
        ])->assertStatus(422)->assertJsonPath('message', 'Choose a listed evacuation site before requesting directions.');

        auth()->logout();
        $this->postJson('/resident/api/map/nearest-site', $origin)->assertUnauthorized();
        $this->actingAs($this->user('bdrrm', 'Other Router'))
            ->postJson('/resident/api/map/routes', [
                'origin' => [126.2, 9.06],
                'destination' => [126.205, 9.065],
                'profile' => 'walking',
            ])->assertForbidden();
    }

    public function test_residents_from_different_barangays_receive_the_same_public_sites(): void
    {
        Storage::disk('local')->put('resident-hazards/catalog.json', json_encode([
            'note' => ResidentHazardCatalog::NOTE,
            'layers' => [],
            'coverage' => 'Tandag City',
        ]));
        Storage::disk('local')->put('resident-hazards/critical-points.geojson', json_encode([
            'type' => 'FeatureCollection',
            'features' => [
                $this->point('evacuation-mabua', 'Barangay Covered Court', 'evacuation', [126.2, 9.07]),
                $this->point('evacuation-dagocdoc', 'Barangay Dagocdoc Covered Court', 'evacuation', [126.25, 9.04]),
                $this->point('health-1', 'Brgy Dagocdoc Health Station', 'health-station', [126.21, 9.08]),
            ],
        ]));
        $mabua = DB::table('barangays')->insertGetId([
            'barangay_code' => '166819008',
            'barangay_name' => 'Mabua',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $dagocdoc = DB::table('barangays')->insertGetId([
            'barangay_code' => '166819005',
            'barangay_name' => 'Dagocdoc',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $mabuaResident = $this->user('resident', 'Mabua Resident');
        $mabuaResident->barangay_id = $mabua;
        $mabuaResident->save();
        $dagocdocResident = $this->user('resident', 'Dagocdoc Resident');
        $dagocdocResident->barangay_id = $dagocdoc;
        $dagocdocResident->save();

        $left = $this->actingAs($mabuaResident)->getJson('/resident/api/map/evacuation-sites')->assertOk();
        $right = $this->actingAs($dagocdocResident)->getJson('/resident/api/map/evacuation-sites')->assertOk();
        $this->assertSame($left->json('features'), $right->json('features'));
        $this->assertSame(['Barangay Covered Court', 'Barangay Dagocdoc Covered Court'], collect($left->json('features'))->pluck('properties.name')->all());
        $this->assertStringNotContainsString('Brgy Dagocdoc Health Station', $left->getContent());
        $this->actingAs($mabuaResident)->getJson('/resident/api/map/hazards')->assertJsonPath('assigned_psgc', '166819008');
        $this->actingAs($dagocdocResident)->getJson('/resident/api/map/hazards')->assertJsonPath('assigned_psgc', '166819005');
        $this->actingAs($mabuaResident)->getJson('/resident/api/map/hazards/none')->assertNotFound();
    }

    public function test_nearest_site_can_be_limited_to_an_explicit_area(): void
    {
        config(['mapr.mapbox.token' => 'test-token']);
        Storage::disk('local')->put('resident-hazards/catalog.json', json_encode([
            'initial_bounds' => [[126.0, 8.9], [126.4, 9.2]],
        ]));
        Storage::disk('local')->put('resident-hazards/critical-points.geojson', json_encode([
            'type' => 'FeatureCollection',
            'features' => [
                $this->point('health-1', 'Brgy Dagocdoc Health Station', 'health-station', [126.201, 9.061]),
                $this->point('evacuation-near', 'Nearby covered court', 'evacuation', [126.205, 9.065]),
                $this->point('evacuation-far', 'Farther barangay hall', 'evacuation', [126.28, 9.12]),
            ],
        ]));
        $resident = $this->user('resident', 'Area Resident');
        Http::fake([
            'https://api.mapbox.com/*' => Http::sequence()
                ->push(['code' => 'Ok', 'distances' => [[0, 900]], 'durations' => [[0, 400]]])
                ->push(['routes' => [[
                    'distance' => 900,
                    'duration' => 400,
                    'geometry' => ['type' => 'LineString', 'coordinates' => [[126.2, 9.06], [126.28, 9.12]]],
                    'legs' => [['steps' => [['maneuver' => ['instruction' => 'Walk east']]]]],
                ]]]),
        ]);

        $limited = $this->actingAs($resident)->postJson('/resident/api/map/nearest-site', [
            'origin' => [126.2, 9.06],
            'profile' => 'walking',
            'client_token' => 3,
            'site_ids' => ['evacuation-far', 'not-a-site'],
            'area_name' => 'Mabua',
        ])->assertOk();
        $limited->assertJsonPath('site.id', 'evacuation-far');
        $limited->assertJsonPath('restricted_to_barangay', true);
        $limited->assertJsonPath('search_area', 'Mabua');
        $limited->assertJsonPath('method', 'route-shortlist');
        $this->assertStringNotContainsString('Nearby covered court', $limited->getContent());

        $this->actingAs($resident)->postJson('/resident/api/map/nearest-site', [
            'origin' => [126.2, 9.06],
            'profile' => 'walking',
            'site_ids' => ['not-a-site', 'health-1'],
            'area_name' => 'Mabua',
        ])->assertStatus(422)->assertJsonPath('message', 'No listed evacuation sites are in the selected area.');
    }

    public function test_hazard_routes_reject_guests_and_other_roles(): void
    {
        $this->getJson('/resident/api/map/hazards')->assertUnauthorized();
        $this->actingAs($this->user('bdrrm', 'Other Role'))
            ->getJson('/resident/api/map/hazards')
            ->assertForbidden();
    }

    /**
     * @param  array{0: float, 1: float}  $coordinates
     * @return array<string, mixed>
     */
    private function point(string $id, string $name, string $category, array $coordinates): array
    {
        return [
            'type' => 'Feature',
            'id' => $id,
            'properties' => [
                'name' => $name,
                'category' => $category,
                'category_label' => $category,
                'source' => 'CDRA Critical Point intersect with MULTIHAZARD',
                'dataset_date' => '2021-07-22',
                'Population' => 99,
                'RISK_FL' => 'HIGH',
            ],
            'geometry' => ['type' => 'Point', 'coordinates' => $coordinates],
        ];
    }

    private function user(string $role, string $name): User
    {
        $roleId = DB::table('roles')->where('code', $role)->value('id');
        if ($roleId === null) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => ucfirst($role),
                'code' => $role,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        [$first, $rest] = array_pad(explode(' ', $name, 2), 2, 'User');

        return User::query()->create([
            'first_name' => $first,
            'last_name' => $rest,
            'email' => strtolower(str_replace(' ', '.', $name)).'@mapr.test',
            'password' => 'secret-pass',
            'account_status' => 'active',
            'role_id' => $roleId,
            'barangay_id' => null,
        ]);
    }
}
