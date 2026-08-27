<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\TaskAttachmentController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::redirect('/', '/login');

Route::post('telegram/webhook', TelegramWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('telegram.webhook');

Volt::route('dashboard', 'pages.dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Volt::route('tasks', 'pages.tasks.index')->name('tasks.index');
    Volt::route('tasks/create', 'pages.tasks.create')->name('tasks.create');
    Volt::route('tasks/{task}', 'pages.tasks.show')->name('tasks.show');

    Route::middleware('billing')->group(function () {
        Volt::route('billing', 'pages.billing.index')->name('billing.index');
        Route::redirect('billing/create', '/billing')->name('billing.create');
        Route::get('billing/{item}', function (\App\Models\BillingItem $item) {
            return redirect()->route('billing.index', ['item' => $item->id]);
        })->name('billing.show');
    });

    Route::post('tasks/{task}/attachments/inline', [TaskAttachmentController::class, 'storeInline'])
        ->middleware('throttle:30,1')
        ->name('tasks.attachments.inline');
    Route::post('pending-attachments/inline', [TaskAttachmentController::class, 'storePendingInline'])
        ->middleware('throttle:30,1')
        ->name('pending.attachments.inline');
    Route::get('pending-attachments/{pending}/download', [TaskAttachmentController::class, 'downloadPending'])
        ->name('pending.attachments.download');
    Route::get('pending-attachments/{pending}/view', [TaskAttachmentController::class, 'viewPending'])
        ->name('pending.attachments.view');
    Route::get('tasks/attachments/{attachment}/download', [TaskAttachmentController::class, 'download'])
        ->name('tasks.attachments.download');
    Route::get('tasks/attachments/{attachment}/view', [TaskAttachmentController::class, 'view'])
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
