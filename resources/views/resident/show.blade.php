@extends('layouts.mapr')

@section('title', $title.' — MapR')

@section('content')
    <div class="workspace-shell">
        <header class="workspace-bar">
            <a href="{{ route('home') }}">
                <img src="/images/mapr-logo.png" alt="MapR" width="288" height="287">
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="mapr-button mapr-button-secondary">Log out</button>
            </form>
        </header>

        <main class="workspace-card">
            <p class="workspace-kicker">Signed in</p>
            <h1>{{ $title }}</h1>
            <p class="workspace-note">This workspace is not built yet. Assessment records and summary figures are not shown here.</p>

            <dl class="workspace-meta">
                <div>
                    <dt>Name</dt>
                    <dd>{{ $user->display_name }}</dd>
                </div>
                <div>
                    <dt>Email</dt>
                    <dd>{{ $user->email }}</dd>
                </div>
                <div>
                    <dt>Role</dt>
                    <dd>{{ $user->role?->name }}</dd>
                </div>
            </dl>
        </main>
    </div>
@endsection
