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

    'google_sheets' => [
        'credentials' => storage_path('app/private/google/service-account.json'),
        'spreadsheet_id' => env('GOOGLE_SHEETS_SPREADSHEET_ID'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'wompi' => [
        'environment' => env('WOMPI_ENVIRONMENT', 'sandbox'),
        'public_key' => env('WOMPI_PUBLIC_KEY'),
        'private_key' => env('WOMPI_PRIVATE_KEY'),
        'integrity_secret' => env('WOMPI_INTEGRITY_SECRET'),
        'events_secret' => env('WOMPI_EVENTS_SECRET'),
        'base_url' => env('WOMPI_BASE_URL', 'https://sandbox.wompi.co/v1'),
        'reservation_release_grace_minutes' => (int) env('WOMPI_RESERVATION_RELEASE_GRACE_MINUTES', 35),
    ],

    'apps_script' => [
        'url' => env('APPS_SCRIPT_URL'),
        'api_key' => env('APPS_SCRIPT_API_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
