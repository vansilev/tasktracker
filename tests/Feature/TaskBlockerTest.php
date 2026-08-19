<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use App\Services\TaskWorkflowService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class TaskBlockerTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_sibling_blocker_can_be_added_and_listed(): void
    {
        [$parent, , $assignee] = $this->makeParent();
        $service = app(TaskService::class);
        $waiting = $service->createSubtask($assignee, $parent, ['title' => 'Copy']);
        $blocker = $service->createSubtask($assignee, $parent, ['title' => 'Design']);

        $service->addBlocker($assignee, $waiting, $blocker);

        $this->assertTrue($waiting->fresh()->blockers->contains('id', $blocker->id));
        $this->assertSame(
            __('Waiting on :numbers', ['numbers' => '#'.$blocker->number]),
            $waiting->fresh()->load('blockers')->waitingOnLabel(),
        );
    }

    public function test_blocker_must_be_a_sibling(): void
    {
        [$parent, , $assignee] = $this->makeParent();
        [$otherParent, , $otherAssignee] = $this->makeParent();
        $service = app(TaskService::class);
        $waiting = $service->createSubtask($assignee, $parent, ['title' => 'Copy']);
        $stranger = $service->createSubtask($otherAssignee, $otherParent, ['title' => 'Other']);

        $this->expectException(ValidationException::class);
        $service->addBlocker($assignee, $waiting, $stranger);
    }

    public function test_task_cannot_wait_on_itself(): void
    {
        [$parent, , $assignee] = $this->makeParent();
        $child = app(TaskService::class)->createSubtask($assignee, $parent, ['title' => 'Copy']);

        $this->expectException(ValidationException::class);
        app(TaskService::class)->addBlocker($assignee, $child, $child);
    }

    public function test_blocker_cycle_is_rejected(): void
    {
        [$parent, , $assignee] = $this->makeParent();
        $service = app(TaskService::class);
        $first = $service->createSubtask($assignee, $parent, ['title' => 'A']);
        $second = $service->createSubtask($assignee, $parent, ['title' => 'B']);
        $service->addBlocker($assignee, $first, $second);

        $this->expectException(ValidationException::class);
        $service->addBlocker($assignee, $second, $first);
    }

    public function test_open_blocker_does_not_stop_status_change(): void
    {
        [$parent, , $assignee] = $this->makeParent();
        $service = app(TaskService::class);
        $waiting = $service->createSubtask($assignee, $parent, ['title' => 'Copy']);
        $blocker = $service->createSubtask($assignee, $parent, ['title' => 'Design']);
        $service->addBlocker($assignee, $waiting, $blocker);

        app(TaskWorkflowService::class)->transition($waiting, $assignee, TaskStatus::InProgress);

        $this->assertSame(TaskStatus::InProgress, $waiting->fresh()->status);
        $this->assertNotSame('', $waiting->fresh()->load('blockers')->waitingOnLabel());
    }

    public function test_completed_blocker_drops_waiting_badge(): void
    {
        [$parent, , $assignee] = $this->makeParent();
        $service = app(TaskService::class);
        $waiting = $service->createSubtask($assignee, $parent, ['title' => 'Copy']);
        $blocker = $service->createSubtask($assignee, $parent, ['title' => 'Design']);
        $service->addBlocker($assignee, $waiting, $blocker);

        $blocker->update(['status' => TaskStatus::Completed]);

        $this->assertSame('', $waiting->fresh()->load('blockers')->waitingOnLabel());
        $this->assertTrue($waiting->fresh()->blockers->contains('id', $blocker->id));
    }

    public function test_waiting_select_is_on_the_real_page_with_full_placeholder(): void
    {
        [$parent, , $assignee] = $this->makeParent();
        $service = app(TaskService::class);
        $waiting = $service->createSubtask($assignee, $parent, ['title' => 'Copy']);
        $blocker = $service->createSubtask($assignee, $parent, ['title' => 'Design']);

        $xpath = $this->waitingPage($assignee, $waiting);

        $select = $xpath->query('//*[@data-testid="waiting-select"]')->item(0);
        $this->assertNotNull($select, 'На карточке подзадачи должен быть селект «кого ждём».');
        $this->assertSame(__('Select a sibling subtask'), $this->firstOptionText($select));
        $this->assertSame('#'.$blocker->number.' · Design', $this->optionTexts($select)[1] ?? null);

        $style = $select->getAttribute('style');
        $this->assertStringContainsString('min-height:2.5rem', $style);
        $this->assertStringContainsString('min-width:16rem', $style);
        $class = $select->getAttribute('class');
        $this->assertDoesNotMatchRegularExpression('/\bh-7\b/', $class);
        $this->assertDoesNotMatchRegularExpression('/\brounded-full\b/', $class);

        $row = $xpath->query('//*[@data-testid="waiting-row"]')->item(0);
        $this->assertNotNull($row);
        $this->assertStringContainsString(__('Waiting on'), $row->textContent);
        $this->assertStringContainsString(__('Add blocker'), $row->textContent);
        $this->assertNull($xpath->query('//*[@data-testid="waiting-chip"]')->item(0));
    }

    public function test_completed_blocker_stays_on_the_real_page_and_can_be_removed(): void
    {
        [$parent, , $assignee] = $this->makeParent();
        $service = app(TaskService::class);
        $waiting = $service->createSubtask($assignee, $parent, ['title' => 'Copy']);
        $blocker = $service->createSubtask($assignee, $parent, ['title' => 'Design']);
        $service->addBlocker($assignee, $waiting, $blocker);
        $blocker->update(['status' => TaskStatus::Completed]);

        $xpath = $this->waitingPage($assignee, $waiting->fresh());

        $chip = $xpath->query('//*[@data-testid="waiting-chip"]')->item(0);
        $this->assertNotNull($chip, 'Пометка «ждёт» не должна пропадать, даже если та подзадача уже закрыта.');
        $this->assertStringContainsString('#'.$blocker->number, $chip->textContent);
        $this->assertStringContainsString('Design', $chip->textContent);

        $face = $xpath->query('.//*[@data-open]', $chip)->item(0);
        $this->assertNotNull($face);
        $this->assertSame('0', $face->getAttribute('data-open'));
        $this->assertStringContainsString('#E5E7EB', $face->getAttribute('style'));
        $this->assertStringNotContainsString('#FACC15', $face->getAttribute('style'));

        $remove = $xpath->query('//*[@data-testid="waiting-remove"]')->item(0);
        $this->assertNotNull($remove, 'Должна быть кнопка, чтобы убрать ожидание.');
        $this->assertSame(__('Remove wait'), trim($remove->textContent));
        $this->assertNull($xpath->query('//*[@data-testid="waiting-select"]')->item(0));

        $this->actingAs($assignee);
        Volt::test('pages.tasks.show', ['task' => $waiting->fresh()])
            ->call('removeBlocker', $blocker->id)
            ->assertHasNoErrors();

        $after = $this->waitingPage($assignee, $waiting->fresh());
        $this->assertNull($after->query('//*[@data-testid="waiting-chip"]')->item(0));
        $select = $after->query('//*[@data-testid="waiting-select"]')->item(0);
        $this->assertNotNull($select, 'После «Убрать» селект должен вернуться.');
        $this->assertSame('#'.$blocker->number.' · Design', $this->optionTexts($select)[1] ?? null);
        $this->assertFalse($waiting->fresh()->blockers->contains('id', $blocker->id));
    }

    public function test_open_blocker_chip_is_yellow_on_the_real_page(): void
    {
        [$parent, , $assignee] = $this->makeParent();
        $service = app(TaskService::class);
        $waiting = $service->createSubtask($assignee, $parent, ['title' => 'Copy']);
        $blocker = $service->createSubtask($assignee, $parent, ['title' => 'Design']);
        $service->addBlocker($assignee, $waiting, $blocker);

        $xpath = $this->waitingPage($assignee, $waiting->fresh());
        $face = $xpath->query('//*[@data-testid="waiting-chip"]//*[@data-open]')->item(0);

        $this->assertNotNull($face);
        $this->assertSame('1', $face->getAttribute('data-open'));
        $this->assertStringContainsString('#FACC15', $face->getAttribute('style'));
        $this->assertStringContainsString('#'.$blocker->number.' · Design', $face->textContent);
    }

    public function test_outsider_cannot_add_blocker(): void
    {
        [$parent, , $assignee] = $this->makeParent();
        $service = app(TaskService::class);
        $waiting = $service->createSubtask($assignee, $parent, ['title' => 'Copy']);
        $blocker = $service->createSubtask($assignee, $parent, ['title' => 'Design']);

        $outsiderDept = $this->createDepartment('Sales');
        $outsider = $this->createUserInDepartment(
            $outsiderDept,
            'Outsider',
            role: $this->createRoleWithPermissions($this->defaultPermissions()),
        );

        $this->expectException(AuthorizationException::class);
        $service->addBlocker($outsider, $waiting, $blocker);
    }

    public function test_child_card_can_add_blocker_from_ui(): void
    {
        [$parent, , $assignee] = $this->makeParent();
        $service = app(TaskService::class);
        $waiting = $service->createSubtask($assignee, $parent, ['title' => 'Copy']);
        $blocker = $service->createSubtask($assignee, $parent, ['title' => 'Design']);

        $this->actingAs($assignee);

        Volt::test('pages.tasks.show', ['task' => $waiting])
            ->set('newBlockerId', $blocker->id)
            ->call('addBlocker')
            ->assertHasNoErrors();

        $xpath = $this->waitingPage($assignee, $waiting->fresh());
        $chip = $xpath->query('//*[@data-testid="waiting-chip"]')->item(0);
        $this->assertNotNull($chip);
        $this->assertStringContainsString('#'.$blocker->number.' · Design', $chip->textContent);
        $this->assertNotNull($xpath->query('//*[@data-testid="waiting-remove"]')->item(0));
    }

    public function test_parent_card_shows_waiting_badge_on_child(): void
    {
        [$parent, , $assignee] = $this->makeParent();
        $service = app(TaskService::class);
        $waiting = $service->createSubtask($assignee, $parent, ['title' => 'Copy']);
        $blocker = $service->createSubtask($assignee, $parent, ['title' => 'Design']);
        $service->addBlocker($assignee, $waiting, $blocker);

        $this->actingAs($assignee)
            ->get('/tasks/'.$parent->id)
            ->assertOk()
            ->assertSee(__('Waiting on :numbers', ['numbers' => '#'.$blocker->number]));
    }

    /** @return array{0: Task, 1: User, 2: User} */
    private function makeParent(array $overrides = []): array
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions());
        $initiator = $this->createUserInDepartment($dept, 'Initiator '.uniqid(), role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee '.uniqid(), role: $role);
        $parent = $this->createTask($initiator, $assignee, $this->createCategory(), array_merge([
            'title' => 'Parent task',
            'priority' => 7,
        ], $overrides));

        return [$parent, $initiator, $assignee];
    }

    private function waitingPage(User $user, Task $task): \DOMXPath
    {
        $html = $this->actingAs($user)->get('/tasks/'.$task->id)->assertOk()->getContent();
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($dom);
    }

    /** @return list<string> */
    private function optionTexts(\DOMNode $select): array
    {
        $texts = [];
        foreach ($select->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->nodeName) === 'option') {
                $texts[] = trim($child->textContent);
            }
        }

        return $texts;
    }

    private function firstOptionText(\DOMNode $select): string
    {
        return $this->optionTexts($select)[0] ?? '';
    }
}
