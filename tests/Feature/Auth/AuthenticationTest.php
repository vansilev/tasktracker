<?php

namespace Tests\Feature\Auth;

use App\Enums\SystemType;
use App\Models\User;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_forgot_password_screen_can_be_rendered(): void
    {
        $this->get('/forgot-password')->assertOk();
    }

    public function test_login_redirects_to_dashboard(): void
    {
        $dept = $this->createDepartment();
        $user = $this->createUserInDepartment($dept, 'Login User');

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));
    }

    public function test_non_admin_login_does_not_follow_admin_intended_url(): void
    {
        $dept = $this->createDepartment();
        $user = $this->createUserInDepartment($dept, 'Marketing User');

        $this->get('/admin');

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_login_can_follow_admin_intended_url(): void
    {
        $admin = User::factory()->create([
            'email' => 'login-admin-'.uniqid().'@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);

        $this->get('/admin/audit');

        Volt::test('pages.auth.login')
            ->set('form.email', $admin->email)
            ->set('form.password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect('/admin/audit');
    }
}
