<?php

namespace App\Support;

use App\Models\Auth\User;
use Illuminate\Http\Request;

class RoleHome
{
    public const PREFIXES = [
        'resident' => '/resident',
        'bdrrm' => '/bdrrm',
        'rdana' => '/rdana',
        'cdrrmo_admin' => '/cdrrmo',
    ];

    public const TITLES = [
        'resident' => 'Resident workspace',
        'bdrrm' => 'BDRRM workspace',
        'rdana' => 'RDANA workspace',
        'cdrrmo_admin' => 'CDRRMO workspace',
    ];

    public static function home(User $user): ?string
    {
        $user->loadMissing('role');

        return self::PREFIXES[$user->role?->code] ?? null;
    }

    public static function title(User $user): string
    {
        $user->loadMissing('role');

        return self::TITLES[$user->role?->code] ?? 'Workspace';
    }

    public static function redirectPath(User $user, Request $request): string
    {
        $home = self::home($user) ?? '/';
        $intended = $request->session()->pull('url.intended');

        if (! is_string($intended) || $intended === '' || str_starts_with($intended, '//')) {
            return $home;
        }

        $parts = parse_url($intended);

        if ($parts === false) {
            return $home;
        }

        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : null;

        if ($scheme !== null && ! in_array($scheme, ['http', 'https'], true)) {
            return $home;
        }

        if (isset($parts['host']) && strcasecmp((string) $parts['host'], $request->getHost()) !== 0) {
            return $home;
        }

        $path = $parts['path'] ?? '/';

        if (! str_starts_with($path, '/') || ! self::allows($user, $path)) {
            return $home;
        }

        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $path.$query;
    }

    public static function allows(User $user, string $path): bool
    {
        $prefix = self::home($user);

        if ($prefix === null) {
            return false;
        }

        return $path === $prefix || str_starts_with($path, $prefix.'/');
    }
}
