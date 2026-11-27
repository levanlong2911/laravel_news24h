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

    // Phase 12 — the apps a person connects a YouTube channel or a Facebook page through. Empty until the apps exist: connecting is then refused with that reason.
    'youtube' => [
        'client_id' => env('YOUTUBE_CLIENT_ID'),
        'client_secret' => env('YOUTUBE_CLIENT_SECRET'),
        // youtube.force-ssl is asked for only to take back the schedule of a video (videos.update); without it a schedule is cancelled on YouTube Studio by a person.
        'scopes' => ['https://www.googleapis.com/auth/youtube.upload', 'https://www.googleapis.com/auth/youtube.readonly', 'https://www.googleapis.com/auth/yt-analytics.readonly', 'https://www.googleapis.com/auth/youtube.force-ssl'],
    ],
    'facebook' => [
        'client_id' => env('FACEBOOK_APP_ID'),
        'client_secret' => env('FACEBOOK_APP_SECRET'),
        'graph_version' => env('FACEBOOK_GRAPH_VERSION', 'v27.0'),
        'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'read_insights'],
    ],

    'serpapi' => ['key' => env('SERPAPI_KEY')],
    'claude'  => [
        'key' => env('CLAUDE_API_KEY'),
        'version' => '2023-06-01',
    ],

];
