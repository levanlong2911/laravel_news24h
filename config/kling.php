<?php

return [
    // Direct Kling API (JWT auth) — leave blank when using FAL
    'access_key_id'     => env('KLING_ACCESS_KEY_ID'),
    'access_key_secret' => env('KLING_ACCESS_KEY_SECRET'),
    'base_url'          => env('KLING_BASE_URL', 'https://api.klingai.com'),

    // FAL.ai proxy for Kling — set FAL_API_KEY to enable
    'fal_api_key'       => env('FAL_API_KEY'),
    'fal_model'         => env('FAL_KLING_MODEL', 'v2.1/standard'),

    'timeout'           => (int) env('KLING_TIMEOUT', 60),
    'default_model'     => env('KLING_MODEL', 'kling-v1'),
    'default_mode'      => env('KLING_MODE', 'std'),
    'cfg_scale'         => (float) env('KLING_CFG_SCALE', 0.5),
];
