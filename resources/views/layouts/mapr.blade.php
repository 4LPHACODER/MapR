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
        @vite(['resources/css/app.css'])
        @stack('head')
    </head>
    <body>
        @yield('content')
        @stack('scripts')
    </body>
</html>
