<?php

namespace App\Support;

/**
 * Buttons under a widget that need typed text — a reply, a comment, a new deadline, a reason —
 * open the tracker's small window inside Telegram (a Mini App) instead of the bot's "write your
 * answer" question. Telegram opens such a window only from an https address; anywhere else the
 * buttons stay as they were.
 */
class TelegramAppLinks
{
    /** callback prefix => window mode and the parameter the id goes into */
    private const MODES = [
        'c' => ['comment', 'task'],
        'd' => ['deadline', 'task'],
        'm' => ['status', 'task'],
        'p' => ['reply', 'notice'],
        'r' => ['reject', 'req'],
    ];

    public static function url(string $mode, array $ids): ?string
    {
        $url = route('telegram.app', ['m' => $mode] + array_filter($ids));

        return str_starts_with($url, 'https://') ? $url : null;
    }

    /**
     * The keyboard of a widget: typing buttons open the window, the link row goes (the widget
     * has its own "open" and "copy" inside), everything else stays.
     *
     * @param  list<list<array<string, mixed>>>  $rows
     * @return list<list<array<string, mixed>>>
     */
    public static function widgetKeyboard(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            if ($row !== [] && collect($row)->every(fn (array $button) => isset($button['url']) || isset($button['copy_text']))) {
                continue;
            }

            $out[] = array_map(fn (array $button) => self::button($button), $row);
        }

        return $out;
    }

    /** @param  array<string, mixed>  $button */
    private static function button(array $button): array
    {
        $data = (string) ($button['callback_data'] ?? '');
        $parts = explode(':', $data);

        if (! isset(self::MODES[$parts[0]]) || ! isset($parts[1]) || ! ctype_digit($parts[1])) {
            return $button;
        }

        [$mode, $param] = self::MODES[$parts[0]];
        $url = self::url($mode, [$param => (int) $parts[1]]);

        if ($url === null) {
            return $button;
        }

        unset($button['callback_data']);

        return $button + ['web_app' => ['url' => $url]];
    }
}
