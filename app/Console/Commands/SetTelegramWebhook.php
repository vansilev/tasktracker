<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\ReminderSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Registers the bot's webhook so that Telegram also sends button presses. Without --apply it
 * only shows what is registered now and what would change. With --apply it also sets the bot's
 * command menu (the "Menu" button next to the input field) in private chats.
 */
class SetTelegramWebhook extends Command
{
    /** Messages (commands, dialog answers) and button presses — nothing else is handled. */
    public const ALLOWED_UPDATES = ['message', 'callback_query'];

    protected $signature = 'telegram:set-webhook
        {--apply : Really register the webhook; without it nothing is changed}
        {--force-url : Register even if the address differs from the one registered now}';

    protected $description = 'Register the Telegram webhook with button presses (callback_query) enabled';

    public function handle(): int
    {
        $token = config('services.telegram.token');
        $secret = config('services.telegram.webhook_secret');
        // Built from APP_URL itself: in the console a generated link may get the wrong scheme.
        $url = rtrim((string) config('app.url'), '/').route('telegram.webhook', [], false);

        if (! filled($token) || ! filled($secret)) {
            $this->error('TELEGRAM_BOT_TOKEN and TELEGRAM_WEBHOOK_SECRET must both be set: buttons are refused without the secret.');

            return self::FAILURE;
        }

        $current = $this->call_('getWebhookInfo', $token);

        if ($current === null) {
            return self::FAILURE;
        }

        $this->line('Now:     '.$this->describe($current));
        $this->line('Planned: url='.$url.' allowed_updates='.json_encode(self::ALLOWED_UPDATES));

        $menus = $this->menus();
        $this->line('Menu:    '.collect($menus)->map(fn (array $menu) => $menu['label'].' → /'.implode(' /', array_column($menu['commands'], 'command')))->implode('; '));

        $currentUrl = (string) ($current['url'] ?? '');

        if ($currentUrl !== '' && $currentUrl !== $url && ! $this->option('force-url')) {
            $this->error('The address registered now differs from this application\'s. Check APP_URL, or pass --force-url if the change is intended.');

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->info('Nothing changed. Run again with --apply to register.');

            return self::SUCCESS;
        }

        // Only what is listed here is changed; pending updates are kept (no drop_pending_updates).
        $payload = array_filter([
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => json_encode(self::ALLOWED_UPDATES),
            'max_connections' => $current['max_connections'] ?? null,
        ], fn ($value) => $value !== null);

        if ($this->call_('setWebhook', $token, $payload) === null) {
            return self::FAILURE;
        }

        $after = $this->call_('getWebhookInfo', $token);

        if ($after === null) {
            return self::FAILURE;
        }

        $this->line('After:   '.$this->describe($after));

        $ok = ($after['url'] ?? '') === $url
            && array_values((array) ($after['allowed_updates'] ?? [])) === self::ALLOWED_UPDATES;

        $ok ? $this->info('Webhook registered.') : $this->error('The webhook does not look as planned — check the line above.');

        // The menu is a convenience: a failure here is reported but does not undo the webhook.
        foreach ($ok ? $menus : [] as $menu) {
            $set = $this->call_('setMyCommands', $token, [
                'commands' => json_encode($menu['commands'], JSON_UNESCAPED_UNICODE),
                'scope' => json_encode($menu['scope']),
            ]);
            $this->line('Menu '.$menu['label'].': '.($set === null ? 'NOT set' : 'set'));
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Who gets which command menu. /svodka is offered only to those it answers (administrators
     * and digest recipients). During a pilot only the pilot people get a menu at all, so nobody
     * else sees a change in their chat with the bot.
     *
     * @return list<array{label: string, scope: array<string, mixed>, commands: list<array{command: string, description: string}>}>
     */
    private function menus(): array
    {
        $settings = app(ReminderSettings::class);
        $pilot = $settings->pilotUserIds();
        $recipients = $settings->digestRecipientIds();
        $command = fn (string $name, string $locale) => [
            'command' => $name,
            'description' => __('notification.bot.command_'.$name, [], $locale),
        ];

        $menus = $pilot === [] ? [[
            'label' => 'all private chats',
            'scope' => ['type' => 'all_private_chats'],
            'commands' => [$command('cancel', (string) config('app.locale'))],
        ]] : [];

        $people = User::query()->where('is_active', true)->whereNotNull('telegram_chat_id')->orderBy('id')->get()
            ->filter(fn (User $user) => $pilot === [] || in_array($user->id, $pilot, true));

        foreach ($people as $user) {
            $digest = $user->isAdmin() || in_array($user->id, $recipients, true);

            // Outside a pilot the common menu already covers those without /svodka.
            if (! $digest && $pilot === []) {
                continue;
            }

            $locale = $user->preferredLocale();
            $menus[] = [
                'label' => 'user '.$user->id,
                'scope' => ['type' => 'chat', 'chat_id' => (int) $user->telegram_chat_id],
                'commands' => array_values(array_filter([$digest ? $command('svodka', $locale) : null, $command('cancel', $locale)])),
            ];
        }

        return $menus;
    }

    /** @param  array<string, mixed>  $info */
    private function describe(array $info): string
    {
        return 'url='.($info['url'] ?? '').' allowed_updates='.json_encode($info['allowed_updates'] ?? 'default (all but member updates)')
            .' pending='.($info['pending_update_count'] ?? 0)
            .' max_connections='.($info['max_connections'] ?? '—')
            .(isset($info['last_error_message']) ? ' last_error="'.$info['last_error_message'].'"' : '');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function call_(string $method, string $token, array $payload = []): ?array
    {
        try {
            $response = Http::timeout(15)->asForm()->post("https://api.telegram.org/bot{$token}/{$method}", $payload);
        } catch (Throwable $e) {
            // The URL in the error text carries the token.
            $this->error("Telegram {$method} failed: ".preg_replace('/bot\d+:[A-Za-z0-9_-]+/', 'bot***', $e->getMessage()));

            return null;
        }

        if ($response->json('ok') !== true) {
            $this->error("Telegram {$method} refused: ".$response->status().' '.$response->json('description'));

            return null;
        }

        $result = $response->json('result');

        return is_array($result) ? $result : [];
    }
}
