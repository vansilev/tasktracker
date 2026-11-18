<?php

namespace App\Services\Meetings;

use App\Models\Meeting;
use Illuminate\Support\Carbon;

/** Copies meetings from Fireflies into the tracker; running it twice changes nothing. */
class MeetingImporter
{
    public function __construct(private FirefliesClient $fireflies) {}

    /** @return list<Meeting> */
    public function importRecent(int $limit = 10, bool $refresh = false): array
    {
        $meetings = [];
        foreach ($this->fireflies->recent($limit) as $row) {
            // A finished meeting does not change: fetch only what we do not have yet,
            // plus fresh meetings whose summary Fireflies had not finished when we took them.
            $existing = Meeting::query()->where('fireflies_id', (string) $row['id'])->first();
            $meetings[] = $existing && ! $refresh && ! $this->stillProcessing($existing)
                ? $existing
                : $this->import((string) $row['id']);
        }

        return $meetings;
    }

    private function stillProcessing(Meeting $meeting): bool
    {
        return blank($meeting->summary) && $meeting->started_at->gt(now()->subDays(3));
    }

    public function import(string $firefliesId): Meeting
    {
        $t = $this->fireflies->transcript($firefliesId);

        $sentences = [];
        foreach ($t['sentences'] ?? [] as $s) {
            $text = trim((string) ($s['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $sentences[] = [
                'start' => round((float) ($s['start_time'] ?? 0), 2),
                'end' => round((float) ($s['end_time'] ?? 0), 2),
                'speaker' => trim((string) ($s['speaker_name'] ?? '')) ?: '—',
                'text' => $text,
            ];
        }

        $attendees = [];
        foreach ($t['meeting_attendees'] ?? [] as $a) {
            $email = trim((string) ($a['email'] ?? ''));
            $name = trim((string) ($a['displayName'] ?? $a['name'] ?? ''));
            if ($email !== '' || $name !== '') {
                $attendees[] = ['email' => $email ?: null, 'name' => $name ?: null];
            }
        }

        $summary = $t['summary'] ?? [];
        $date = $t['date'] ?? null;
        $startedAt = is_numeric($date) ? Carbon::createFromTimestampMs((int) $date) : Carbon::parse((string) $date);
        // Stored in the app's time zone, like every other date column here.
        $startedAt = $startedAt->setTimezone((string) config('app.timezone'));

        return Meeting::query()->updateOrCreate(
            ['fireflies_id' => (string) $t['id']],
            [
                'title' => (string) ($t['title'] ?: 'Встреча'),
                'started_at' => $startedAt,
                'duration_minutes' => (int) round((float) ($t['duration'] ?? 0)),
                'organizer_email' => $t['organizer_email'] ?? null,
                'meeting_link' => $t['meeting_link'] ?? null,
                // short_summary is plain prose; overview comes as Markdown bullets.
                'summary' => $summary['short_summary'] ?? $summary['overview'] ?? null,
                'keywords' => array_values(array_filter((array) ($summary['keywords'] ?? []))),
                'fireflies_action_items' => $summary['action_items'] ?? null,
                'attendees' => $attendees,
                'sentences' => $sentences,
            ],
        );
    }
}
