<?php

namespace App\Services;

use App\Enums\ContentFormat;
use App\Enums\ContentSource;

/**
 * Format-aware task/comment content: rendering, loading into the editor, and
 * turning submitted content into stored HTML.
 *
 * Write path: there is no single "user input" entry point, because the two
 * sources need opposite treatment. Editor markup is sanitized; plain text is
 * escaped. Callers must say which they are holding — see ContentSource.
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
     * Stored HTML from the WYSIWYG editor. Sanitized against the task_content
     * profile, which is also what render() applies, so a save is a no-op there.
     */
    public function fromEditorHtml(?string $html): string
    {
        return $this->html->sanitize($html);
    }

    /**
     * Stored HTML from a literal-text source (spreadsheet import, system text).
     * Escaped so "<b>" stays visible characters instead of becoming markup.
     */
    public function fromPlainTextSource(?string $text): string
    {
        return $this->html->fromPlainText($text);
    }

    public function fromSource(?string $content, ContentSource $source): string
    {
        return match ($source) {
            ContentSource::Editor => $this->fromEditorHtml($content),
            ContentSource::PlainText => $this->fromPlainTextSource($content),
        };
    }

    /**
     * Stored content as HTML for the editor to load.
     *
     * Legacy rows still marked markdown are rendered to HTML on the way in; the
     * write path then stores the result with an html marker, so a row converts
     * itself the first time somebody edits it.
     */
    public function toEditorHtml(?string $content, ContentFormat|string|null $format): string
    {
        return $this->render($content, $format);
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
