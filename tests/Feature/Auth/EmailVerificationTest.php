<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_verification_screen_requires_authentication(): void
    {
        $this->get('/verify-email')->assertRedirect('/login');
    }

    public function test_verified_user_can_access_dashboard(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)->get('/dashboard')->assertOk();
    }
}
