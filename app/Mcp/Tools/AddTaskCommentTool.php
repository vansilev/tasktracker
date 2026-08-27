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

#[Name('add_comment')]
#[Description('Add a plain-text comment to a task. Mentions like @name work. Only when the user asked to comment.')]
class AddTaskCommentTool extends Tool
{
    public function handle(Request $request, TaskApiService $api): ResponseFactory
    {
        $user = $api->actor($request->user() instanceof User ? $request->user() : null);
        $data = $request->validate([
            'number' => ['required', 'integer', 'min:1'],
            'body' => ['required', 'string', 'min:1'],
        ]);

        return Response::structured($api->comment($user, (int) $data['number'], $data['body']));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'number' => $schema->integer()->description('Public task number.')->required(),
            'body' => $schema->string()->description('Comment text.')->required(),
        ];
    }
}
