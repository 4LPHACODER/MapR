@extends('layouts.mapr')

@section('title', 'Sign in — MapR')
@section('description', 'Sign in to access your MapR assessment workspace.')

@section('content')
    <div class="login-shell">
        <main class="login-panel">
            <div class="login-card">
                <a class="login-logo" href="{{ route('home') }}">
                    <img src="/images/mapr-logo.png" alt="MapR" width="288" height="287">
                </a>

                <h1>Welcome back to MapR</h1>
                <p class="login-lead">Sign in to access your assessment workspace.</p>

                <form method="POST" action="{{ route('login.store') }}" id="login-form">
                    @csrf

                    <div class="field">
                        <label for="email">Email address</label>
                        <input
                            id="email"
                            name="email"
                            type="email"
                            value="{{ old('email') }}"
                            autocomplete="email"
                            inputmode="email"
                            required
                            autofocus
                            @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                        >
                        @error('email')
                            <p id="email-error" class="field-error" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="field">
                        <label for="password">Password</label>
                        <div class="password-field">
                            <input
                                id="password"
                                name="password"
                                type="password"
                                autocomplete="current-password"
                                required
                                @error('email') aria-invalid="true" @enderror
                            >
                            <button
                                type="button"
                                id="toggle-password"
                                class="password-toggle"
                                aria-controls="password"
                                aria-pressed="false"
                                aria-label="Show password"
                            >
                                <svg class="icon-eye" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/>
                                    <circle cx="12" cy="12" r="3"/>
                                </svg>
                                <svg class="icon-eye-off" hidden xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/>
                                    <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/>
                                    <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/>
                                    <line x1="2" x2="22" y1="2" y2="22"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <label class="remember">
                        <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                        <span>Remember me</span>
                    </label>

                    <button type="submit" class="mapr-button mapr-button-primary login-submit" id="login-submit">
                        Sign In
                    </button>
                </form>

                <a class="back-home" href="{{ route('home') }}">Back to home</a>
            </div>
        </main>
    </div>
@endsection

@push('scripts')
    <script>
        const form = document.getElementById('login-form');
        const password = document.getElementById('password');
        const toggle = document.getElementById('toggle-password');
        const submit = document.getElementById('login-submit');
        const iconEye = toggle.querySelector('.icon-eye');
        const iconEyeOff = toggle.querySelector('.icon-eye-off');

        toggle.addEventListener('click', () => {
            const visible = password.type === 'text';
            password.type = visible ? 'password' : 'text';
            iconEye.hidden = !visible;
            iconEyeOff.hidden = visible;
            toggle.setAttribute('aria-pressed', visible ? 'false' : 'true');
            toggle.setAttribute('aria-label', visible ? 'Show password' : 'Hide password');
            password.focus();
        });

        form.addEventListener('submit', () => {
            submit.disabled = true;
            submit.textContent = 'Signing in…';
        });
    </script>
@endpush
