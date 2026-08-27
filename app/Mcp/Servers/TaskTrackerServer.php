<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddTaskCommentTool;
use App\Mcp\Tools\CreateTaskTool;
use App\Mcp\Tools\GetCurrentUserTool;
use App\Mcp\Tools\GetTaskTool;
use App\Mcp\Tools\ListCatalogsTool;
use App\Mcp\Tools\ListTasksTool;
use App\Mcp\Tools\ListUsersTool;
use App\Mcp\Tools\TransitionTaskTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Task Tracker AVANT')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
Task Tracker AVANT (https://task.avant.od.ua). Identify tasks by public **number** (#224), never by internal id.

Default to read-only: get_current_user, list_tasks, get_task, list_users, list_catalogs.
Use add_comment, create_task, and transition_task only when the user explicitly asks to write.
Statuses: new, in_progress, awaiting_initiator, on_review, rework, completed, postponed, rejected, cancelled.
Some transitions require a comment (rejected, postponed, rework, cancelled, awaiting_initiator, reopen from completed).
Visibility and workflow follow the token owner's permissions.
MARKDOWN)]
class TaskTrackerServer extends Server
{
    protected array $tools = [
        GetCurrentUserTool::class,
        ListTasksTool::class,
        GetTaskTool::class,
        ListUsersTool::class,
        ListCatalogsTool::class,
        AddTaskCommentTool::class,
        CreateTaskTool::class,
        TransitionTaskTool::class,
    ];
}
