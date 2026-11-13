<?php

namespace App\Notifications;

use App\Models\User;
use App\Notifications\Messages\TelegramWidgetMessage;
use App\Services\ReminderSettings;
use App\Support\WorkCalendar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramMessage;

/**
 * One Telegram message of the task digest. The text is built before queueing
 * (TaskDigestFormatter), so what the recipient reads is the state at the time of the run.
 */
class TaskDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Re-sending is the digest command's job (it re-claims failed deliveries), not the queue's. */
    public int $tries = 1;

    /**
     * @param  string  $date  Y-m-d the digest was built for
     * @param  int  $departmentId  0 = the whole company
     * @param  bool  $scheduled  false for /svodka: asked for by the person, so it is sent at any hour and never claimed
     */
    public function __construct(
        public string $date,
        public int $departmentId,
        public string $text,
        public int $part,
        public int $parts,
        public bool $scheduled = true,
        public ?string $widgetHtml = null,
        public ?string $widgetCaption = null,
    ) {}

    /** The scheduled digest never leaves outside working hours; the next run picks it up. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return ! $this->scheduled || app(WorkCalendar::class)->isWithinWorkHours();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        // A digest the person asked for (/svodka) is answered even outside the pilot list.
        return $notifiable instanceof User
            && filled($notifiable->telegram_chat_id)
            && (! $this->scheduled || app(ReminderSettings::class)->allowsPersonalMessages($notifiable->id))
                ? ['telegram']
                : [];
    }

    public function toTelegram(object $notifiable): TelegramMessage|TelegramWidgetMessage
    {
        $text = TelegramMessage::create()
            ->parseMode('HTML')
            ->options(['disable_web_page_preview' => true])
            ->content($this->text);

        // `??`: a digest queued before widgets existed has no such property at all.
        $html = $this->widgetHtml ?? null;

        return $html === null
            ? $text
            : TelegramWidgetMessage::make($html, (string) ($this->widgetCaption ?? ''), $text);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->scheduled ? 'task.digest' : 'task.digest_on_demand',
            'date' => $this->date,
            'part' => $this->part,
            'parts' => $this->parts,
        ];
    }
}
