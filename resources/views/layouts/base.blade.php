<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', $site->name ?? 'Swash')</title>

    @if($fontsUrl ?? null)
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="{{ $fontsUrl }}">
    @endif

    <style id="swash-theme">{!! $themeCss ?? '' !!}</style>

    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @stack('head')
</head>
<body class="swash-body">
    @yield('body')
</body>
</html>
