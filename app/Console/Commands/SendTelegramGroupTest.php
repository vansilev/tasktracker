<?php

namespace App\Console\Commands;

use App\Jobs\SendTelegramGroupMessage;
use App\Services\TelegramGroupNotifier;
use Illuminate\Console\Command;

class SendTelegramGroupTest extends Command
{
    protected $signature = 'telegram:group-test';

    protected $description = 'Send a test message to the Task Tracker Pulse forum topic';

    public function handle(TelegramGroupNotifier $notifier): int
    {
        if (! $notifier->isReady()) {
            $this->error('Telegram group is not configured. Set TELEGRAM_GROUP_ENABLED, TELEGRAM_BOT_TOKEN, TELEGRAM_GROUP_CHAT_ID, TELEGRAM_GROUP_MESSAGE_THREAD_ID.');

            return self::FAILURE;
        }

        SendTelegramGroupMessage::dispatch(
            "<b>Herald</b>\nТестовое сообщение в топик Task Tracker | Pulse.\nЕсли вы это видите — канал работает.",
        );

        $this->info('Test message queued for the Pulse topic.');

        return self::SUCCESS;
    }
}
