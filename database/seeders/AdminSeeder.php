<?php

namespace Database\Seeders;

use App\Enums\AuthProvider;
use App\Enums\SystemType;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('tasktracker.admin_email');
        $password = env('ADMIN_PASSWORD');

        if (empty($password)) {
            $this->command?->warn('ADMIN_PASSWORD is not set in .env — using default password for local development only.');
            $password = 'ChangeMe2026!';
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => config('tasktracker.admin_name', 'Administrator'),
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'system_type' => SystemType::Admin,
                'auth_provider' => AuthProvider::Password,
                'locale' => config('tasktracker.default_locale', 'ru'),
                'is_active' => true,
                'department_id' => \App\Models\Department::query()->where('name', 'IT')->value('id'),
            ]
        );

        $itRole = \App\Models\Role::query()->where('name', 'IT')->first();
        if ($itRole) {
            $user->roles()->syncWithoutDetaching([$itRole->id]);
        }

        Setting::set('google_sso_enabled', config('tasktracker.google_sso_enabled'));
        Setting::set('password_login_enabled', config('tasktracker.password_login_enabled'));
        Setting::set('allowed_email_domains', config('tasktracker.allowed_email_domains'));
    }
}
