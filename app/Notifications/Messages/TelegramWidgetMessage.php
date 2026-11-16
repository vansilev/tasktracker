<?php

namespace App\Notifications\Messages;

use App\Support\TelegramWidget;
use Illuminate\Support\Facades\Log;
use NotificationChannels\Telegram\Contracts\TelegramSenderContract;
use NotificationChannels\Telegram\Exceptions\CouldNotSendNotification;
use NotificationChannels\Telegram\TelegramBase;
use NotificationChannels\Telegram\TelegramMessage;
use Psr\Http\Message\ResponseInterface;

/**
 * A notification sent as an HTML widget (Bot API sendWidget), with the old text message as the
 * fallback: when the widget does not fit Telegram's limits or Telegram refuses it, the person
 * still gets the same news as text. Works through the package's Telegram channel, so delivery
 * logging and failure handling stay exactly as they were.
 */
class TelegramWidgetMessage extends TelegramBase implements TelegramSenderContract
{
    /**
     * @param  list<list<array<string, mixed>>>  $keyboard  buttons under the widget
     */
    public static function make(string $html, string $captionHtml, TelegramMessage $fallback, array $keyboard = []): self
    {
        $message = new self;
        $message->fallback = $fallback;
        $message->payload = array_filter([
            'html' => $html,
            'caption' => $captionHtml,
            'parse_mode' => 'HTML',
            'reply_markup' => $keyboard === [] ? null : json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE),
        ], fn ($value) => $value !== null);

        return $message;
    }

    public TelegramMessage $fallback;

    public function send(): ResponseInterface|array|null
    {
        $payload = $this->toArray();

        if (! TelegramWidget::fits((string) $payload['html'])) {
            Log::info('Telegram widget too large, sent as text instead.');

            return $this->sendFallback();
        }

        try {
            // The package has no sendWidget method; its request helper is protected, so it is
            // called on the same client the package uses (and the tests replace).
            $telegram = $this->telegram;

            return (fn () => $this->sendRequest('sendWidget', $payload))->call($telegram);
        } catch (CouldNotSendNotification $e) {
            // Only a refusal: after a timeout the widget may have arrived, and a text on top
            // of it would be a double message. That case fails as any message would.
            if (! str_contains($e->getMessage(), 'responded with an error')) {
                throw $e;
            }

            Log::warning('Telegram widget refused, sent as text instead: '.preg_replace('/bot\d+:[A-Za-z0-9_-]+/', 'bot***', mb_strimwidth($e->getMessage(), 0, 300, '…')));

            return $this->sendFallback();
        }
    }

    private function sendFallback(): ResponseInterface|array|null
    {
        $this->fallback->to($this->getPayloadValue('chat_id'));

        return $this->fallback->send();
    }
}
