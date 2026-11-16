<?php

namespace Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use NotificationChannels\Telegram\Telegram;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The Telegram notification channel talks through its own Guzzle client, which
        // Http::fake() does not cover: no test may reach the real Bot API.
        $this->app->bind(Telegram::class, fn () => new Telegram('test-token', new Client([
            'handler' => fn () => \GuzzleHttp\Promise\Create::promiseFor(
                new Response(200, [], '{"ok":true,"result":{"message_id":1}}'),
            ),
        ])));
    }
}
