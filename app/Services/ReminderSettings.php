<?php

namespace App\Services;

/**
 * Typed access to the Telegram reminder settings (admin → System settings).
 * Defaults live in config/tasktracker.php; values saved by an admin win.
 */
class ReminderSettings
{
    /** @var list<string> */
    public const KEYS = [
        'reminder_enabled',
        'reminder_threshold_days',
        'reminder_morning_time',
        'reminder_digest_time',
        'reminder_work_start',
        'reminder_work_end',
        'reminder_work_days',
        'reminder_escalation_after_days',
        'reminder_escalation_repeat_days',
        'reminder_stale_work_days',
        'reminder_mention_repeat_work_days',
        'reminder_mention_digest_work_days',
        'reminder_digest_recipient_ids',
        'reminder_excluded_user_ids',
        'reminder_no_escalation_head_ids',
    ];

    public function __construct(private SettingsService $settings) {}

    /**
     * Master switch for the morning reminders and escalations. Off until an admin turns it on.
     */
    public function enabled(): bool
    {
        return filter_var($this->settings->get('reminder_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Days before the deadline on which a reminder goes out, largest first. 0 = the deadline day.
     *
     * @return list<int>
     */
    public function thresholdDays(): array
    {
        $days = $this->intList('reminder_threshold_days');
        rsort($days);

        return $days;
    }

    public function morningTime(): string
    {
        return $this->time('reminder_morning_time', '09:30');
    }

    public function digestTime(): string
    {
        return $this->time('reminder_digest_time', '10:00');
    }

    public function workStart(): string
    {
        return $this->time('reminder_work_start', '09:00');
    }

    public function workEnd(): string
    {
        return $this->time('reminder_work_end', '19:00');
    }

    /**
     * ISO weekdays, 1 = Monday … 7 = Sunday.
     *
     * @return list<int>
     */
    public function workDays(): array
    {
        $days = array_values(array_filter(
            $this->intList('reminder_work_days'),
            fn (int $day) => $day >= 1 && $day <= 7,
        ));
        sort($days);

        return $days === [] ? [1, 2, 3, 4, 5] : $days;
    }

    public function escalationAfterDays(): int
    {
        return $this->positiveInt('reminder_escalation_after_days', 3);
    }

    public function escalationRepeatDays(): int
    {
        return $this->positiveInt('reminder_escalation_repeat_days', 7);
    }

    public function staleWorkDays(): int
    {
        return $this->positiveInt('reminder_stale_work_days', 5);
    }

    public function mentionRepeatWorkDays(): int
    {
        return $this->positiveInt('reminder_mention_repeat_work_days', 1);
    }

    public function mentionDigestWorkDays(): int
    {
        return $this->positiveInt('reminder_mention_digest_work_days', 2);
    }

    /** @return list<int> */
    public function digestRecipientIds(): array
    {
        return $this->intList('reminder_digest_recipient_ids');
    }

    /** @return list<int> */
    public function excludedUserIds(): array
    {
        return $this->intList('reminder_excluded_user_ids');
    }

    /** @return list<int> */
    public function noEscalationHeadIds(): array
    {
        return $this->intList('reminder_no_escalation_head_ids');
    }

    /** @return list<int> */
    private function intList(string $key): array
    {
        $value = $this->settings->get($key, []);

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_map(
            'intval',
            array_filter($value, fn ($item) => is_numeric($item) && (int) $item >= 0),
        )));
    }

    private function positiveInt(string $key, int $default): int
    {
        $value = $this->settings->get($key, $default);

        return is_numeric($value) && (int) $value >= 1 ? (int) $value : $default;
    }

    private function time(string $key, string $default): string
    {
        $value = $this->settings->get($key, $default);

        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : $default;
    }
}
