<?php

namespace App\Rules;

use App\Services\SettingsService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CorporateEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $domains = app(SettingsService::class)->get('allowed_email_domains', []);

        if ($domains === []) {
            return;
        }

        $email = strtolower((string) $value);
        $domain = substr(strrchr($email, '@') ?: '', 1);

        if ($domain === '' || ! in_array($domain, $domains, true)) {
            $allowed = implode(', @', $domains);
            $fail(__('Only corporate email addresses are allowed (@:domains).', ['domains' => $allowed]));
        }
    }
}
