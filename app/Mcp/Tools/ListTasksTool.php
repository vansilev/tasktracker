<?php

namespace App\Mcp\Tools;

use App\Enums\TaskStatus;
use App\Models\User;
use App\Services\TaskApiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_tasks')]
#[Description('Search and filter tasks visible to the token owner. Prefer this before guessing a task number. Default: open tasks, tab=all.')]
#[IsReadOnly]
class ListTasksTool extends Tool
{
    public function handle(Request $request, TaskApiService $api): ResponseFactory
    {
        $user = $api->actor($request->user() instanceof User ? $request->user() : null);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'tab' => ['nullable', 'in:all,assigned,created,watching,department,action'],
            'status' => ['nullable', 'string', 'max:50'],
            'open' => ['nullable', 'boolean'],
            'overdue' => ['nullable', 'boolean'],
            'urgent' => ['nullable', 'boolean'],
            'department' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'assignee_email' => ['nullable', 'email'],
            'initiator_email' => ['nullable', 'email'],
            'parent_number' => ['nullable', 'integer'],
            'sort' => ['nullable', 'in:priority,deadline,created_at,title,number,status'],
            'dir' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        if (! empty($filters['status'])) {
            $status = TaskStatus::tryFrom($filters['status']);
            if ($status !== null && ! $status->isOpen()) {
                $filters['open'] = false;
            }
        } elseif (! array_key_exists('open', $filters) || $filters['open'] === null) {
            $q = trim((string) ($filters['q'] ?? ''));
            $filters['open'] = $q === '' || ! ctype_digit($q);
        }
        $filters['tab'] ??= 'all';
        $filters['per_page'] ??= 20;

        return Response::structured($api->list($user, $filters));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'q' => $schema->string()->description('Search number, title, description, comments.'),
            'tab' => $schema->string()->enum(['all', 'assigned', 'created', 'watching', 'department', 'action'])->description('assigned = my tasks as executor. action = needs my action.'),
            'status' => $schema->string()->description('Exact status value, e.g. in_progress. If set, overrides default open=true.'),
            'open' => $schema->boolean()->description('Only open statuses. Default true. Set false to include completed/rejected/cancelled.'),
            'overdue' => $schema->boolean(),
            'urgent' => $schema->boolean()->description('Priority >= 9.'),
            'department' => $schema->string()->description('Department name.'),
            'category' => $schema->string()->description('Category name.'),
            'assignee_email' => $schema->string()->description('Executor email.'),
            'initiator_email' => $schema->string()->description('Initiator email.'),
            'parent_number' => $schema->integer()->description('Only subtasks of this parent number.'),
            'sort' => $schema->string()->enum(['priority', 'deadline', 'created_at', 'title', 'number', 'status']),
            'dir' => $schema->string()->enum(['asc', 'desc']),
            'per_page' => $schema->integer()->description('1–50, default 20.'),
        ];
    }
}
