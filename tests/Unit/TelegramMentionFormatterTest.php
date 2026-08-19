<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\TelegramMentionFormatter;
use Tests\TestCase;

class TelegramMentionFormatterTest extends TestCase
{
    public function test_prefers_numeric_chat_id_mention(): void
    {
        $user = new User([
            'name' => 'Иван',
            'telegram_chat_id' => '12345',
            'telegram_username' => 'ivan_user',
        ]);

        $html = app(TelegramMentionFormatter::class)->mention($user);

        $this->assertSame('<a href="tg://user?id=12345">Иван</a>', $html);
    }

    public function test_falls_back_to_username(): void
    {
        $user = new User([
            'name' => 'Иван',
            'telegram_chat_id' => null,
            'telegram_username' => 'ivan_user',
        ]);

        $this->assertSame('@ivan_user', app(TelegramMentionFormatter::class)->mention($user));
    }

    public function test_falls_back_to_escaped_name(): void
    {
        $user = new User([
            'name' => 'Иван <script>',
            'telegram_chat_id' => null,
            'telegram_username' => null,
        ]);

        $this->assertSame(
            'Иван &lt;script&gt;',
            app(TelegramMentionFormatter::class)->mention($user),
        );
    }
}
