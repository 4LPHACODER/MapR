<?php

use App\Http\Middleware\ApplyFieldScope;
use App\Http\Middleware\EnsureRole;
use App\Support\RoleHome;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(function () {
            $user = auth()->user();

            return $user ? (RoleHome::home($user) ?? '/') : '/';
        });
        $middleware->alias([
            'role' => EnsureRole::class,
            'field.scope' => ApplyFieldScope::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
