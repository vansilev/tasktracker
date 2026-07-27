<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Auth\Events\Login;

class LogSuccessfulLogin
{
    public function __construct(
        private AuditLogService $audit,
    ) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $method = session()->pull('audit_login_method', 'password');

        $this->audit->log('auth.login', $event->user, $event->user, null, [
            'email' => $event->user->email,
            'method' => $method,
        ]);
    }
}
