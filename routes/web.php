<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::redirect('/', '/login');

Route::post('telegram/webhook', \App\Http\Controllers\TelegramWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('telegram.webhook');

Volt::route('dashboard', 'pages.dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Volt::route('tasks', 'pages.tasks.index')->name('tasks.index');
    Volt::route('tasks/create', 'pages.tasks.create')->name('tasks.create');
    Volt::route('tasks/{task}', 'pages.tasks.show')->name('tasks.show');

    Route::get('tasks/attachments/{attachment}/download', [\App\Http\Controllers\TaskAttachmentController::class, 'download'])
        ->name('tasks.attachments.download');
    Route::get('tasks/attachments/{attachment}/view', [\App\Http\Controllers\TaskAttachmentController::class, 'view'])
        ->name('tasks.attachments.view');
});

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::get('locale/{locale}', [LocaleController::class, 'switch'])
    ->name('locale.switch');

Route::middleware('guest')->group(function () {
    Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirect'])
        ->middleware('throttle:10,1')
        ->name('auth.google.redirect');

    Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])
        ->middleware('throttle:10,1')
        ->name('auth.google.callback');
});

require __DIR__.'/auth.php';
require __DIR__.'/admin.php';
