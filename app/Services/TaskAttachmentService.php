<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TaskAttachmentService
{
    /** @var list<string> */
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    public function store(Task $task, User $user, UploadedFile $file, ?int $commentId = null, bool $writeHistory = true): TaskAttachment
    {
        Gate::forUser($user)->authorize('uploadAttachment', $task);

        $maxKb = (int) app(SettingsService::class)->get('attachment_max_kb', 10240);
        $maxBytes = $maxKb * 1024;

        if ($file->getSize() > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => [__('task.attachment_too_large', ['max' => $maxKb])],
            ]);
        }

        $mime = $file->getMimeType() ?: 'application/octet-stream';

        if (! in_array($mime, self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages([
                'file' => [__('task.attachment_type_not_allowed')],
            ]);
        }

        $path = $file->storeAs(
            'tasks/'.$task->id,
            Str::uuid()->toString().'_'.$file->getClientOriginalName(),
            'attachments',
        );

        $attachment = TaskAttachment::query()->create([
            'task_id' => $task->id,
            'comment_id' => $commentId,
            'filename' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $mime,
            'size' => (int) $file->getSize(),
            'uploaded_by' => $user->id,
        ]);

        if ($writeHistory) {
            app(TaskWorkflowService::class)->logHistory(
                $task,
                'attachment',
                null,
                $file->getClientOriginalName(),
                $user,
            );
        }

        return $attachment;
    }

    public function delete(TaskAttachment $attachment, User $user): void
    {
        $task = $attachment->task;
        Gate::forUser($user)->authorize('deleteAttachment', [$task, $attachment]);

        $filename = $attachment->filename;

        if (Storage::disk('attachments')->exists($attachment->path)) {
            Storage::disk('attachments')->delete($attachment->path);
        }

        $attachment->delete();

        app(TaskWorkflowService::class)->logHistory($task, 'attachment', $filename, null, $user);
    }

    public function downloadPath(TaskAttachment $attachment, User $user): string
    {
        if (! Gate::forUser($user)->allows('view', $attachment->task)) {
            throw new AuthorizationException;
        }

        if (! Storage::disk('attachments')->exists($attachment->path)) {
            throw ValidationException::withMessages([
                'file' => [__('task.attachment_not_found')],
            ]);
        }

        return Storage::disk('attachments')->path($attachment->path);
    }
}
