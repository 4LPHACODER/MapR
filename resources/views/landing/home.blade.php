@extends('layouts.mapr')

@section('title', 'MapR — Building Damage Classification and Geospatial Resident Impact Estimation')
@section('description', 'MapR brings resident reports, building damage assessments, and geographic records together to support informed disaster-response decisions in Tandag City.')

@push('head')
    <link rel="preload" as="image" href="/images/hero-1.webp" fetchpriority="high">
@endpush

@section('content')
    <div
        id="app"
        data-sign-in-url="{{ route('login') }}"
        data-dashboard-url="{{ $dashboardUrl ?? '' }}"
    ></div>
    <noscript>
        <p>MapR brings resident reports, building damage assessments, and geographic records together to support informed disaster-response decisions in Tandag City, Surigao del Sur.</p>
        <p><a href="{{ route('login') }}">Sign in</a></p>
    </noscript>
@endsection

@push('scripts')
    @vite(['resources/js/app.js'])
@endpush
