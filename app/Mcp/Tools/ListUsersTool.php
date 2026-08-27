<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\TaskApiService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_users')]
#[Description('Active users: id, name, email, department. Use emails when creating or filtering tasks.')]
#[IsReadOnly]
class ListUsersTool extends Tool
{
    public function handle(Request $request, TaskApiService $api): ResponseFactory
    {
        $api->actor($request->user() instanceof User ? $request->user() : null);

        return Response::structured(['data' => $api->users()]);
    }
}
