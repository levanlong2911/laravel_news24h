<?php

return [
    // Which render provider to use when none is explicitly specified.
    'default_render_provider' => env('AI_RENDER_PROVIDER', 'kling'),

    // Laravel queue name for render + poll jobs.
    'render_queue' => env('AI_RENDER_QUEUE', 'rendering'),

    // Maximum wall-clock minutes before a render task is declared TIMEOUT.
    'render_timeout_minutes' => (int) env('AI_RENDER_TIMEOUT_MINUTES', 30),

    // Maximum number of poll attempts before declaring TIMEOUT (backstop alongside wall-clock).
    'render_max_polls' => (int) env('AI_RENDER_MAX_POLLS', 40),

    // Circuit breaker state TTL in seconds. After this time, state keys expire from cache.
    'circuit' => [
        'ttl' => (int) env('AI_CIRCUIT_TTL', 86400),
    ],

    // Log channel for render lifecycle events. Defaults to the app's default channel.
    // Override with AI_RENDER_LOG_CHANNEL=render in .env to route to a dedicated channel.
    'log_channel' => env('AI_RENDER_LOG_CHANNEL'),

    // Event-related configuration.
    'events' => [
        // TTL in seconds for the render-failed dedup cache key.
        // Prevents duplicate RenderFailed events under queue retries or duplication.
        'render_failed_dedup_ttl' => (int) env('AI_RENDER_FAILED_DEDUP_TTL', 3600),
    ],

    // Artifact storage configuration.
    'artifact' => [
        // Laravel filesystem disk name. Use 's3' or 'renders' in production.
        'disk'             => env('AI_ARTIFACT_DISK', 'local'),
        // HTTP timeout in seconds for downloading a video artifact from the provider CDN.
        'download_timeout' => (int) env('AI_ARTIFACT_DOWNLOAD_TIMEOUT', 300),
    ],
];
