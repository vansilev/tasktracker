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

        return (string) $this->purifier->clean($html, 'task_content');
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
        return $this->toPlainText($html) === '';
    }
}
