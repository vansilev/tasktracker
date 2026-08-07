<?php

namespace App\Services;

use Mews\Purifier\Purifier;

class HtmlContentService
{
    public function __construct(
        private Purifier $purifier,
    ) {}

    public function sanitize(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $cleaned = (string) $this->purifier->clean($html, 'task_content');

        // Purifier allows img tags and blocks data:/external hosts via schemes +
        // URI.DisableExternalResources, but still permits arbitrary same-origin
        // paths (e.g. /evil.png). Keep only attachment view URLs.
        return $this->stripNonAttachmentImages($cleaned);
    }

    public function fromPlainText(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $paragraphs = preg_split("/\n\s*\n/", $normalized) ?: [];

        $blocks = [];

        foreach ($paragraphs as $paragraph) {
            $escaped = htmlspecialchars($paragraph, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $escaped = str_replace("\n", '<br>', $escaped);
            $blocks[] = '<p>'.$escaped.'</p>';
        }

        return implode('', $blocks);
    }

    public function toPlainText(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        // Insert a space at block-level boundaries so TipTap-style markup
        // without newlines between tags does not glue adjacent text.
        // Inline tags (strong, em, b, i, u, s, a, code, span, sub, sup, mark, …)
        // are left alone here and removed by strip_tags without a separator.
        $text = preg_replace(
            '#</?(?:p|br|li|ul|ol|tr|td|th|table|thead|tbody|h[1-6]|blockquote|pre|div|hr)(?:\s[^>]*)?/?>#iu',
            ' ',
            $html,
        ) ?? $html;

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    public function isEmpty(?string $html): bool
    {
        if ($html === null || $html === '') {
            return true;
        }

        // Decide emptiness on sanitized markup so hostile wrappers that Purifier
        // drops (e.g. <div href="…/download">) cannot fake non-empty content.
        $sanitized = $this->sanitize($html);

        if ($this->toPlainText($sanitized) !== '') {
            return false;
        }

        // Image-only / attachment-link-only bodies are still content.
        return ! $this->hasAttachmentEmbed($sanitized);
    }

    /**
     * True when the markup references a stored task attachment (inline image
     * view URL or document download/view link).
     */
    public function hasAttachmentEmbed(?string $html): bool
    {
        if ($html === null || $html === '') {
            return false;
        }

        return (bool) preg_match(
            '~<(?:img\b[^>]*\bsrc|a\b[^>]*\bhref)=["\'](?:https?://[^"\']+)?/tasks/attachments/\d+/(?:view|download)["\']~i',
            $html,
        );
    }

    /**
     * Whether an img src is an allowed same-app attachment view URL.
     */
    public function isAllowedAttachmentImageSrc(string $src): bool
    {
        $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($src === '' || str_contains($src, '..')) {
            return false;
        }

        // Stored src must be a clean path/URL — no query or fragment.
        if (str_contains($src, '?') || str_contains($src, '#')) {
            return false;
        }

        if (str_starts_with($src, '//')) {
            return false;
        }

        $path = $src;
        $appUrl = (string) config('app.url');

        if (preg_match('#^https?://#i', $src) === 1) {
            $parts = parse_url($src);
            $host = $parts['host'] ?? null;
            $appHost = parse_url($appUrl, PHP_URL_HOST);

            if ($host === null || $appHost === null || strcasecmp($host, $appHost) !== 0) {
                return false;
            }

            $path = $parts['path'] ?? '';
        }

        $appPath = rtrim((string) (parse_url($appUrl, PHP_URL_PATH) ?: ''), '/');

        if ($appPath !== '' && str_starts_with($path, $appPath.'/')) {
            $path = substr($path, strlen($appPath)) ?: '';
        }

        return preg_match('#^/tasks/attachments/\d+/view$#', $path) === 1;
    }

    private function stripNonAttachmentImages(string $html): string
    {
        if ($html === '' || ! str_contains(strtolower($html), '<img')) {
            return $html;
        }

        $result = preg_replace_callback(
            '#<img\b[^>]*/?>#i',
            function (array $matches): string {
                $tag = $matches[0];

                if (preg_match('#\bsrc\s*=\s*("|\')([^"\']*)\1#i', $tag, $srcMatch) !== 1
                    && preg_match('#\bsrc\s*=\s*([^\s>]+)#i', $tag, $srcMatch) !== 1) {
                    return '';
                }

                $src = $srcMatch[2] ?? $srcMatch[1];

                return $this->isAllowedAttachmentImageSrc($src) ? $tag : '';
            },
            $html,
        );

        return $result ?? $html;
    }
}
