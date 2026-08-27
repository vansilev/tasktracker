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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_task')]
#[Description('Full task card by public number: description, comments, checklist, history, allowed_transitions. Example: number=224.')]
#[IsReadOnly]
class GetTaskTool extends Tool
{
    public function handle(Request $request, TaskApiService $api): ResponseFactory
    {
        $user = $api->actor($request->user() instanceof User ? $request->user() : null);
        $data = $request->validate([
            'number' => ['required', 'integer', 'min:1'],
        ]);

        return Response::structured($api->show($user, (int) $data['number']));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'number' => $schema->integer()->description('Public task number, e.g. 224.')->required(),
        ];
    }
}
