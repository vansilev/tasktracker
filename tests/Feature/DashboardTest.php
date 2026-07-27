<?php

namespace Tests\Feature;

use App\Enums\SystemType;
use App\Enums\TaskStatus;
use App\Models\User;
use App\Services\DashboardService;
use Carbon\Carbon;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_dashboard_page_is_accessible_for_common_roles(): void
    {
        $dept = $this->createDepartment();
        $user = $this->createUserInDepartment($dept, 'Employee');

        $admin = User::factory()->create([
            'email' => 'dashboard-admin@tcsavant.com',
            'system_type' => SystemType::Admin,
            'email_verified_at' => now(),
        ]);

        $noRolesUser = User::factory()->create([
            'email' => 'dashboard-no-roles@tcsavant.com',
            'department_id' => null,
            'email_verified_at' => now(),
        ]);

        foreach ([$admin, $user, $noRolesUser] as $actor) {
            $this->actingAs($actor)
                ->get('/dashboard')
                ->assertOk()
                ->assertSee(__('Open tasks'))
                ->assertSee(__('Overdue tasks'))
                ->assertSee(__('On review'))
                ->assertSee(__('Urgent'))
                ->assertSee(__('Average closing time'))
                ->assertSee(__('My tasks'))
                ->assertSee(__('By department'))
                ->assertSee(__('By category'))
                ->assertSee(__('Quick links'));
        }
    }

    public function test_visibility_limits_open_and_overdue_widgets_for_regular_user(): void
    {
        $ownDept = $this->createDepartment('Own');
        $otherDept = $this->createDepartment('Other');
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$ownDept->id]);

        $user = $this->createUserInDepartment($ownDept, 'Scoped User', role: $role);
        $otherAssignee = $this->createUserInDepartment($otherDept, 'Other Assignee');
        $category = $this->createCategory();

        $visibleTask = $this->createTask($user, $user, $category, [
            'status' => TaskStatus::InProgress,
            'deadline' => now()->subDay(),
        ]);

        $hiddenTask = $this->createTask($otherAssignee, $otherAssignee, $category, [
            'status' => TaskStatus::InProgress,
            'deadline' => now()->subDay(),
        ]);

        $service = app(DashboardService::class);

        $open = $service->openByStatus($user);
        $overdue = $service->overdue($user);

        $this->assertSame(1, $open['total']);
        $this->assertSame(1, $open['by_status'][TaskStatus::InProgress->value]);
        $this->assertSame(1, $overdue['count']);
        $this->assertSame([$visibleTask->id], array_column($overdue['items'], 'id'));
        $this->assertNotContains($hiddenTask->id, array_column($overdue['items'], 'id'));
    }

    public function test_by_department_counts_completed_tasks_only_inside_period(): void
    {
        Carbon::setTestNow('2026-07-15 12:00:00');

        $dept = $this->createDepartment('Analytics');
        $user = $this->createUserInDepartment($dept, 'Dept User');
        $category = $this->createCategory();

        $inPeriodTask = $this->createTask($user, $user, $category, [
            'status' => TaskStatus::Completed,
        ]);
        $inPeriodTask->forceFill([
            'created_at' => '2026-07-01 10:00:00',
            'completed_at' => '2026-07-10 10:00:00',
        ])->save();

        $outOfPeriodTask = $this->createTask($user, $user, $category, [
            'status' => TaskStatus::Completed,
        ]);
        $outOfPeriodTask->forceFill([
            'created_at' => '2026-06-01 10:00:00',
            'completed_at' => '2026-06-20 10:00:00',
        ])->save();

        $service = app(DashboardService::class);
        $from = Carbon::parse('2026-07-01')->startOfDay();
        $to = Carbon::parse('2026-07-31')->endOfDay();

        $rows = collect($service->byDepartment($user, $from, $to));
        $deptRow = $rows->firstWhere('id', $dept->id);

        $this->assertNotNull($deptRow);
        $this->assertSame(1, $deptRow['created']);
        $this->assertSame(1, $deptRow['completed']);
        $this->assertTrue($inPeriodTask->completed_at->between($from, $to));
        $this->assertFalse($outOfPeriodTask->completed_at->between($from, $to));

        Carbon::setTestNow();
    }

    public function test_avg_closing_time_is_calculated_in_hours(): void
    {
        Carbon::setTestNow('2026-07-15 12:00:00');

        $dept = $this->createDepartment();
        $user = $this->createUserInDepartment($dept, 'Closer');
        $category = $this->createCategory();

        $task = $this->createTask($user, $user, $category, [
            'status' => TaskStatus::Completed,
        ]);
        $task->forceFill([
            'created_at' => '2026-07-01 00:00:00',
            'completed_at' => '2026-07-03 00:00:00',
        ])->save();

        $service = app(DashboardService::class);
        $from = Carbon::parse('2026-07-01')->startOfDay();
        $to = Carbon::parse('2026-07-31')->endOfDay();

        $this->assertSame(48.0, $service->avgClosingTime($user, $from, $to));
        $this->assertSame(__(':count d', ['count' => 2]), DashboardService::formatDurationHours(48.0));

        Carbon::setTestNow();
    }

    public function test_my_tasks_returns_only_open_assigned_tasks(): void
    {
        $dept = $this->createDepartment();
        $user = $this->createUserInDepartment($dept, 'Assignee');
        $other = $this->createUserInDepartment($dept, 'Other');
        $category = $this->createCategory();

        $openAssigned = $this->createTask($other, $user, $category, [
            'status' => TaskStatus::InProgress,
            'priority' => 8,
        ]);

        $this->createTask($other, $user, $category, [
            'status' => TaskStatus::Completed,
            'priority' => 10,
        ]);

        $this->createTask($user, $other, $category, [
            'status' => TaskStatus::New,
            'priority' => 9,
        ]);

        $service = app(DashboardService::class);
        $result = $service->myTasks($user);

        $this->assertSame(1, $result['count']);
        $this->assertCount(1, $result['items']);
        $this->assertSame($openAssigned->id, $result['items'][0]->id);
    }
}
