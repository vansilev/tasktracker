<?php

namespace App\Http\Controllers;

use App\Models\TaskAttachment;
use App\Services\TaskAttachmentService;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TaskAttachmentController extends Controller
{
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
