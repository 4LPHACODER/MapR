<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>@yield('title', 'MapR')</title>
        @hasSection('description')
            <meta name="description" content="@yield('description')">
        @endif
        <link rel="icon" href="/images/mapr-logo.png" type="image/png">
        <link rel="manifest" href="/manifest.webmanifest">
        <link rel="apple-touch-icon" href="/images/mapr-icon-180.png">
        <meta name="theme-color" content="#123b70">
        @vite(['resources/css/app.css', 'resources/js/pwa.js'])
        @stack('head')
    </head>
    <body>
        @yield('content')
        @stack('scripts')
    </body>
</html>
