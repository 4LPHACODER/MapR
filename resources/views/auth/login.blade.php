@extends('layouts.mapr')

@section('title', 'Sign in — MapR')
@section('description', 'Sign in to access your MapR assessment workspace.')

@push('head')
    @vite('resources/js/login.js')
@endpush

@section('content')
    <main class="login-shell">
        <div class="login-card">
            <div class="login-masthead">
                <img class="login-art" src="/images/mapr-hero-assessment.svg" alt="" width="1200" height="900">
                <img class="login-logo" src="/images/mapr-logo.png" alt="MapR" width="56" height="56">
            </div>

            <div class="login-content">
                <h1>Welcome back to MapR</h1>
                <p class="login-lead">Sign in to access your assessment workspace.</p>

                <div
                    id="login-app"
                    data-action="{{ route('login.store') }}"
                    data-email="{{ is_string(old('email')) ? old('email') : '' }}"
                    data-remember="{{ in_array(old('remember'), [true, 1, '1'], true) ? '1' : '0' }}"
                    data-email-error="{{ $errors->first('email') }}"
                    data-password-error="{{ $errors->first('password') }}"
                    data-remember-error="{{ $errors->first('remember') }}"
                ></div>

                <noscript><p>Enable JavaScript to sign in.</p></noscript>

                <div class="login-footer">
                    <a href="{{ route('home') }}">
                        <i class="pi pi-arrow-left" aria-hidden="true"></i>
                        Back to home
                    </a>
                </div>
            </div>
        </div>
    </main>
@endsection
