<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Dashboard - ShepardOne</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600&display=swap" rel="stylesheet">

    <!-- Styles -->
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <style>
            /*! tailwindcss v4.0.7 | MIT License | https://tailwindcss.com */
            body {
                font-family: 'Figtree', sans-serif;
                background-color: #fdfdfc;
                color: #1b1b18;
            }
        </style>
    @endif
</head>
<body>
    <script>
        window.__AUTH_USER__ = {{ Illuminate\Support\Js::from(Auth::user()) }};
    </script>
    <div id="app"></div>
</body>
</html>