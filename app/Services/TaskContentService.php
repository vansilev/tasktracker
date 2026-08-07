<?php

namespace App\Services;

use App\Enums\ContentFormat;

/**
 * Format-aware task/comment content: rendering, plain-textarea → HTML storage,
 * and reverse for edit forms until the TipTap editor lands.
 *
 * Write path note: fromUserInput() currently uses HtmlContentService::fromPlainText()
 * because inputs are still plain <textarea>s. Flip the call site inside that method
 * to sanitize() when the WYSIWYG editor starts submitting HTML.
 */
class TaskContentService
{
    public function __construct(
        private HtmlContentService $html,
        private MarkdownService $markdown,
    ) {}

    public function render(?string $content, ContentFormat|string|null $format): string
    {
        if ($content === null || $content === '') {
            return '';
        }

        $resolved = $this->resolveFormat($format);

        if ($resolved === ContentFormat::Html) {
            // Stored HTML is untrusted: query-builder writes, quiet saves, and
            // restored backups bypass Eloquent sanitize-on-write hooks.
            return $this->html->sanitize($content);
        }

        return $this->markdown->toHtml($content);
    }

    /**
     * Convert plain textarea input into stored HTML.
     * TIP TAP FLIP POINT: change fromPlainText → sanitize when the editor lands.
     */
    public function fromUserInput(?string $plainText): string
    {
        return $this->html->fromPlainText($plainText);
    }

    /**
     * Present stored content in a plain textarea without double-encoding on save.
     * Markdown rows are returned as-is; HTML rows are roughly reversed to text.
     */
    public function toEditablePlainText(?string $content, ContentFormat|string|null $format): string
    {
        if ($content === null || $content === '') {
            return '';
        }

        if ($this->resolveFormat($format) !== ContentFormat::Html) {
            return $content;
        }

        $text = $content;

        // Preserve href when visible text differs; emit URL once when they match.
        $text = preg_replace_callback(
            '/<a\b([^>]*)>(.*?)<\/a>/is',
            function (array $matches): string {
                $attrs = $matches[1];
                $visible = trim(html_entity_decode(
                    strip_tags($matches[2]),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8',
                ));

                if (! preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $attrs, $hrefMatch)) {
                    return $visible;
                }

                $href = html_entity_decode($hrefMatch[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if ($href === '' || $visible === '' || $visible === $href) {
                    return $href !== '' ? $href : $visible;
                }

                return $visible.' ('.$href.')';
            },
            $text,
        ) ?? $text;

        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/p>\s*<p\b[^>]*>/i', "\n\n", $text) ?? $text;
        $text = preg_replace('/<\/?p\b[^>]*>/i', '', $text) ?? $text;

        // Compact TipTap-style markup has no newlines between block tags; insert
        // separators before strip_tags so list items / headings / rows do not glue.
        $text = preg_replace(
            '#</(?:li|h[1-6]|tr|blockquote)>#i',
            "$0\n",
            $text,
        ) ?? $text;

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return $text;
    }

    private function resolveFormat(ContentFormat|string|null $format): ContentFormat
    {
        if ($format instanceof ContentFormat) {
            return $format;
        }

        if (is_string($format) && $format !== '') {
            return ContentFormat::tryFrom($format) ?? ContentFormat::Markdown;
        }

        return ContentFormat::Markdown;
    }
}
