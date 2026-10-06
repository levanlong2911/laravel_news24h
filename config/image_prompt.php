<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Image Prompt Authoring
    |--------------------------------------------------------------------------
    |
    | Duong sinh prompt anh KHONG di qua Python. Model doc mot skill van xuoi
    | roi tra ve prompt hoan chinh; Laravel chi thay du lieu vao hai diem chen
    | va bam ket qua.
    |
    | Khac voi `canonical_concept`: o day KHONG gui output schema, vi skill yeu
    | cau tra ve van ban thuan.
    |
    | Thong tin nha cung cap KHONG khai lai o day — no doc thang tu
    | `canonical_concept` de mot lan doi khoa la ca hai duong cung doi theo.
    | Con lai o day CHI la ngan sach rieng cua duong nay.
    |
    */

    'prompt_path' => resource_path(
        'ai/prompts/geometry_reference_v2.txt'
    ),

    'prompt_version' => env(
        'IMAGE_PROMPT_VERSION',
        'geometry-reference-v2-r16'
    ),

    'character_prompt_path' => resource_path(
        'ai/prompts/character_reference_v1.txt'
    ),

    'character_prompt_version' => env(
        'IMAGE_CHARACTER_PROMPT_VERSION',
        'character-reference-v1'
    ),

    'design_anchor' => [
        'prompt_path' => resource_path('ai/prompts/anchor_from_design_v1.txt'),
        'prompt_version' => 'anchor-from-design-v1-r10',
    ],

    'anchor_model' => env('IMAGE_ANCHOR_MODEL', 'gpt-image-2.5-flare'),

    'text_pricing' => [
        'model' => 'gpt-5.6-sol',
        'version' => 'openai-gpt-5.6-sol-2026-09-29',
        'input_per_million' => 2.0,
        'cached_input_per_million' => 0.2,
        'output_per_million' => 12.0,
    ],

    'anchor_author' => [
        'model' => env('IMAGE_ANCHOR_PROMPT_MODEL', 'gpt-5.6-sol'),
        'reasoning_effort' => env('IMAGE_ANCHOR_PROMPT_REASONING', 'medium'),
    ],

    'reference' => [
        'prompt_path' => resource_path('ai/prompts/reference_view_v1.txt'),
        'prompt_version' => 'reference-view-v1-r15',
        'model' => env('IMAGE_REFERENCE_PROMPT_MODEL', 'gpt-5.6-sol'),
        'reasoning_effort' => env('IMAGE_REFERENCE_PROMPT_REASONING', 'medium'),
        'max_tokens' => (int) env('IMAGE_REFERENCE_PROMPT_MAX_TOKENS', 32000),
    ],

    'anthropic' => [
        'max_tokens' => (int) env('IMAGE_PROMPT_ANTHROPIC_MAX_TOKENS', 8000),
    ],

    'openai' => [
        /*
         * `reasoning_tokens` nam TRONG `completion_tokens`, nen ngan sach nay
         * phai chua ca phan suy luan lan phan viet ra — khac hoan toan nghia
         * cua `max_tokens` ben Anthropic.
         */
        'max_tokens' => (int) env('IMAGE_PROMPT_OPENAI_MAX_TOKENS', 32000),
    ],

    'timeout_seconds' => (int) env(
        'IMAGE_PROMPT_TIMEOUT_SECONDS',
        300
    ),

    'retry_times' => (int) env('IMAGE_PROMPT_RETRY_TIMES', 2),

    'retry_sleep_ms' => (int) env('IMAGE_PROMPT_RETRY_SLEEP_MS', 800),
];
