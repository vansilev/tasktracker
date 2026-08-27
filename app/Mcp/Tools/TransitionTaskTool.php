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

#[Name('transition_task')]
#[Description('Change task status. Check get_task.allowed_transitions first. Some statuses require comment. Only when the user asked to change status.')]
class TransitionTaskTool extends Tool
{
    public function handle(Request $request, TaskApiService $api): ResponseFactory
    {
        $user = $api->actor($request->user() instanceof User ? $request->user() : null);
        $data = $request->validate([
            'number' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'string'],
            'comment' => ['nullable', 'string'],
        ]);

        return Response::structured($api->transition(
            $user,
            (int) $data['number'],
            $data['status'],
            $data['comment'] ?? null,
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'number' => $schema->integer()->description('Public task number.')->required(),
            'status' => $schema->string()->enum([
                'new',
                'in_progress',
                'awaiting_initiator',
                'on_review',
                'rework',
                'completed',
                'postponed',
                'rejected',
                'cancelled',
            ])->required(),
            'comment' => $schema->string()->description('Required for rejected, postponed, rework, cancelled, awaiting_initiator, and reopen from completed.'),
        ];
    }
}
