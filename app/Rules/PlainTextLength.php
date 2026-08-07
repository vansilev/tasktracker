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
        $length = mb_strlen(app(HtmlContentService::class)->toPlainText($html));

        if ($this->min !== null && $length < $this->min) {
            $fail(__('validation.plain_text_min', ['min' => $this->min]));
        }

        if ($this->max !== null && $length > $this->max) {
            $fail(__('validation.plain_text_max', ['max' => $this->max]));
        }
    }
}
