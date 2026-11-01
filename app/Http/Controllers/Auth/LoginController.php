<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Auth\User;
use App\Support\RoleHome;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->to(RoleHome::home($request->user()) ?? '/');
        }

        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'max:191'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $key = Str::transliterate(Str::lower($credentials['email']).'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many sign-in attempts. Please try again shortly.',
            ]);
        }

        $user = User::withTrashed()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => 'These credentials do not match our records.',
            ]);
        }

        if ($user->trashed() || $user->account_status !== 'active') {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => 'This account is not active.',
            ]);
        }

        $user->loadMissing('role');

        if (RoleHome::home($user) === null) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => 'This account cannot access MapR.',
            ]);
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        RateLimiter::clear($key);

        return redirect()->to(RoleHome::redirectPath($user, $request));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
