<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\SystemType;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class AdminAuditTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_creating_role_writes_audit_log(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin);

        Volt::test('pages.admin.roles')
            ->set('name', 'Audit Test Role')
            ->set('description', 'Role for audit test')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'role.created',
            'actor_id' => $admin->id,
        ]);

        $log = AuditLog::query()->where('action', 'role.created')->latest('created_at')->first();
        $this->assertNotNull($log);
        $this->assertSame('Audit Test Role', $log->new_values['name']);
        $this->assertNull($log->old_values);
    }

    public function test_saving_role_edit_writes_audit_log_with_old_and_new_values(): void
    {
        $admin = $this->createAdmin();
        $dept = $this->createDepartment('Audit Dept');
        $role = Role::query()->create([
            'name' => 'Original Role',
            'description' => 'Before',
            'is_active' => true,
        ]);
        $role->syncPermissions([Permission::ViewTask->value]);

        $this->actingAs($admin);

        Volt::test('pages.admin.role-edit', ['role' => $role])
            ->set('name', 'Updated Role')
            ->set('description', 'After')
            ->set('permissions', [Permission::ViewTask->value, Permission::Comment->value])
            ->set('visibleDepartmentIds', [(string) $dept->id])
            ->call('save')
            ->assertHasNoErrors();

        $log = AuditLog::query()->where('action', 'role.updated')->latest('created_at')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('Original Role', $log->old_values['name']);
        $this->assertSame('Updated Role', $log->new_values['name']);
        $this->assertSame('Before', $log->old_values['description']);
        $this->assertSame('After', $log->new_values['description']);
        $this->assertContains(Permission::Comment->value, $log->new_values['permissions']);
        $this->assertContains($dept->id, $log->new_values['visible_department_ids']);
    }

    public function test_archiving_role_writes_audit_log(): void
    {
        $admin = $this->createAdmin();
        $role = Role::query()->create([
            'name' => 'Role To Archive',
            'is_active' => true,
        ]);

        $this->actingAs($admin);

        Volt::test('pages.admin.roles')
            ->call('archive', $role->id)
            ->assertHasNoErrors();

        $log = AuditLog::query()->where('action', 'role.archived')->latest('created_at')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($role->id, $log->entity_id);
        $this->assertTrue($log->old_values['is_active']);
        $this->assertFalse($log->new_values['is_active']);
    }

    public function test_restoring_role_writes_audit_log(): void
    {
        $admin = $this->createAdmin();
        $role = Role::query()->create([
            'name' => 'Role To Restore',
            'is_active' => false,
        ]);

        $this->actingAs($admin);

        Volt::test('pages.admin.roles')
            ->call('restore', $role->id)
            ->assertHasNoErrors();

        $log = AuditLog::query()->where('action', 'role.restored')->latest('created_at')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame($role->id, $log->entity_id);
        $this->assertFalse($log->old_values['is_active']);
        $this->assertTrue($log->new_values['is_active']);
    }

    public function test_archiving_department_writes_audit_log(): void
    {
        $admin = $this->createAdmin();
        $dept = $this->createDepartment('Empty Dept');

        $this->actingAs($admin);

        Volt::test('pages.admin.departments')
            ->call('archive', $dept->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'department.archived',
            'actor_id' => $admin->id,
            'entity_id' => $dept->id,
        ]);
    }

    public function test_creating_category_writes_audit_log(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin);

        Volt::test('pages.admin.categories')
            ->set('name', 'Audit Category')
            ->call('create')
            ->assertHasNoErrors();

        $log = AuditLog::query()->where('action', 'category.created')->latest('created_at')->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertSame('Audit Category', $log->new_values['name']);
    }

    public function test_admin_can_access_audit_page(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->get('/admin/audit')
            ->assertOk()
            ->assertSee(__('Audit log'));
    }

    public function test_regular_user_cannot_access_audit_page(): void
    {
        $dept = $this->createDepartment();
        $user = $this->createUserInDepartment($dept, 'Employee');

        $this->actingAs($user)
            ->get('/admin/audit')
            ->assertForbidden();
    }

    private function createAdmin(): User
    {
        return User::factory()->create([
            'email' => 'audit-admin-'.uniqid().'@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);
    }
}
