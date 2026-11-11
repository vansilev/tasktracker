<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Mcp\Servers\TaskTrackerServer;
use App\Mcp\Tools\TransitionTaskTool;
use App\Models\Task;
use App\Models\TaskHistory;
use App\Models\User;
use App\Services\TaskWorkflowService;
use Carbon\Carbon;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class PostponedUntilTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    private const TODAY = '2026-11-11';

    private const RETURN_DATE = '2026-11-20';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::TODAY.' 10:00:00', 'Europe/Kyiv'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_postponing_requires_a_return_date_later_than_today(): void
    {
        [$user, $task] = $this->userWithTaskInProgress();
        $workflow = app(TaskWorkflowService::class);

        foreach ([
            [null, __('task.postponed_until_required')],
            ['', __('task.postponed_until_required')],
            ['next week', __('task.postponed_until_invalid')],
            ['2026-02-30', __('task.postponed_until_invalid')],
            [self::TODAY, __('task.postponed_until_future')],
            ['2026-11-01', __('task.postponed_until_future')],
        ] as [$date, $message]) {
            try {
                $workflow->transition($task, $user, TaskStatus::Postponed, 'Waiting', postponedUntil: $date);
                $this->fail('Postponing with '.json_encode($date).' must be rejected.');
            } catch (InvalidArgumentException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
        $this->assertNull($task->fresh()->postponed_until);
    }

    public function test_return_date_is_stored_logged_and_cleared_when_the_task_resumes(): void
    {
        [$user, $task] = $this->userWithTaskInProgress();
        $workflow = app(TaskWorkflowService::class);

        $workflow->transition($task, $user, TaskStatus::Postponed, 'Waiting for the client', postponedUntil: self::RETURN_DATE);

        $task->refresh();
        $this->assertSame(TaskStatus::Postponed, $task->status);
        $this->assertSame(self::RETURN_DATE, $task->postponed_until->toDateString());
        $this->assertDatabaseHas('task_histories', [
            'task_id' => $task->id, 'field' => 'postponed_until', 'old_value' => null, 'new_value' => self::RETURN_DATE,
        ]);

        // Leaving "postponed" needs no date and drops the old one.
        $workflow->transition($task, $user, TaskStatus::InProgress);

        $task->refresh();
        $this->assertSame(TaskStatus::InProgress, $task->status);
        $this->assertNull($task->postponed_until);
        $this->assertDatabaseHas('task_histories', [
            'task_id' => $task->id, 'field' => 'postponed_until', 'old_value' => self::RETURN_DATE, 'new_value' => null,
        ]);
    }

    public function test_other_transitions_ignore_a_stray_return_date(): void
    {
        [$user, $task] = $this->userWithTaskInProgress();

        app(TaskWorkflowService::class)->transition($task, $user, TaskStatus::OnReview, postponedUntil: self::RETURN_DATE);

        $this->assertNull($task->fresh()->postponed_until);
        $this->assertSame(0, TaskHistory::query()->where('field', 'postponed_until')->count());
    }

    public function test_undo_clears_the_date_and_undoing_a_resume_puts_it_back(): void
    {
        [$user, $task] = $this->userWithTaskInProgress();
        $workflow = app(TaskWorkflowService::class);

        $postpone = $workflow->transition($task, $user, TaskStatus::Postponed, 'Waiting', postponedUntil: self::RETURN_DATE);
        $this->assertSame(1, $workflow->undo($user, $workflow->issueUndoToken($user, [$postpone])));

        $task->refresh();
        $this->assertSame(TaskStatus::InProgress, $task->status);
        $this->assertNull($task->postponed_until);

        $workflow->transition($task, $user, TaskStatus::Postponed, 'Waiting again', postponedUntil: self::RETURN_DATE);
        $resume = $workflow->transition($task->fresh(), $user, TaskStatus::InProgress);
        $this->assertSame(1, $workflow->undo($user, $workflow->issueUndoToken($user, [$resume])));

        $task->refresh();
        $this->assertSame(TaskStatus::Postponed, $task->status);
        $this->assertSame(self::RETURN_DATE, $task->postponed_until->toDateString());
    }

    public function test_undo_token_issued_before_the_field_existed_still_works(): void
    {
        [$user, $task] = $this->userWithTaskInProgress();
        $workflow = app(TaskWorkflowService::class);

        $item = $workflow->transition($task, $user, TaskStatus::Postponed, 'Waiting', postponedUntil: self::RETURN_DATE);
        unset($item['snapshot']['postponed_until']);

        $this->assertSame(1, $workflow->undo($user, $workflow->issueUndoToken($user, [$item])));
        $this->assertNull($task->fresh()->postponed_until);
    }

    public function test_task_card_asks_for_the_date_and_shows_it(): void
    {
        [$user, $task] = $this->userWithTaskInProgress();
        $this->actingAs($user);

        $component = Volt::test('pages.tasks.show', ['task' => $task])
            ->call('selectTransition', TaskStatus::Postponed->value)
            ->assertSee('data-ui="postponed-until"', false)
            ->set('transitionComment', '<p>Waiting for the client</p>')
            ->call('confirmTransition')
            ->assertHasErrors('postponedUntil');

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);

        $component
            ->set('postponedUntil', self::TODAY)
            ->call('confirmTransition')
            ->assertHasErrors('postponedUntil');

        $component
            ->set('postponedUntil', self::RETURN_DATE)
            ->call('confirmTransition')
            ->assertHasNoErrors()
            ->assertSee('data-ui="postponed-until-value"', false)
            ->assertSee('20.11.2026');

        $this->assertSame(self::RETURN_DATE, $task->fresh()->postponed_until->toDateString());
    }

    public function test_other_statuses_in_the_card_show_no_date_field(): void
    {
        [$user, $task] = $this->userWithTaskInProgress();
        $this->actingAs($user);

        Volt::test('pages.tasks.show', ['task' => $task])
            ->call('selectTransition', TaskStatus::AwaitingInitiator->value)
            ->assertDontSee('data-ui="postponed-until"', false)
            ->set('transitionComment', '<p>Need details</p>')
            ->call('confirmTransition')
            ->assertHasNoErrors();

        $this->assertSame(TaskStatus::AwaitingInitiator, $task->fresh()->status);
    }

    public function test_peek_asks_for_the_date(): void
    {
        [$user, $task] = $this->userWithTaskInProgress();
        $this->actingAs($user);

        $component = Volt::test('pages.tasks.peek', ['number' => $task->number])
            ->call('selectTransition', TaskStatus::Postponed->value)
            ->assertSee('data-ui="postponed-until"', false)
            ->set('comment', 'Waiting')
            ->call('confirmTransition')
            ->assertHasErrors('postponedUntil');

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);

        $component->set('postponedUntil', self::RETURN_DATE)->call('confirmTransition')->assertHasNoErrors();

        $this->assertSame(self::RETURN_DATE, $task->fresh()->postponed_until->toDateString());
    }

    public function test_list_context_menu_passes_the_date_and_marks_the_status_as_needing_one(): void
    {
        [$user, $task] = $this->userWithTaskInProgress();
        $this->actingAs($user);

        $component = Volt::test('pages.tasks.index')->set('tab', 'all');

        $component->call('quickTransition', $task->id, TaskStatus::Postponed->value, 'Waiting');
        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);

        $component->call('quickTransition', $task->id, TaskStatus::Postponed->value, 'Waiting', self::RETURN_DATE);
        $this->assertSame(TaskStatus::Postponed, $task->fresh()->status);
        $this->assertSame(self::RETURN_DATE, $task->fresh()->postponed_until->toDateString());
    }

    public function test_bulk_postpone_stops_before_the_loop_without_a_date(): void
    {
        [$user, $task] = $this->userWithTaskInProgress();
        $second = $this->createTask($user, $user, $task->category, ['title' => 'Second', 'status' => TaskStatus::InProgress]);
        $this->actingAs($user);

        $component = Volt::test('pages.tasks.index')
            ->set('tab', 'all')
            ->set('selectedIds', [$task->id, $second->id])
            ->call('chooseBulkStatus', TaskStatus::Postponed->value)
            ->assertSee('data-ui="postponed-until"', false)
            ->set('bulkComment', 'Waiting')
            ->call('confirmBulkAction');

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);
        $this->assertSame(TaskStatus::InProgress, $second->fresh()->status);
        // The selection survives, so the person only has to add the date.
        $this->assertCount(2, $component->get('selectedIds'));

        $component->set('bulkPostponedUntil', self::RETURN_DATE)->call('confirmBulkAction');

        foreach ([$task, $second] as $postponed) {
            $this->assertSame(TaskStatus::Postponed, $postponed->fresh()->status);
            $this->assertSame(self::RETURN_DATE, $postponed->fresh()->postponed_until->toDateString());
        }
    }

    public function test_api_rejects_postponed_without_a_date_and_returns_the_field(): void
    {
        [$user, $task] = $this->userWithTaskInProgress();
        Sanctum::actingAs($user);
        $url = '/api/v1/tasks/'.$task->number.'/transition';

        $this->postJson($url, ['status' => 'postponed', 'comment' => 'Waiting'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', __('task.postponed_until_required'));

        $this->postJson($url, ['status' => 'postponed', 'comment' => 'Waiting', 'postponed_until' => self::RETURN_DATE])
            ->assertOk()
            ->assertJsonPath('data.status', 'postponed')
            ->assertJsonPath('data.postponed_until', self::RETURN_DATE);

        $this->postJson($url, ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.postponed_until', null);
    }

    public function test_mcp_tool_takes_the_date(): void
    {
        [$user, $task] = $this->userWithTaskInProgress();

        TaskTrackerServer::actingAs($user)
            ->tool(TransitionTaskTool::class, [
                'number' => $task->number,
                'status' => 'postponed',
                'comment' => 'Waiting',
            ])
            ->assertHasErrors();

        $this->assertSame(TaskStatus::InProgress, $task->fresh()->status);

        TaskTrackerServer::actingAs($user)
            ->tool(TransitionTaskTool::class, [
                'number' => $task->number,
                'status' => 'postponed',
                'comment' => 'Waiting',
                'postponed_until' => self::RETURN_DATE,
            ])
            ->assertOk()
            ->assertSee(self::RETURN_DATE);

        $this->assertSame(self::RETURN_DATE, $task->fresh()->postponed_until->toDateString());
    }

    /** @return array{0: User, 1: Task} */
    private function userWithTaskInProgress(): array
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $user = $this->createUserInDepartment($dept, 'Postpone User', role: $role);
        $task = $this->createTask($user, $user, $this->createCategory(), [
            'title' => 'Postpone me',
            'status' => TaskStatus::InProgress,
        ]);

        return [$user, $task];
    }
}
