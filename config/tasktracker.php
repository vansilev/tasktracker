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

    /*
    |--------------------------------------------------------------------------
    | Personal access tokens a user may keep at once
    |--------------------------------------------------------------------------
    */
    'api_token_limit' => 10,

    /*
    |--------------------------------------------------------------------------
    | Read-only Kommo MCP (kommo-read.tcsavant.com)
    |--------------------------------------------------------------------------
    */
    'kommo_read_mcp_url' => env('KOMMO_READ_MCP_URL', 'https://kommo-read.tcsavant.com/mcp'),
    'kommo_read_key_limit' => 5,

    /*
    |--------------------------------------------------------------------------
    | Telegram reminders (task 448) — defaults, editable in admin settings
    |--------------------------------------------------------------------------
    | Times are Europe/Kyiv, HH:MM. Work days are ISO weekdays (1 = Monday).
    | "Work days" counters skip weekends only; public holidays are not tracked.
    */
    'reminder_enabled' => false,
    'reminder_threshold_days' => [3, 1, 0],
    'reminder_morning_time' => '09:30',
    'reminder_digest_time' => '10:00',
    'reminder_work_start' => '09:00',
    'reminder_work_end' => '19:00',
    'reminder_work_days' => [1, 2, 3, 4, 5],
    'reminder_escalation_after_days' => 3,
    'reminder_escalation_repeat_days' => 7,
    'reminder_stale_work_days' => 5,
    'reminder_mention_repeat_work_days' => 1,
    'reminder_mention_digest_work_days' => 2,
    'reminder_digest_recipient_ids' => [],
    'reminder_excluded_user_ids' => [],
    'reminder_no_escalation_head_ids' => [],

];
