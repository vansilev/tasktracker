<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', env('APP_URL').'/auth/google/callback'),
    ],

    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'dm_enabled' => env('TELEGRAM_DM_ENABLED', false),
        'group_enabled' => env('TELEGRAM_GROUP_ENABLED', false),
        'group_chat_id' => env('TELEGRAM_GROUP_CHAT_ID'),
        'group_message_thread_id' => env('TELEGRAM_GROUP_MESSAGE_THREAD_ID'),
        'group_tag_assignee_on_comment' => env('TELEGRAM_GROUP_TAG_ASSIGNEE_ON_COMMENT', true),
    ],

    // Meetings tab (task 456): transcripts come from Fireflies, tasks are drafted by OpenAI.
    'fireflies' => [
        'api_key' => env('FIREFLIES_API_KEY'),
        'url' => env('FIREFLIES_API_URL', 'https://api.fireflies.ai/graphql'),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'url' => env('OPENAI_API_URL', 'https://api.openai.com/v1'),
        'meeting_model' => env('OPENAI_MEETING_MODEL', 'gpt-5.4'),
        'meeting_reasoning' => env('OPENAI_MEETING_REASONING', 'medium'),
    ],

];
