<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Allowed email domains for registration / SSO
    |--------------------------------------------------------------------------
    */
    'allowed_email_domains' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('ALLOWED_EMAIL_DOMAINS', 'tcsavant.com'))
    ))),

    /*
    |--------------------------------------------------------------------------
    | Authentication methods (can be toggled later from admin UI — phase 1)
    |--------------------------------------------------------------------------
    */
    'google_sso_enabled' => (bool) env('GOOGLE_SSO_ENABLED', false),
    'password_login_enabled' => (bool) env('PASSWORD_LOGIN_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Bootstrap administrator
    |--------------------------------------------------------------------------
    */
    'admin_email' => env('ADMIN_EMAIL', 'crm.manager@tcsavant.com'),
    'admin_name' => env('ADMIN_NAME', 'CRM Manager'),

    'billing_bot_email' => env('BILLING_BOT_EMAIL', 'billing@tcsavant.com'),
    'billing_bot_name' => env('BILLING_BOT_NAME', 'Оплаты'),

    /*
    |--------------------------------------------------------------------------
    | Application defaults
    |--------------------------------------------------------------------------
    */
    'default_locale' => env('APP_LOCALE', 'ru'),
    'timezone' => env('APP_TIMEZONE', 'Europe/Kyiv'),

    /*
    |--------------------------------------------------------------------------
    | SLA for initiator review (days)
    |--------------------------------------------------------------------------
    */
    'sla_review_days' => (int) env('SLA_REVIEW_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Attachment upload limit (kilobytes)
    |--------------------------------------------------------------------------
    */
    'attachment_max_kb' => (int) env('ATTACHMENT_MAX_KB', 10240),

];
