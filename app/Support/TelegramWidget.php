<?php

namespace App\Support;

/**
 * Bricks of the HTML widgets the bot sends instead of text messages (Bot API 10.4, sendWidget;
 * layouts approved by the customer on 2026-11-13). A widget is one self-contained document,
 * drawn by the Telegram app in the chat's colours, with no network access: everything it shows
 * is in the HTML. Links open the tracker after the app asks the person to confirm.
 *
 * Every brick escapes what it is given unless the parameter says it takes HTML.
 */
class TelegramWidget
{
    /** Telegram's limit is 4096 bytes after zlib at level 9; a margin keeps us clear of it. */
    public const MAX_COMPRESSED = 3900;

    public const MAX_HTML = 32000;

    private const CSS = 'body{margin:0;padding:10px 12px 12px;font-size:1em;line-height:1.45}'
        .'.hd{display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-weight:600}'
        .'.ch{font-size:.78em;font-weight:600;padding:2px 9px;border-radius:11px;color:#fff;background:#e5484d;white-space:nowrap}'
        .'.or{background:#e38b00}.bl{background:var(--tg-theme-button-color);color:var(--tg-theme-button-text-color)}.gr{background:#2f9e5f}.gy{background:var(--tg-theme-hint-color)}'
        .'.t{display:block;margin:8px 0 4px;font-weight:600;font-size:1.06em;line-height:1.3;color:var(--tg-theme-text-color);text-decoration:none}'
        .'.n{color:var(--tg-theme-link-color);font-weight:700}'
        .'.m{color:var(--tg-theme-text-color);font-size:.95em;line-height:1.55}.m b{font-weight:700}'
        .'.bx{background:var(--tg-theme-secondary-bg-color);border-radius:10px;padding:8px 10px;margin-top:8px;line-height:1.45;overflow-wrap:anywhere}'
        .'.bx .m{font-size:.88em;font-weight:600;color:var(--tg-theme-link-color);margin-bottom:2px}'
        .'.do{margin-top:9px;padding:6px 10px;border-radius:8px;border-left:3px solid var(--tg-theme-accent-text-color);background:var(--tg-theme-secondary-bg-color)}'
        .'.r{display:block;padding:7px 0;border-top:1px solid var(--tg-theme-section-separator-color);color:var(--tg-theme-text-color);text-decoration:none;line-height:1.3}.r:first-child{border-top:0}'
        .'.tb{display:flex;gap:4px;margin:10px 0 4px;background:var(--tg-theme-secondary-bg-color);border-radius:9px;padding:3px}'
        .'.tb button{flex:1;margin:0;padding:6px 2px;font-size:.88em;font-weight:600;border-radius:7px;background:transparent;color:var(--tg-theme-text-color);border:0}'
        .'.tb button.on{background:var(--tg-theme-button-color);color:var(--tg-theme-button-text-color)}'
        .'.k{display:flex;gap:6px;margin-top:9px}.k div{flex:1;text-align:center;background:var(--tg-theme-secondary-bg-color);border-radius:10px;padding:7px 2px}'
        .'.k b{display:block;font-size:1.35em}.k span{font-size:.8em}'
        .'.bar{height:8px;border-radius:4px;background:var(--tg-theme-secondary-bg-color);overflow:hidden;margin:3px 0 7px}.bar i{display:block;height:100%;background:var(--tg-theme-button-color)}'
        .'.st{display:flex;align-items:center;gap:3px;margin-top:9px;font-size:.82em}.st span{flex:1;text-align:center;padding:5px 2px;border-radius:7px;background:var(--tg-theme-secondary-bg-color)}'
        .'.st .on{background:var(--tg-theme-button-color);color:var(--tg-theme-button-text-color);font-weight:600}.st .was{text-decoration:line-through}'
        .'summary{font-weight:600;padding:6px 0}'
        .'.bs{margin-top:10px;display:flex;gap:5px}'
        .'.b{flex:1;margin:0;padding:8px 4px;border-radius:9px;border:0;font-size:.92em;font-weight:600;text-align:center;text-decoration:none;background:var(--tg-theme-secondary-bg-color);color:var(--tg-theme-link-color)}';

    private const TABS = '<script>function tab(i){document.querySelectorAll(".tb button").forEach((b,j)=>b.className=i==j?"on":"");'
        .'document.querySelectorAll(".pg").forEach((p,j)=>p.style.display=i==j?"block":"none")}tab(0)</script>';

    /** The whole document. $body is HTML built from the bricks below. */
    public static function document(string $body, string $locale): string
    {
        $tabs = str_contains($body, 'class=tb') ? self::TABS : '';

        return '<!doctype html><html lang="'.e($locale).'"><head><meta charset="utf-8"><style>'.self::CSS.'</style></head><body>'
            .$body.$tabs.'</body></html>';
    }

    /** Whether Telegram will take it: both limits, counted the way the server counts. */
    public static function fits(string $html): bool
    {
        return strlen($html) <= self::MAX_HTML
            && strlen((string) gzcompress($html, 9)) <= self::MAX_COMPRESSED
            && ! str_contains($html, "\0");
    }

    /**
     * A row of coloured labels. Each chip: [text, colour] — red (default), or, bl, gr, gy.
     *
     * @param  list<array{0: string, 1?: string}|null>  $chips
     */
    public static function chips(array $chips, ?string $plainTitle = null): string
    {
        $html = '';

        foreach (array_filter($chips) as $chip) {
            $class = ($chip[1] ?? 'red') === 'red' ? 'ch' : 'ch '.$chip[1];
            $html .= '<span class="'.$class.'">'.e($chip[0]).'</span>';
        }

        if ($plainTitle !== null) {
            $html .= '<span>'.e($plainTitle).'</span>';
        }

        return '<div class=hd>'.$html.'</div>';
    }

    public static function title(int $taskId, int $number, string $title): string
    {
        return '<a class=t href="'.e(self::taskUrl($taskId)).'"><span class=n>#'.$number.'</span> '.e(self::cut($title, 160)).'</a>';
    }

    /**
     * Lines of facts, one per entry. Entries are HTML (use e() and <b> inside).
     *
     * @param  list<string|null>  $linesHtml
     */
    public static function meta(array $linesHtml, string $style = ''): string
    {
        $lines = array_values(array_filter($linesHtml, fn (?string $line) => $line !== null && $line !== ''));

        return $lines === [] ? '' : '<div class=m'.($style === '' ? '' : ' style="'.$style.'"').'>'.implode('<br>', $lines).'</div>';
    }

    /** A quote or any boxed text: a small coloured label over it. */
    public static function box(?string $label, string $text): string
    {
        return '<div class=bx>'.($label === null ? '' : '<div class=m>'.e($label).'</div>').e($text).'</div>';
    }

    /** "What to do", or any note that needs to stand out. $html may hold <b>. */
    public static function todo(string $html): string
    {
        return '<div class=do>'.$html.'</div>';
    }

    /** One task in a list: the whole row opens it. $metaHtml is HTML. */
    public static function row(int $taskId, int $number, string $title, string $metaHtml = ''): string
    {
        return '<a class=r href="'.e(self::taskUrl($taskId)).'"><span class=n>#'.$number.'</span> '.e(self::cut($title, 90))
            .($metaHtml === '' ? '' : '<div class=m>'.$metaHtml.'</div>').'</a>';
    }

    /**
     * Tabs: [label, page HTML]. Empty pages are dropped; a single page is shown without tabs.
     *
     * @param  list<array{0: string, 1: string}>  $pages
     */
    public static function tabs(array $pages): string
    {
        $pages = array_values(array_filter($pages, fn (array $page) => $page[1] !== ''));

        if (count($pages) <= 1) {
            return $pages[0][1] ?? '';
        }

        $buttons = '';
        $bodies = '';

        foreach ($pages as $i => [$label, $html]) {
            $buttons .= '<button onclick="tab('.$i.')">'.e($label).'</button>';
            $bodies .= '<div class=pg>'.$html.'</div>';
        }

        return '<div class=tb>'.$buttons.'</div>'.$bodies;
    }

    /**
     * Big numbers in tiles: [number, label, red?].
     *
     * @param  list<array{0: int, 1: string, 2?: bool}>  $tiles
     */
    public static function tiles(array $tiles): string
    {
        $html = '';

        foreach ($tiles as $tile) {
            $html .= '<div><b'.(($tile[2] ?? false) && $tile[0] > 0 ? ' style="color:#e5484d"' : '').'>'.$tile[0].'</b><span>'.e($tile[1]).'</span></div>';
        }

        return '<div class=k>'.$html.'</div>';
    }

    /** A labelled bar, $value of $max. */
    public static function bar(string $label, int $value, int $max): string
    {
        $width = $max > 0 ? (int) round(100 * $value / $max) : 0;

        return '<div class=m>'.e($label).' · <b>'.$value.'</b></div><div class=bar><i style="width:'.$width.'%"></i></div>';
    }

    /**
     * The way a task went: earlier statuses struck through, the current one highlighted.
     *
     * @param  list<string>  $was
     */
    public static function steps(array $was, string $now): string
    {
        $html = '';

        foreach ($was as $label) {
            $html .= '<span class=was>'.e($label).'</span>→';
        }

        return '<div class=st>'.$html.'<span class=on>'.e($now).'</span></div>';
    }

    /** A collapsed block that opens on a tap. $innerHtml is HTML. */
    public static function details(string $summaryHtml, string $innerHtml): string
    {
        return '<details><summary>'.$summaryHtml.'</summary>'.$innerHtml.'</details>';
    }

    /** "Open in the tracker" and "Copy the number", side by side. */
    public static function linkButtons(int $taskId, int $number, string $locale): string
    {
        return '<div class=bs><a class=b href="'.e(self::taskUrl($taskId)).'">'.e(__('notification.bot.open_in_tracker', [], $locale)).'</a>'
            .'<button class=b onclick="TelegramWidget.copyText(\'#'.$number.'\')">'.e(__('notification.bot.copy_number', [], $locale)).'</button></div>';
    }

    public static function cut(string $text, int $limit): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit).'…' : $text;
    }

    /** The caption under a widget: plain words, at most 1024 characters as Telegram counts them. */
    public static function caption(string $text): string
    {
        return e(self::cut($text, 1000));
    }

    private static function taskUrl(int $taskId): string
    {
        return route('tasks.show', $taskId);
    }
}
