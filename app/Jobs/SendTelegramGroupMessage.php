<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendTelegramGroupMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $html) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 30, 60, 120];
    }

    public function handle(): void
    {
        if (! config('services.telegram.group_enabled')) {
            return;
        }

        $token = config('services.telegram.token');
        $chatId = config('services.telegram.group_chat_id');
        $threadId = config('services.telegram.group_message_thread_id');

        if (! filled($token) || ! filled($chatId) || ! filled($threadId)) {
            Log::warning('Telegram group message skipped: token, chat_id or message_thread_id is missing.');

            return;
        }

        $response = Http::timeout(10)->post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $chatId,
            'message_thread_id' => (int) $threadId,
            'text' => $this->html,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);

        if ($response->status() === 429) {
            $retryAfter = (int) ($response->json('parameters.retry_after') ?? 30);
            $this->release(max(1, $retryAfter));

            return;
        }

        if ($response->serverError()) {
            throw new RequestException($response);
        }

        if ($response->failed() || $response->json('ok') !== true) {
            Log::warning('Telegram group message failed.', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        }
    }
}
