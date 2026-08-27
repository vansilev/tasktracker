<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\TaskApiService;
use App\Services\TaskPresenter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_current_user')]
#[Description('Who the API token belongs to: id, name, email, department, admin flag.')]
#[IsReadOnly]
class GetCurrentUserTool extends Tool
{
    public function handle(Request $request, TaskApiService $api, TaskPresenter $presenter): ResponseFactory
    {
        $user = $api->actor($request->user() instanceof User ? $request->user() : null);

        return Response::structured($presenter->me($user));
    }
}
