<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureApiUserIsActive;
use App\Http\Middleware\EnsureCanViewBilling;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetLocale::class,
            EnsureUserIsActive::class,
        ]);

        $middleware->api(append: [
            EnsureApiUserIsActive::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'telegram/webhook',
            'mcp',
            'mcp/*',
        ]);

        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'billing' => EnsureCanViewBilling::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('mcp') || $request->is('mcp/*'),
        );
    })->create();
