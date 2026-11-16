<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex">

        <title>@yield('title') · {{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" href="{{ asset('favicon.png') }}" type="image/png">

        @include('partials.theme-init')
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
                    <x-button variant="primary" :href="url('/dashboard')">{{ __('Go to dashboard') }}</x-button>
                    <x-button variant="secondary" href="javascript:history.back()">{{ __('Back') }}</x-button>
                </div>
            </div>
        </main>
    </body>
</html>
