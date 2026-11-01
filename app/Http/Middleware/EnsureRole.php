<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $code = $request->user()?->role?->code;

        if ($code === null || ! in_array($code, $roles, true)) {
            abort(403);
        }

        return $next($request);
    }
}
