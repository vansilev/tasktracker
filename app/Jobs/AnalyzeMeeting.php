<?php

namespace App\Jobs;

use App\Models\Meeting;
use App\Services\Meetings\MeetingTaskExtractor;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

/**
 * Reads a meeting with the model: 1–4 minutes. Prod PHP-FPM stops a request after
 * 30 s and the queue worker after 90 s (and would hold up notifications), so the
 * page dispatches this after the response: the browser gets its answer at once,
 * the FPM worker carries on, the page polls `analysis_started_at`.
 */
class AnalyzeMeeting
{
    use Dispatchable;

    public function __construct(public int $meetingId) {}

    public function handle(MeetingTaskExtractor $extractor): void
    {
        @set_time_limit(600);
        ignore_user_abort(true);

        $meeting = Meeting::query()->find($this->meetingId);
        if (! $meeting) {
            return;
        }

        try {
            $extractor->analyze($meeting);
        } catch (Throwable $e) {
            report($e);
        } finally {
            Meeting::query()->whereKey($this->meetingId)->update(['analysis_started_at' => null]);
        }
    }
}
