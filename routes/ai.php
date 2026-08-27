<?php

use App\Http\Middleware\EnsureApiUserIsActive;
use App\Mcp\Servers\TaskTrackerServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', TaskTrackerServer::class)
    ->middleware(['auth:sanctum', EnsureApiUserIsActive::class, 'throttle:60,1']);

Mcp::local('tasktracker', TaskTrackerServer::class);
