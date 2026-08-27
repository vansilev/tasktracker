<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TaskApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function index(Request $request, TaskApiService $api): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'tab' => ['nullable', 'in:all,assigned,created,watching,department'],
            'status' => ['nullable', 'string', 'max:50'],
            'open' => ['nullable', 'boolean'],
            'overdue' => ['nullable', 'boolean'],
            'urgent' => ['nullable', 'boolean'],
            'department_id' => ['nullable', 'integer'],
            'department' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:255'],
            'assignee_id' => ['nullable', 'integer'],
            'assignee_email' => ['nullable', 'email'],
            'initiator_id' => ['nullable', 'integer'],
            'initiator_email' => ['nullable', 'email'],
            'parent_number' => ['nullable', 'integer'],
            'parents_only' => ['nullable', 'boolean'],
            'priority_min' => ['nullable', 'integer', 'min:1', 'max:10'],
            'priority_max' => ['nullable', 'integer', 'min:1', 'max:10'],
            'sort' => ['nullable', 'in:priority,deadline,created_at,title,number,status'],
            'dir' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($api->list($request->user(), $filters));
    }

    public function show(Request $request, int $number, TaskApiService $api): JsonResponse
    {
        return response()->json([
            'data' => $api->show($request->user(), $number),
        ]);
    }

    public function store(Request $request, TaskApiService $api): JsonResponse
    {
        $payload = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'department_id' => ['nullable', 'integer'],
            'department' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:255'],
            'assignee_id' => ['nullable', 'integer'],
            'assignee_email' => ['nullable', 'email'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:10'],
            'deadline' => ['nullable', 'date'],
            'spec_url' => ['nullable', 'url', 'max:2048'],
            'checklist' => ['nullable', 'array'],
            'checklist.*' => ['string', 'max:500'],
            'watcher_ids' => ['nullable', 'array'],
            'watcher_ids.*' => ['integer'],
            'watcher_emails' => ['nullable', 'array'],
            'watcher_emails.*' => ['email'],
            'parent_number' => ['nullable', 'integer'],
        ]);

        return response()->json([
            'data' => $api->create($request->user(), $payload),
        ], 201);
    }

    public function comment(Request $request, int $number, TaskApiService $api): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'min:1'],
        ]);

        return response()->json([
            'data' => $api->comment($request->user(), $number, $data['body']),
        ], 201);
    }

    public function transition(Request $request, int $number, TaskApiService $api): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string'],
            'comment' => ['nullable', 'string'],
        ]);

        return response()->json([
            'data' => $api->transition($request->user(), $number, $data['status'], $data['comment'] ?? null),
        ]);
    }
}
