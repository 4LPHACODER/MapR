@extends('layouts.mapr')

@section('title', 'MapR Resident — MapR')

@push('head')
    <meta name="csrf-token" content="{{ csrf_token() }}">
@endpush

@section('content')
    <div
        id="resident-app"
        data-user-name="{{ $user?->display_name }}"
        data-user-email="{{ $user?->email }}"
        data-job-title="{{ $roleName }}"
        data-role="resident"
        data-mapbox-token="{{ $mapboxToken }}"
        data-mapbox-style="{{ $mapboxStyle }}"
        data-logout-url="{{ route('logout') }}"
        data-home-url="{{ route('home') }}"
    ></div>
@endsection

@push('scripts')
    @vite(['resources/js/resident.js'])
@endpush
