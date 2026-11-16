<?php

namespace App\Services\Meetings;

use App\Enums\ContentSource;
use App\Models\Meeting;
use App\Models\MeetingTaskDraft;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskService;
use Illuminate\Validation\ValidationException;

/** Turns confirmed drafts into tracker tasks through the normal TaskService path (rights, history, notifications). */
class MeetingTaskCreator
{
    public function __construct(private TaskService $tasks) {}

    public function create(User $actor, MeetingTaskDraft $draft, int $categoryId, int $priority = 5): Task
    {
        if ($draft->status !== MeetingTaskDraft::STATUS_DRAFT) {
            throw ValidationException::withMessages(['draft' => __('meetings.already_handled')]);
        }

        $assignee = $draft->assignee;
        if (! $assignee || ! $assignee->is_active) {
            throw ValidationException::withMessages(['draft' => __('meetings.pick_assignee')]);
        }
        if (! $assignee->department_id) {
            throw ValidationException::withMessages(['draft' => __('meetings.assignee_no_department', ['name' => $assignee->name])]);
        }

        $meeting = $draft->meeting;

        $watchers = array_values(array_unique(array_filter([
            // The person who was named but missed the meeting should still see the task.
            $draft->mentioned_user_id !== $assignee->id ? $draft->mentioned_user_id : null,
        ])));

        $task = $this->tasks->create(
            $actor,
            [
                'title' => $draft->title,
                'description' => $this->description($draft, $meeting),
                'priority' => $priority,
                'department_id' => $assignee->department_id,
                'category_id' => $categoryId,
                'assignee_id' => $assignee->id,
                'deadline' => $draft->deadline?->toDateString(),
            ],
            [],
            $watchers,
            ContentSource::Editor,
        );

        $draft->update([
            'status' => MeetingTaskDraft::STATUS_CREATED,
            'task_id' => $task->id,
            'created_by' => $actor->id,
        ]);

        return $task;
    }

    private function description(MeetingTaskDraft $draft, Meeting $meeting): string
    {
        $e = fn (?string $v) => e((string) $v);
        $html = '';

        if ($draft->description) {
            $html .= '<p>'.$e($draft->description).'</p>';
        }

        if ($draft->quote) {
            $who = $draft->speaker_name ? ' — '.$e($draft->speaker_name) : '';
            $at = $draft->start_seconds !== null ? ', '.Meeting::formatSeconds($draft->start_seconds) : '';
            $html .= '<blockquote><p>«'.$e($draft->quote).'»'.$who.$at.'</p></blockquote>';
        }

        if ($draft->assignment_reason === 'absent_speaker' && $draft->mentioned_name) {
            $html .= '<p>'.$e(__('meetings.absent_note', ['name' => $draft->mentionedUser?->name ?? $draft->mentioned_name])).'</p>';
        }

        if ($draft->deadline_phrase) {
            $html .= '<p>'.$e(__('meetings.deadline_said', ['phrase' => $draft->deadline_phrase])).'</p>';
        }

        $html .= '<p>'.$e(__('meetings.from_meeting')).' <a href="'.$e(route('meetings.show', $meeting)).'">'
            .$e($meeting->title).', '.$meeting->started_at->timezone('Europe/Kyiv')->format('d.m.Y').'</a>'
            .' · <a href="'.$e($meeting->firefliesUrl($draft->start_seconds)).'">'.$e(__('meetings.recording_moment')).'</a></p>';

        return $html;
    }
}
