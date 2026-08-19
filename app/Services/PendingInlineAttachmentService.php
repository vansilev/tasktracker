<?php

namespace App\Services;

use App\Models\PendingInlineAttachment;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PendingInlineAttachmentService
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

    /**
     * Store a create-page inline file before a Task row exists.
     * Promoted into TaskAttachment on task create (see promoteReferencedInHtml).
     */
    public function store(User $user, UploadedFile $file): PendingInlineAttachment
    {
        Gate::forUser($user)->authorize('create', Task::class);

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
            'pending/'.$user->id,
            Str::uuid()->toString().'_'.$file->getClientOriginalName(),
            'attachments',
        );

        return PendingInlineAttachment::query()->create([
            'uploaded_by' => $user->id,
            'filename' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $mime,
            'size' => (int) $file->getSize(),
        ]);
    }

    public function downloadPath(PendingInlineAttachment $pending, User $user): string
    {
        if ((int) $pending->uploaded_by !== (int) $user->id) {
            throw new AuthorizationException;
        }

        if (! Storage::disk('attachments')->exists($pending->path)) {
            throw ValidationException::withMessages([
                'file' => [__('task.attachment_not_found')],
            ]);
        }

        return Storage::disk('attachments')->path($pending->path);
    }

    /**
     * Move pending files referenced in HTML onto the new task and rewrite URLs
     * to /tasks/attachments/{id}/(view|download). Unreferenced pendings are left
     * for TTL cleanup.
     */
    public function promoteReferencedInHtml(string $html, Task $task, User $user): string
    {
        if ($html === '' || ! str_contains($html, '/pending-attachments/')) {
            return $html;
        }

        if (! preg_match_all('#/pending-attachments/(\d+)/(view|download)#i', $html, $matches)) {
            return $html;
        }

        $ids = array_values(array_unique(array_map('intval', $matches[1])));
        $pendings = PendingInlineAttachment::query()
            ->whereIn('id', $ids)
            ->where('uploaded_by', $user->id)
            ->get()
            ->keyBy('id');

        foreach ($pendings as $pending) {
            $attachment = $this->promoteOne($pending, $task, $user);
            $html = str_replace(
                [
                    '/pending-attachments/'.$pending->id.'/view',
                    '/pending-attachments/'.$pending->id.'/download',
                ],
                [
                    '/tasks/attachments/'.$attachment->id.'/view',
                    '/tasks/attachments/'.$attachment->id.'/download',
                ],
                $html,
            );
        }

        return $html;
    }

    private function promoteOne(PendingInlineAttachment $pending, Task $task, User $user): TaskAttachment
    {
        $disk = Storage::disk('attachments');
        $newPath = 'tasks/'.$task->id.'/'.basename($pending->path);

        if ($disk->exists($pending->path)) {
            $disk->move($pending->path, $newPath);
        } else {
            $newPath = $pending->path;
        }

        $attachment = TaskAttachment::query()->create([
            'task_id' => $task->id,
            'comment_id' => null,
            'filename' => $pending->filename,
            'path' => $newPath,
            'mime' => $pending->mime,
            'size' => $pending->size,
            'uploaded_by' => $user->id,
        ]);

        $pending->delete();

        return $attachment;
    }
}
