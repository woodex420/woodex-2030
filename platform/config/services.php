<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME', 'WoodexInteriorBot'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'allow_legacy_webhook' => env('TELEGRAM_ALLOW_LEGACY_WEBHOOK', false),
        'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
    ],

    // WhatsApp Cloud API (WAP-001). The server .env is the fallback: credentials saved in the dashboard win, and
    // the dashboard never shows them back (D-115).
    'whatsapp' => [
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'waba_id' => env('WHATSAPP_WABA_ID'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v23.0'),
        'api_base' => env('WHATSAPP_API_BASE', 'https://graph.facebook.com'),
        // Used only when no approved template is mapped to an event on the templates screen (WAP-005).
        'quotation_template' => env('WHATSAPP_QUOTATION_TEMPLATE', 'quotation'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'sign_in_enabled' => env('GOOGLE_SIGN_IN_ENABLED', false),
        // Workspace data access is a separate consent and a separate connection (App\Integrations\Connectors).
        'workspace_redirect_uri' => env('GOOGLE_WORKSPACE_REDIRECT_URI', rtrim((string) env('APP_URL', ''), '/').'/admin/integrations/oauth/google/callback'),
        'workspace_scopes' => array_values(array_filter(array_map('trim', explode(' ', (string) env('GOOGLE_WORKSPACE_SCOPES',
            'openid email https://www.googleapis.com/auth/drive.metadata.readonly https://www.googleapis.com/auth/spreadsheets.readonly https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/calendar.readonly'
        ))))),
    ],

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    ],

];
