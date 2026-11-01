<?php

namespace App\Http\Middleware;

use App\Services\AssignmentScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApplyFieldScope
{
    public function __construct(private readonly AssignmentScope $scope) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->user()?->loadMissing('role');
        $this->scope->apply($request);

        return $next($request);
    }
}
