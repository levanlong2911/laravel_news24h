<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Canonical Concept
    |--------------------------------------------------------------------------
    |
    | Configuration for the provider-independent canonical concept stage.
    |
    | IMPORTANT:
    |
    | This file contains infrastructure/runtime configuration only.
    |
    | Semantic design rules belong in:
    |
    | - canonical schema
    | - category profiles
    | - validators
    | - system prompts
    |
    | Do not put object-specific design rules here.
    |
    */

    'schema' => [

        /*
         * Immutable production V1 contract.
         */
        'version' => '1.0',

        'path' => resource_path(
            'ai/schemas/'
            .'canonical_design_spec_v1.json'
        ),
    ],

    'prompt_version' => env(
        'CANONICAL_CONCEPT_PROMPT_VERSION',
        'concept-v2'
    ),

    'provider' => env('CANONICAL_CONCEPT_PROVIDER', 'anthropic'),

    /*
    |--------------------------------------------------------------------------
    | Anthropic
    |--------------------------------------------------------------------------
    */

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

        /*
         * Khong hard-code model identifier
         * rai rac trong service.
         */
        'model' => env(
            'CANONICAL_CONCEPT_MODEL',
            'claude-sonnet-5'
        ),

        /*
         * Maximum output budget.
         */
        'max_tokens' => (int) env(
            'CANONICAL_CONCEPT_MAX_TOKENS',
            24000
        ),

        /*
         * HTTP timeout, seconds.
         */
        'timeout' => (int) env(
            'CANONICAL_CONCEPT_HTTP_TIMEOUT',
            360
        ),

        /*
         * Infrastructure retry.
         *
         * Day KHONG phai semantic repair.
         */
        'http_retry_times' => (int) env(
            'CANONICAL_CONCEPT_HTTP_RETRY_TIMES',
            2
        ),

        'http_retry_sleep_ms' => (int) env(
            'CANONICAL_CONCEPT_HTTP_RETRY_SLEEP_MS',
            500
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAI
    |--------------------------------------------------------------------------
    */

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

        /*
         * `reasoning_tokens` nam TRONG `completion_tokens`, nen ngan sach nay
         * phai chua ca phan suy luan lan phan viet ra.
         */
        'max_tokens' => (int) env(
            'CANONICAL_CONCEPT_OPENAI_MAX_TOKENS',
            32000
        ),

        'timeout' => (int) env(
            'CANONICAL_CONCEPT_OPENAI_HTTP_TIMEOUT',
            600
        ),

        'http_retry_times' => (int) env(
            'CANONICAL_CONCEPT_OPENAI_HTTP_RETRY_TIMES',
            2
        ),

        'http_retry_sleep_ms' => (int) env(
            'CANONICAL_CONCEPT_OPENAI_HTTP_RETRY_SLEEP_MS',
            500
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Semantic Repair
    |--------------------------------------------------------------------------
    */

    'repair' => [

        /*
         * Day chu yeu la observability/documentation.
         *
         * Production orchestration van enforce
         * structurally dung 1 repair.
         *
         * Khong viet generic while-loop dua vao
         * config nay.
         */
        'max_attempts' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Prompts
    |--------------------------------------------------------------------------
    */

    'prompts' => [

        'designer' => resource_path(
            'ai/prompts/'
            .'concept_designer_system.txt'
        ),

        'repairer' => resource_path(
            'ai/prompts/'
            .'concept_repair_system.txt'
        ),
    ],

];
