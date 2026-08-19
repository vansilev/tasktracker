<?php

namespace App\Http\Controllers;

use App\Models\PendingInlineAttachment;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Services\PendingInlineAttachmentService;
use App\Services\SettingsService;
use App\Services\TaskAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TaskAttachmentController extends Controller
{
    /**
     * Upload an inline editor attachment without a Livewire round-trip.
     *
     * Livewire's WithFileUploads `_finishUpload` always re-renders the page;
     * that races TipTap (stale snapshot / remount) and is why paste looked like
     * a successful Network upload with nothing in the editor. This endpoint
     * returns JSON only — the client inserts into TipTap locally.
     */
    public function storeInline(
        Request $request,
        Task $task,
        TaskAttachmentService $attachments,
        SettingsService $settings,
    ): JsonResponse {
        Gate::authorize('uploadAttachment', $task);

        $maxKb = (int) $settings->get('attachment_max_kb', 10240);

        $request->validate([
            'file' => 'required|file|max:'.$maxKb,
        ]);

        $attachment = $attachments->store($task, $request->user(), $request->file('file'));

        return response()->json([
            'id' => $attachment->id,
            'filename' => $attachment->filename,
            'mime' => $attachment->mime,
            'isImage' => $attachment->isImage(),
            'viewUrl' => route('tasks.attachments.view', $attachment, absolute: false),
            'downloadUrl' => route('tasks.attachments.download', $attachment, absolute: false),
        ]);
    }

    /**
     * Create-page inline upload: no Task yet, so the file is held as pending
     * until TaskService::create promotes referenced URLs into TaskAttachment.
     */
    public function storePendingInline(
        Request $request,
        PendingInlineAttachmentService $pending,
        SettingsService $settings,
    ): JsonResponse {
        Gate::authorize('create', Task::class);

        $maxKb = (int) $settings->get('attachment_max_kb', 10240);

        $request->validate([
            'file' => 'required|file|max:'.$maxKb,
        ]);

        $attachment = $pending->store($request->user(), $request->file('file'));

        return response()->json([
            'id' => $attachment->id,
            'filename' => $attachment->filename,
            'mime' => $attachment->mime,
            'isImage' => $attachment->isImage(),
            'viewUrl' => route('pending.attachments.view', $attachment, absolute: false),
            'downloadUrl' => route('pending.attachments.download', $attachment, absolute: false),
        ]);
    }

    public function downloadPending(
        PendingInlineAttachment $pending,
        PendingInlineAttachmentService $attachments,
    ): BinaryFileResponse {
        $path = $attachments->downloadPath($pending, auth()->user());

        return Response::download($path, $pending->filename, [
            'Content-Type' => $pending->mime,
        ]);
    }

    public function viewPending(
        PendingInlineAttachment $pending,
        PendingInlineAttachmentService $attachments,
    ): BinaryFileResponse {
        if (! $pending->isImage()) {
            return $this->downloadPending($pending, $attachments);
        }

        $path = $attachments->downloadPath($pending, auth()->user());

        return Response::file($path, [
            'Content-Type' => $pending->mime,
            'Content-Disposition' => 'inline; filename="'.$pending->filename.'"',
        ]);
    }

    public function download(TaskAttachment $attachment, TaskAttachmentService $attachments): BinaryFileResponse
    {
        $path = $attachments->downloadPath($attachment, auth()->user());

        return Response::download($path, $attachment->filename, [
            'Content-Type' => $attachment->mime,
        ]);
    }

    public function view(TaskAttachment $attachment, TaskAttachmentService $attachments): BinaryFileResponse
    {
        if (! $attachment->isImage()) {
            return $this->download($attachment, $attachments);
        }

        $path = $attachments->downloadPath($attachment, auth()->user());

        return Response::file($path, [
            'Content-Type' => $attachment->mime,
            'Content-Disposition' => 'inline; filename="'.$attachment->filename.'"',
        ]);
    }
}
