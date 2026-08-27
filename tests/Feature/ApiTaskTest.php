<?php

namespace Tests\Feature;

use App\Enums\SystemType;
use App\Enums\TaskStatus;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class ApiTaskTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_guest_cannot_list_tasks(): void
    {
        $this->getJson('/api/v1/tasks')->assertUnauthorized();
    }

    public function test_token_login_returns_bearer_token(): void
    {
        $dept = $this->createDepartment();
        $user = $this->createUserInDepartment($dept, 'Api Owner');

        $response = $this->postJson('/api/v1/auth/token', [
            'email' => $user->email,
            'password' => 'password',
            'name' => 'test',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonStructure(['token', 'token_type', 'user']);

        $this->withToken($response->json('token'))
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_invalid_password_is_rejected(): void
    {
        $dept = $this->createDepartment();
        $user = $this->createUserInDepartment($dept, 'Api Owner');

        $this->postJson('/api/v1/auth/token', [
            'email' => $user->email,
            'password' => 'wrong',
        ])->assertUnprocessable();
    }

    public function test_deactivated_user_token_is_rejected(): void
    {
        $dept = $this->createDepartment();
        $user = $this->createUserInDepartment($dept, 'Api Owner');
        Sanctum::actingAs($user);
        $user->update(['is_active' => false]);

        $this->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_list_and_show_respect_visibility_and_use_public_number(): void
    {
        $dept = $this->createDepartment();
        $other = $this->createDepartment('Other');
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $outsider = $this->createUserInDepartment($other, 'Outsider', role: $this->createRoleWithPermissions($this->defaultPermissions(), [$other->id]));
        $category = $this->createCategory();
        $task = $this->createTask($initiator, $assignee, $category, ['title' => 'Visible API task']);

        Sanctum::actingAs($assignee);
        $this->getJson('/api/v1/tasks')
            ->assertOk()
            ->assertJsonPath('data.0.number', $task->number)
            ->assertJsonPath('data.0.title', 'Visible API task');

        $this->getJson('/api/v1/tasks/'.$task->number)
            ->assertOk()
            ->assertJsonPath('data.number', $task->number)
            ->assertJsonPath('data.title', 'Visible API task');

        Sanctum::actingAs($outsider);
        $this->getJson('/api/v1/tasks/'.$task->number)->assertNotFound();
    }

    public function test_create_comment_and_transition(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();

        Sanctum::actingAs($initiator);
        $created = $this->postJson('/api/v1/tasks', [
            'title' => 'API created task',
            'description' => 'Plain description',
            'department_id' => $dept->id,
            'category_id' => $category->id,
            'assignee_id' => $assignee->id,
            'priority' => 6,
        ])->assertCreated();

        $number = $created->json('data.number');
        $this->assertIsInt($number);

        Sanctum::actingAs($assignee);
        $this->postJson('/api/v1/tasks/'.$number.'/comments', [
            'body' => 'Working on it',
        ])->assertCreated()->assertJsonPath('data.body', 'Working on it');

        $this->postJson('/api/v1/tasks/'.$number.'/transition', [
            'status' => 'in_progress',
        ])->assertOk()->assertJsonPath('data.status', TaskStatus::InProgress->value);
    }

    public function test_admin_can_filter_by_search(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $category = $this->createCategory();
        $this->createTask($initiator, $assignee, $category, ['title' => 'Alpha needle']);
        $this->createTask($initiator, $assignee, $category, ['title' => 'Other task']);

        $admin = User::factory()->create([
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/tasks?q=needle')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Alpha needle');
    }

    public function test_artisan_issues_api_token(): void
    {
        $dept = $this->createDepartment();
        $user = $this->createUserInDepartment($dept, 'Token User');

        $this->artisan('tasktracker:issue-api-token', ['email' => $user->email, '--name' => 'test'])
            ->expectsOutputToContain('Token created')
            ->assertSuccessful();

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }
}
