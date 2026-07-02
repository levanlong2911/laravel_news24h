<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Category Templates
    |--------------------------------------------------------------------------
    | Maps template_key → visual style decisions. TransformationPlanner reads
    | this at runtime. Bump the version key when you change any template value
    | so pipeline_runs cache is auto-invalidated via cacheHash().
    |--------------------------------------------------------------------------
    */

    'template_version' => '1.0',

    'templates' => [
        'motorcycle' => [
            'style'         => 'cinematic',
            'color_palette' => 'warm',
            'pacing'        => 'dynamic',
            'emotion_arc'   => ['hook', 'craftsmanship', 'power', 'reveal'],
        ],
        'superyacht' => [
            'style'         => 'elegant',
            'color_palette' => 'cool',
            'pacing'        => 'slow',
            'emotion_arc'   => ['hook', 'luxury', 'awe', 'reveal'],
        ],
        'construction' => [
            'style'         => 'documentary',
            'color_palette' => 'neutral',
            'pacing'        => 'medium',
            'emotion_arc'   => ['hook', 'engineering', 'scale', 'reveal'],
        ],
        'travel' => [
            'style'         => 'cinematic',
            'color_palette' => 'warm',
            'pacing'        => 'medium',
            'emotion_arc'   => ['hook', 'discovery', 'beauty', 'inspire'],
        ],
        'luxury' => [
            'style'         => 'elegant',
            'color_palette' => 'high_contrast',
            'pacing'        => 'slow',
            'emotion_arc'   => ['hook', 'craft', 'exclusivity', 'reveal'],
        ],
        'sports' => [
            'style'         => 'dynamic',
            'color_palette' => 'high_contrast',
            'pacing'        => 'fast',
            'emotion_arc'   => ['hook', 'tension', 'drama', 'triumph'],
        ],
        'technology' => [
            'style'         => 'documentary',
            'color_palette' => 'cool',
            'pacing'        => 'medium',
            'emotion_arc'   => ['hook', 'innovation', 'impact', 'inspire'],
        ],
        'default' => [
            'style'         => 'cinematic',
            'color_palette' => 'warm',
            'pacing'        => 'medium',
            'emotion_arc'   => ['hook', 'build', 'reveal', 'wow'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Domain → Template Key Map
    |--------------------------------------------------------------------------
    | First keyword match wins. Keep longer/more-specific keywords first.
    |--------------------------------------------------------------------------
    */

    'domain_map' => [
        'superyacht'   => 'superyacht',
        'yacht'        => 'superyacht',
        'vessel'       => 'superyacht',
        'motorcycle'   => 'motorcycle',
        'moto'         => 'motorcycle',
        'construction' => 'construction',
        'building'     => 'construction',
        'engineering'  => 'construction',
        'travel'       => 'travel',
        'tourism'      => 'travel',
        'adventure'    => 'travel',
        'luxury'       => 'luxury',
        'premium'      => 'luxury',
        'nfl'          => 'sports',
        'football'     => 'sports',
        'sport'        => 'sports',
        'racing'       => 'sports',
        'software'     => 'technology',
        'tech'         => 'technology',
        'ai'           => 'technology',
        'saas'         => 'technology',
    ],

    /*
    |--------------------------------------------------------------------------
    | Duration Rules
    |--------------------------------------------------------------------------
    */

    'duration' => [
        'default_seconds' => 15,
        'min_seconds'     => 10,
        'max_seconds'     => 60,
    ],

];
