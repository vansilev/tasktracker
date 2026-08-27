<?php

namespace App\Notifications;

use App\Models\BillingItem;
use App\Models\User;
use App\Notifications\Concerns\ResolvesNotificationChannels;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use NotificationChannels\Telegram\TelegramMessage;

class BillingDueNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use ResolvesNotificationChannels;
    use SerializesModels;

    public function __construct(
        public BillingItem $item,
        public string $event,
    ) {}

    protected function notificationEvent(): string
    {
        return $this->event;
    }

    public function toMail(object $notifiable): MailMessage
    {
        /** @var User $notifiable */
        return (new MailMessage)
            ->subject($this->line($notifiable))
            ->line($this->line($notifiable))
            ->action(__('billing.open_billing', [], $this->recipientLocale($notifiable)), $this->url());
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        /** @var User $notifiable */
        return TelegramMessage::create()
            ->normal()
            ->content($this->line($notifiable))
            ->button(__('billing.open_billing', [], $this->recipientLocale($notifiable)), $this->url());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'event' => $this->event,
            'billing_item_id' => $this->item->id,
            'title' => $this->item->title(),
            'next_due_on' => $this->item->next_due_on?->toDateString(),
        ];
    }

    private function line(User $notifiable): string
    {
        $key = match ($this->event) {
            'billing.due_3' => 'billing.notify_due_3',
            'billing.overdue' => 'billing.notify_overdue',
            default => 'billing.notify_due_7',
        };

        $locale = $this->recipientLocale($notifiable);

        return __($key, [
            'title' => $this->item->title(),
            'amount' => $this->item->formattedAmount(),
            'date' => $this->item->next_due_on?->timezone(config('app.timezone'))->format('d.m.Y'),
        ], $locale);
    }

    private function recipientLocale(User $notifiable): string
    {
        return $notifiable->locale ?: config('app.locale');
    }

    private function url(): string
    {
        if ($this->item->last_task_id) {
            return route('tasks.show', $this->item->last_task_id);
        }

        return route('billing.show', $this->item);
    }
}
