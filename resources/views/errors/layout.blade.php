<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">

        <title>@yield('title') · {{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" href="{{ asset('favicon.png') }}" type="image/png">
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css'])
    </head>
    <body class="font-sans text-gray-900 antialiased bg-gray-50">
        <main class="min-h-screen flex items-center justify-center px-4 py-12">
            <div class="w-full max-w-md text-center">
                <a href="{{ url('/') }}" class="inline-flex mb-8" aria-label="{{ config('app.name') }}">
                    <x-application-logo class="h-12 w-12" />
                </a>
                <p class="text-sm font-medium text-gray-600">{{ __('Error :code', ['code' => trim($__env->yieldContent('code'))]) }}</p>
                <h1 class="mt-2 text-2xl font-semibold text-gray-900">@yield('title')</h1>
                <p class="mt-3 text-sm text-gray-600">@yield('message')</p>
                <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
                    <a href="{{ url('/dashboard') }}" class="inline-flex items-center justify-center px-4 py-2 bg-indigo-600 rounded-lg font-medium text-sm text-white hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 transition-colors">{{ __('Go to dashboard') }}</a>
                    <a href="javascript:history.back()" class="inline-flex items-center justify-center px-4 py-2 bg-white border border-gray-300 rounded-lg font-medium text-sm text-gray-700 hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 transition-colors">{{ __('Back') }}</a>
                </div>
            </div>
        </main>
    </body>
</html>
