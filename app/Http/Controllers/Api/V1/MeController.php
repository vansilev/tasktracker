<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TaskApiService;
use App\Services\TaskPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function show(Request $request, TaskPresenter $presenter): JsonResponse
    {
        return response()->json([
            'data' => $presenter->me($request->user()),
        ]);
    }

    public function catalogs(TaskApiService $api): JsonResponse
    {
        return response()->json(['data' => $api->catalogs()]);
    }

    public function users(TaskApiService $api): JsonResponse
    {
        return response()->json(['data' => $api->users()]);
    }
}
