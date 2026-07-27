<?php

namespace App\Http\Controllers\Auth;

use App\Enums\AuthProvider;
use App\Enums\SystemType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

/**
 * Google SSO — prepared for future activation.
 * Set GOOGLE_SSO_ENABLED=true and Google credentials in .env to enable.
 */
class GoogleAuthController extends Controller
{
    public function __construct(
        private SettingsService $settings,
    ) {}

    public function redirect(): RedirectResponse
    {
        if (! $this->settings->get('google_sso_enabled')) {
            return redirect()
                ->route('login')
                ->with('status', __('Google sign-in is not enabled yet. Use email and password.'));
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback(): RedirectResponse
    {
        if (! $this->settings->get('google_sso_enabled')) {
            return redirect()->route('login');
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException|Throwable) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => __('Google sign-in failed. Please try again.')]);
        }

        $email = strtolower($googleUser->getEmail());
        $domain = substr(strrchr($email, '@') ?: '', 1);

        if (! in_array($domain, $this->settings->get('allowed_email_domains', []), true)) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => __('Only corporate email addresses are allowed.')]);
        }

        $user = User::query()->where('email', $email)->first();

        if ($user !== null && ! $user->is_active) {
            return redirect()
                ->route('login')
                ->withErrors(['email' => __('Your account has been deactivated.')]);
        }

        if ($user === null) {
            $user = User::query()->create([
                'name' => $googleUser->getName() ?: $email,
                'email' => $email,
                'password' => null,
                'email_verified_at' => now(),
                'system_type' => SystemType::User,
                'auth_provider' => AuthProvider::Google,
                'google_id' => $googleUser->getId(),
                'avatar' => $googleUser->getAvatar(),
                'locale' => config('tasktracker.default_locale', 'ru'),
                'is_active' => true,
            ]);
        } else {
            $user->update([
                'google_id' => $googleUser->getId(),
                'avatar' => $googleUser->getAvatar() ?: $user->avatar,
                'auth_provider' => AuthProvider::Google,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);
        }

        session(['audit_login_method' => 'google']);

        Auth::login($user, remember: true);
        session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
