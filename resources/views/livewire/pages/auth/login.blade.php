<?php

use App\Livewire\Forms\LoginForm;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        Session::regenerate();

        $default = route('dashboard', absolute: false);
        $intended = Session::pull('url.intended', $default);
        $path = parse_url($intended, PHP_URL_PATH) ?: $intended;

        // Guest visits to /admin are stored as "intended"; non-admins must not land there after login.
        if (! auth()->user()->isAdmin() && str_starts_with($path, '/admin')) {
            $intended = $default;
        }

        // Full page redirect after session regenerate — avoids Livewire navigate CSRF/session races.
        $this->redirect($intended, navigate: false);
    }

    public function with(SettingsService $settings): array
    {
        return [
            'googleSsoEnabled' => (bool) $settings->get('google_sso_enabled'),
            'passwordLoginEnabled' => (bool) $settings->get('password_login_enabled', true),
        ];
    }
}; ?>

<div>
    <div class="mb-6 text-center">
        <h1 class="text-lg font-semibold text-gray-900">{{ config('app.name') }}</h1>
        <p class="text-xs text-gray-500 mt-1.5">{{ __('Corporate task tracker') }}</p>
    </div>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    @if ($googleSsoEnabled)
        <a href="{{ route('auth.google.redirect') }}"
           class="w-full inline-flex justify-center items-center gap-2 px-4 py-2 border border-gray-300 rounded-lg shadow-sm bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
            <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" aria-hidden="true">
                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.1z"/>
                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
            </svg>
            {{ __('Sign in with Google') }}
        </a>

        @if ($passwordLoginEnabled)
            <div class="relative my-4">
                <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                <div class="relative flex justify-center text-xs"><span class="px-3 bg-white text-gray-500">{{ __('or') }}</span></div>
            </div>
        @endif
    @elseif ($passwordLoginEnabled)
        <div class="mb-4 rounded-lg bg-indigo-50 border border-indigo-100 px-4 py-3 text-sm text-indigo-800">
            {{ __('Google sign-in will be available later. Use your corporate email and password.') }}
        </div>
    @endif

    @if ($passwordLoginEnabled)
        <form wire:submit="login" class="space-y-4">
            <div>
                <x-input-label for="email" :value="__('Email')" class="text-xs text-gray-500 font-medium" />
                <x-text-input wire:model="form.email" id="email" class="block mt-1 w-full rounded-lg text-sm" type="email" name="email" required autofocus autocomplete="username" placeholder="you@tcsavant.com" />
                <x-input-error :messages="$errors->get('form.email')" class="mt-1" />
            </div>

            <div>
                <x-input-label for="password" :value="__('Password')" class="text-xs text-gray-500 font-medium" />
                <x-text-input wire:model="form.password" id="password" class="block mt-1 w-full rounded-lg text-sm" type="password" name="password" required autocomplete="current-password" />
                <x-input-error :messages="$errors->get('form.password')" class="mt-1" />
            </div>

            <div class="flex items-center justify-between">
                <label for="remember" class="inline-flex items-center">
                    <input wire:model="form.remember" id="remember" type="checkbox" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:ring-indigo-500" name="remember">
                    <span class="ms-2 text-sm text-gray-600">{{ __('Remember me') }}</span>
                </label>
                @if (Route::has('password.request'))
                    <a class="text-sm text-indigo-600 hover:text-indigo-800" href="{{ route('password.request') }}" wire:navigate>
                        {{ __('Forgot your password?') }}
                    </a>
                @endif
            </div>

            <x-primary-button class="w-full justify-center">
                {{ __('Log in') }}
            </x-primary-button>
        </form>
    @else
        <div class="rounded-lg bg-indigo-50 border border-indigo-100 px-4 py-3 text-sm text-indigo-800">
            {{ __('Password login is disabled. Please sign in with Google.') }}
        </div>
    @endif

    <div class="mt-6 flex justify-center gap-1 p-0.5 bg-gray-100 rounded-lg text-xs w-fit mx-auto">
        @foreach (['ru' => 'RU', 'uk' => 'UA', 'en' => 'EN'] as $code => $label)
            <a href="{{ route('locale.switch', $code) }}"
               class="px-3 py-1.5 rounded-md transition-colors {{ app()->getLocale() === $code ? 'bg-white text-indigo-700 font-semibold shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>
</div>
