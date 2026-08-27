<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\TaskApiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create_task')]
#[Description('Create a task. Requires title plus department and category (name or id). Only when the user asked to create a task. Call list_catalogs first if names are unknown.')]
class CreateTaskTool extends Tool
{
    public function handle(Request $request, TaskApiService $api): ResponseFactory
    {
        $user = $api->actor($request->user() instanceof User ? $request->user() : null);
        $payload = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'department' => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer'],
            'assignee_email' => ['nullable', 'email'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:10'],
            'deadline' => ['nullable', 'date'],
            'spec_url' => ['nullable', 'url', 'max:2048'],
            'checklist' => ['nullable', 'array'],
            'checklist.*' => ['string', 'max:500'],
            'watcher_emails' => ['nullable', 'array'],
            'watcher_emails.*' => ['email'],
            'parent_number' => ['nullable', 'integer'],
        ]);

        return Response::structured($api->create($user, $payload));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('Task title.')->required(),
            'description' => $schema->string()->description('Plain-text description.'),
            'department' => $schema->string()->description('Department name. Required unless department_id is set.'),
            'department_id' => $schema->integer(),
            'category' => $schema->string()->description('Category name. Required unless category_id is set.'),
            'category_id' => $schema->integer(),
            'assignee_email' => $schema->string()->description('Executor email. Omit for auto-assign.'),
            'priority' => $schema->integer()->description('1–10, default 5. Urgent is >= 9.'),
            'deadline' => $schema->string()->description('Date or datetime.'),
            'spec_url' => $schema->string(),
            'checklist' => $schema->array()->items($schema->string())->description('Checklist item texts.'),
            'watcher_emails' => $schema->array()->items($schema->string()),
            'parent_number' => $schema->integer()->description('Create as a subtask of this parent.'),
        ];
    }
}
