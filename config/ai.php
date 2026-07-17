<?php

return [
    // Which render provider to use when none is explicitly specified.
    'default_render_provider' => env('AI_RENDER_PROVIDER', 'kling'),

    // Circuit breaker state TTL in seconds. After this time, state keys expire from cache.
    'circuit' => [
        'ttl' => (int) env('AI_CIRCUIT_TTL', 86400),
    ],

    // Artifact storage configuration.
    'artifact' => [
        // Laravel filesystem disk name. Use 's3' or 'renders' in production.
        'disk'             => env('AI_ARTIFACT_DISK', 'local'),
        // HTTP timeout in seconds for downloading a video artifact from the provider CDN.
        'download_timeout' => (int) env('AI_ARTIFACT_DOWNLOAD_TIMEOUT', 300),
    ],
];
