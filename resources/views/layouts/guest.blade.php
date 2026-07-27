<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" href="{{ asset('favicon.png') }}" type="image/png">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-gradient-to-br from-indigo-50 via-white to-gray-100">
            <div class="w-full sm:max-w-md px-4 sm:px-6">
                <div class="mb-4 text-center">
                    <a href="/" wire:navigate class="inline-flex flex-col items-center">
                        <x-application-logo class="h-12 w-12" />
                    </a>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-100 px-5 py-6 sm:px-6 sm:py-7">
                    {{ $slot }}
                </div>
            </div>
        </div>
    </body>
</html>
