<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MapboxRouteClient
{
    public function directions(array $origin, array $destination, string $profile): array
    {
        $this->profile($profile);
        $path = $this->pair($origin).';'.$this->pair($destination);
        $payload = $this->get("https://api.mapbox.com/directions/v5/mapbox/{$profile}/{$path}", [
            'geometries' => 'geojson',
            'overview' => 'full',
            'steps' => 'true',
        ]);
        $route = $payload['routes'][0] ?? null;
        $coordinates = $route['geometry']['coordinates'] ?? null;
        if (! is_array($route) || ! is_array($coordinates) || count($coordinates) < 2) {
            throw new RuntimeException('No route was returned for this site.');
        }

        return [
            'profile' => $profile,
            'distance' => (float) $route['distance'],
            'duration' => (float) $route['duration'],
            'geometry' => ['type' => 'LineString', 'coordinates' => $coordinates],
            'steps' => $this->steps($route),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $destinations
     * @return list<array{distance: float|null, duration: float|null}>
     */
    public function matrix(array $origin, array $destinations, string $profile): array
    {
        $this->profile($profile);
        $pairs = [$this->pair($origin)];
        foreach ($destinations as $destination) {
            $pairs[] = $this->pair($destination['geometry']['coordinates']);
        }
        $payload = $this->get('https://api.mapbox.com/directions-matrix/v1/mapbox/'.$profile.'/'.implode(';', $pairs), [
            'sources' => '0',
            'annotations' => 'distance,duration',
        ]);
        $distances = $payload['distances'][0] ?? null;
        $durations = $payload['durations'][0] ?? null;
        if (! is_array($distances) || count($distances) !== count($destinations) + 1) {
            throw new RuntimeException('Directions are unavailable right now.');
        }
        $results = [];
        foreach ($destinations as $index => $destination) {
            $distance = $distances[$index + 1] ?? null;
            $duration = is_array($durations) ? ($durations[$index + 1] ?? null) : null;
            $results[] = [
                'distance' => is_numeric($distance) ? (float) $distance : null,
                'duration' => is_numeric($duration) ? (float) $duration : null,
            ];
        }

        return $results;
    }

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function get(string $url, array $query): array
    {
        $token = config('mapr.mapbox.token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Directions are not available in this environment.');
        }
        try {
            $response = Http::timeout(12)->acceptJson()->get($url, [...$query, 'access_token' => $token]);
        } catch (ConnectionException) {
            throw new RuntimeException('Directions are unavailable right now.');
        }
        if (! $response->successful()) {
            throw new RuntimeException('Directions are unavailable right now.');
        }
        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    private function profile(string $profile): void
    {
        if (! in_array($profile, ['walking', 'driving'], true)) {
            throw new RuntimeException('That travel mode is not supported.');
        }
    }

    /**
     * @param  array{0: float|int, 1: float|int}  $coordinates
     */
    private function pair(array $coordinates): string
    {
        return round((float) $coordinates[0], 6).','.round((float) $coordinates[1], 6);
    }

    /**
     * @param  array<string, mixed>  $route
     * @return list<array{instruction: string, distance: float, duration: float}>
     */
    private function steps(array $route): array
    {
        $steps = [];
        foreach ($route['legs'][0]['steps'] ?? [] as $step) {
            $instruction = trim((string) ($step['maneuver']['instruction'] ?? ''));
            if ($instruction === '') {
                continue;
            }
            $steps[] = [
                'instruction' => $instruction,
                'distance' => (float) ($step['distance'] ?? 0),
                'duration' => (float) ($step['duration'] ?? 0),
            ];
            if (count($steps) === 40) {
                break;
            }
        }

        return $steps;
    }
}
