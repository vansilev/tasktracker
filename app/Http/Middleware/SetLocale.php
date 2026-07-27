<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = Auth::user()?->locale
            ?? $request->session()->get('locale')
            ?? config('tasktracker.default_locale', 'ru');

        if (! in_array($locale, ['ru', 'uk', 'en'], true)) {
            $locale = config('tasktracker.default_locale', 'ru');
        }

        App::setLocale($locale);

        return $next($request);
    }
}
