<?php

declare(strict_types=1);

return [

    'provider' => env('CANONICAL_CONCEPT_PROVIDER', 'anthropic'),

    'anthropic' => [

        'api_key' => env('CLAUDE_API_KEY'),

        'base_url' => env(
            'ANTHROPIC_BASE_URL',
            'https://api.anthropic.com'
        ),

        'api_version' => env(
            'ANTHROPIC_API_VERSION',
            '2023-06-01'
        ),

        'model' => env(
            'CANONICAL_CONCEPT_MODEL',
            'claude-sonnet-5'
        ),
    ],

    'openai' => [

        'api_key' => env('OPENAI_API_KEY'),

        'base_url' => env(
            'OPENAI_BASE_URL',
            'https://api.openai.com'
        ),

        'model' => env(
            'CANONICAL_CONCEPT_OPENAI_MODEL',
            'gpt-5.6-terra'
        ),

        'reasoning_effort' => env(
            'CANONICAL_CONCEPT_OPENAI_REASONING',
            'medium'
        ),
    ],

];
