<?php

namespace App\Rules;

use App\Services\HtmlContentService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class PlainTextLength implements ValidationRule
{
    public function __construct(
        private readonly ?int $min = null,
        private readonly ?int $max = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $html = is_string($value) ? $value : null;
        $htmlService = app(HtmlContentService::class);

        // Length / emptiness against sanitized HTML so tags Purifier drops
        // cannot satisfy min-length via fake attachment URLs.
        $sanitized = $htmlService->sanitize($html);
        $length = mb_strlen($htmlService->toPlainText($sanitized));

        // Inline attachment embeds (image-only / file-chip bodies) count as
        // content even when strip_tags leaves no visible text.
        if ($length === 0 && $htmlService->hasAttachmentEmbed($sanitized)) {
            $length = $this->min ?? 1;
        }

        if ($this->min !== null && $length < $this->min) {
            $fail(__('validation.plain_text_min', ['min' => $this->min]));
        }

        if ($this->max !== null && $length > $this->max) {
            $fail(__('validation.plain_text_max', ['max' => $this->max]));
        }
    }
}
