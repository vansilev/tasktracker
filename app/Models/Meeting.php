<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A meeting recorded by Fireflies (task 456). The transcript is a copy:
 * Fireflies stays the source, the tracker keeps what it needs to show
 * the meeting and to explain where each task came from.
 */
class Meeting extends Model
{
    protected $fillable = [
        'fireflies_id',
        'title',
        'started_at',
        'duration_minutes',
        'organizer_email',
        'meeting_link',
        'summary',
        'keywords',
        'fireflies_action_items',
        'attendees',
        'sentences',
        'speaker_map',
        'analyzed_at',
        'analysis_started_at',
        'analysis_model',
        'analysis_error',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'analyzed_at' => 'datetime',
            'analysis_started_at' => 'datetime',
            'keywords' => 'array',
            'attendees' => 'array',
            'sentences' => 'array',
            'speaker_map' => 'array',
        ];
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(MeetingTaskDraft::class)->orderBy('sort_order')->orderBy('id');
    }

    /** A run that started more than 10 minutes ago is treated as dead. */
    public function isBeingAnalyzed(): bool
    {
        return $this->analysis_started_at !== null && $this->analysis_started_at->gt(now()->subMinutes(10));
    }

    public function firefliesUrl(?float $seconds = null): string
    {
        $url = 'https://app.fireflies.ai/view/'.rawurlencode($this->fireflies_id);

        return $seconds !== null ? $url.'?t='.round($seconds, 2) : $url;
    }

    /** @return list<string> speaker names in order of first appearance */
    public function speakerNames(): array
    {
        $names = [];
        foreach ($this->sentences ?? [] as $sentence) {
            $name = trim((string) ($sentence['speaker'] ?? ''));
            if ($name !== '' && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public static function formatSeconds(?float $seconds): string
    {
        $seconds = (int) floor((float) $seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
    }
}
