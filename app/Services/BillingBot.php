<?php

namespace App\Services;

use App\Enums\AuthProvider;
use App\Enums\SystemType;
use App\Models\Department;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BillingBot
{
    public function email(): string
    {
        return (string) config('tasktracker.billing_bot_email');
    }

    public function is(User $user): bool
    {
        return strcasecmp($user->email, $this->email()) === 0;
    }

    public function user(): User
    {
        $email = $this->email();

        $user = User::query()->where('email', $email)->first();

        if ($user) {
            return $user;
        }

        return User::query()->create([
            'name' => config('tasktracker.billing_bot_name', 'Оплаты'),
            'email' => $email,
            'password' => Hash::make(Str::password(48)),
            'email_verified_at' => now(),
            'system_type' => SystemType::Admin,
            'auth_provider' => AuthProvider::Password,
            'locale' => config('tasktracker.default_locale', 'ru'),
            'is_active' => true,
            'department_id' => Department::query()->where('name', 'IT')->value('id'),
        ]);
    }

    public function peopleQuery()
    {
        return User::query()->people()->orderBy('name');
    }
}
