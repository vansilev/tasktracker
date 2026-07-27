<?php

namespace Tests\Feature;

use App\Enums\SystemType;
use App\Models\User;
use App\Services\UserLifecycleService;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class UserLifecycleTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_cannot_deactivate_last_active_administrator(): void
    {
        $admin = User::factory()->create([
            'email' => 'only-admin@tcsavant.com',
            'system_type' => SystemType::Admin,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        app(UserLifecycleService::class)->deactivate($admin);
    }

    public function test_cannot_assign_inactive_user_as_department_head(): void
    {
        $dept = $this->createDepartment();
        $inactive = $this->createUserInDepartment($dept, 'Inactive');
        $inactive->update(['is_active' => false]);

        $this->expectException(ValidationException::class);
        app(UserLifecycleService::class)->syncDepartmentHead($dept, $inactive->id);
    }

    public function test_cannot_assign_head_from_other_department(): void
    {
        $deptA = $this->createDepartment('Dept A');
        $deptB = $this->createDepartment('Dept B');
        $user = $this->createUserInDepartment($deptB, 'Other dept user');

        $this->expectException(ValidationException::class);
        app(UserLifecycleService::class)->syncDepartmentHead($deptA, $user->id);
    }
}
