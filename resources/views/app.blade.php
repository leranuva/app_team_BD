<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'Blue Draft') }}</title>
        <link rel="icon" href="{{ asset('brand/favicon.ico') }}">
        <link rel="apple-touch-icon" href="{{ asset('brand/icon-192.png') }}">
        <link rel="manifest" href="{{ asset('manifest.json') }}">
        <meta name="theme-color" content="#003366">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @viteReactRefresh
        @php($pageEntry = "resources/js/Pages/{$page['component']}")
        @vite(['resources/js/app.jsx', is_file(resource_path("js/Pages/{$page['component']}.tsx")) ? "{$pageEntry}.tsx" : "{$pageEntry}.jsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
