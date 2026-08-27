<?php

namespace Tests\Feature;

use App\Mcp\Servers\TaskTrackerServer;
use App\Mcp\Tools\AddTaskCommentTool;
use App\Mcp\Tools\GetCurrentUserTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\TransitionTaskTool;
use Tests\Support\CreatesTaskTrackerFixtures;
use Tests\TestCase;

class McpTaskTrackerTest extends TestCase
{
    use CreatesTaskTrackerFixtures;

    public function test_get_current_user_requires_auth(): void
    {
        TaskTrackerServer::tool(GetCurrentUserTool::class)
            ->assertHasErrors();
    }

    public function test_list_and_get_task_as_assignee(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'title' => 'MCP visible task',
        ]);

        TaskTrackerServer::actingAs($assignee)
            ->tool(ListTasksTool::class, ['q' => 'MCP visible'])
            ->assertOk()
            ->assertSee('MCP visible task');

        TaskTrackerServer::actingAs($assignee)
            ->tool(GetTaskTool::class, ['number' => $task->number])
            ->assertOk()
            ->assertSee('MCP visible task');
    }

    public function test_comment_and_transition_tools(): void
    {
        $dept = $this->createDepartment();
        $role = $this->createRoleWithPermissions($this->defaultPermissions(), [$dept->id]);
        $initiator = $this->createUserInDepartment($dept, 'Initiator', role: $role);
        $assignee = $this->createUserInDepartment($dept, 'Assignee', role: $role);
        $task = $this->createTask($initiator, $assignee, $this->createCategory(), [
            'title' => 'MCP workflow task',
        ]);

        TaskTrackerServer::actingAs($assignee)
            ->tool(AddTaskCommentTool::class, [
                'number' => $task->number,
                'body' => 'MCP comment',
            ])
            ->assertOk()
            ->assertSee('MCP comment');

        TaskTrackerServer::actingAs($assignee)
            ->tool(TransitionTaskTool::class, [
                'number' => $task->number,
                'status' => 'in_progress',
            ])
            ->assertOk()
            ->assertSee('in_progress');
    }
}
