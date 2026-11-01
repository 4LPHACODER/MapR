@extends('layouts.mapr')

@section('title', 'MapR Assessor — MapR')

@push('head')
    <meta name="csrf-token" content="{{ csrf_token() }}">
@endpush

@section('content')
    <div
        id="rdana-app"
        data-user-name="{{ $user?->display_name }}"
        data-user-email="{{ $user?->email }}"
        data-job-title="{{ $user?->job_title ?: $roleName }}"
        data-role="rdana"
        data-mapbox-token="{{ $mapboxToken }}"
        data-mapbox-style="{{ $mapboxStyle }}"
        data-flood-classes="{{ json_encode($floodClasses) }}"
        data-logout-url="{{ route('logout') }}"
        data-home-url="{{ route('home') }}"
    ></div>
@endsection

@push('scripts')
    @vite(['resources/js/rdana.js'])
@endpush
