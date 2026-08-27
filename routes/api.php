<?php

use App\Http\Controllers\Api\V1\AuthTokenController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\TaskController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/token', [AuthTokenController::class, 'store'])
        ->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [MeController::class, 'show']);
        Route::get('catalogs', [MeController::class, 'catalogs']);
        Route::get('users', [MeController::class, 'users']);

        Route::get('tasks', [TaskController::class, 'index']);
        Route::post('tasks', [TaskController::class, 'store']);
        Route::get('tasks/{number}', [TaskController::class, 'show'])->whereNumber('number');
        Route::post('tasks/{number}/comments', [TaskController::class, 'comment'])->whereNumber('number');
        Route::post('tasks/{number}/transition', [TaskController::class, 'transition'])->whereNumber('number');
    });
});
