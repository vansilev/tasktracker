<?php

namespace App\Console\Commands;

use App\Services\CommentNoticeSender;
use Illuminate\Console\Command;

class SendCommentNotices extends Command
{
    protected $signature = 'tasks:send-comment-notices';

    protected $description = 'Send comments and mentions to people in Telegram and follow up on unanswered mentions';

    public function handle(CommentNoticeSender $sender): int
    {
        $stats = $sender->run();

        if (array_sum($stats) > 0) {
            $this->info(sprintf(
                'Sent: %d, skipped: %d, failed: %d, reminders: %d, notes to authors: %d',
                $stats['sent'], $stats['skipped'], $stats['failed'], $stats['reminders'], $stats['author_notes'],
            ));
        }

        return self::SUCCESS;
    }
}
