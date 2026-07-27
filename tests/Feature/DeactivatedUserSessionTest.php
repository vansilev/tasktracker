<?php

namespace Tests\Feature;

use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class DeactivatedUserSessionTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_deactivated_user_with_active_session_is_logged_out(): void
    {
        $dept = $this->createDepartment();
        $user = $this->createUserInDepartment($dept, 'Inactive');
        $user->update(['is_active' => false]);

        $this->actingAs($user)
            ->get('/tasks')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_active_user_can_access_tasks(): void
    {
        $dept = $this->createDepartment();
        $user = $this->createUserInDepartment($dept, 'Active');

        $this->actingAs($user)
            ->get('/tasks')
            ->assertOk();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/tasks')
            ->assertRedirect(route('login'));
    }

    public function test_google_redirect_when_sso_disabled_redirects_to_login(): void
    {
        config(['tasktracker.google_sso_enabled' => false]);

        $this->get(route('auth.google.redirect'))
            ->assertRedirect(route('login'));
    }
}
