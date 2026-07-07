<?php

return [
    'access_key_id'     => env('KLING_ACCESS_KEY_ID'),
    'access_key_secret' => env('KLING_ACCESS_KEY_SECRET'),
    'base_url'          => env('KLING_BASE_URL', 'https://api.klingai.com'),
    'timeout'           => (int) env('KLING_TIMEOUT', 30),
    'default_model'     => env('KLING_MODEL', 'kling-v1'),
    'default_mode'      => env('KLING_MODE', 'std'),
    'cfg_scale'         => (float) env('KLING_CFG_SCALE', 0.5),
];
