<?php

namespace Tests\Feature;

use App\Enums\SystemType;
use App\Models\User;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class SmokeRoutesTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_root_redirects_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_access_core_routes(): void
    {
        $dept = $this->createDepartment();
        $user = $this->createUserInDepartment($dept, 'Employee');

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk();

        $this->actingAs($user)
            ->get('/tasks')
            ->assertOk();

        $this->actingAs($user)
            ->get('/tasks/create')
            ->assertOk();
    }

    public function test_admin_can_access_admin_area(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk();
    }

    public function test_regular_user_cannot_access_admin_area(): void
    {
        $dept = $this->createDepartment();
        $user = $this->createUserInDepartment($dept, 'Employee');

        $this->actingAs($user)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_dept_head_can_access_core_routes(): void
    {
        $head = User::factory()->create([
            'email' => 'dept-head@tcsavant.com',
            'system_type' => SystemType::DeptHead,
            'email_verified_at' => now(),
        ]);
        $dept = $this->createDepartment('Sales', $head);
        $head->update(['department_id' => $dept->id]);

        $this->actingAs($head)
            ->get('/dashboard')
            ->assertOk();

        $this->actingAs($head)
            ->get('/tasks')
            ->assertOk();
    }

    public function test_user_without_roles_can_access_tasks(): void
    {
        $user = User::factory()->create([
            'email' => 'no-roles@tcsavant.com',
            'department_id' => null,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($user)
            ->get('/tasks')
            ->assertOk();

        $this->actingAs($user)
            ->get('/tasks/create')
            ->assertOk();
    }

    public function test_regular_user_cannot_access_billing(): void
    {
        $dept = $this->createDepartment();
        $user = $this->createUserInDepartment($dept, 'Employee');

        $this->actingAs($user)
            ->get('/billing')
            ->assertForbidden();
    }

    public function test_admin_can_access_billing(): void
    {
        $admin = User::factory()->create([
            'email' => 'admin-billing-smoke@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get('/billing')
            ->assertOk();
    }
}
