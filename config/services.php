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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'serpapi' => ['key' => env('SERPAPI_KEY')],
    'claude'  => [
        'key' => env('CLAUDE_API_KEY'),
        'version' => '2023-06-01',
    ],

    // L9 Thumbnail Lab — Flux image generation via fal.ai
    'fal' => [
        'key' => env('FAL_KEY'),
    ],

    // L11 Publisher — n8n/Make webhook URLs (leave blank to skip that platform)
    'publisher' => [
        'youtube_webhook'   => env('PUBLISHER_YOUTUBE_WEBHOOK'),
        'facebook_webhook'  => env('PUBLISHER_FACEBOOK_WEBHOOK'),
        'tiktok_webhook'    => env('PUBLISHER_TIKTOK_WEBHOOK'),
        'instagram_webhook' => env('PUBLISHER_INSTAGRAM_WEBHOOK'),
    ],

];
