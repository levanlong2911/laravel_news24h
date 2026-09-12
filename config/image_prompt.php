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
        'ai/prompts/geometry_reference_v1.txt'
    ),

    'prompt_version' => env(
        'IMAGE_PROMPT_VERSION',
        'geometry-reference-v1'
    ),

    'anthropic' => [
        'max_tokens' => (int) env('IMAGE_PROMPT_ANTHROPIC_MAX_TOKENS', 8000),
    ],

    'openai' => [
        /*
         * `reasoning_tokens` nam TRONG `completion_tokens`, nen ngan sach nay
         * phai chua ca phan suy luan lan phan viet ra — khac hoan toan nghia
         * cua `max_tokens` ben Anthropic.
         */
        'max_tokens' => (int) env('IMAGE_PROMPT_OPENAI_MAX_TOKENS', 16000),
    ],

    'timeout_seconds' => (int) env(
        'IMAGE_PROMPT_TIMEOUT_SECONDS',
        300
    ),

    'retry_times' => (int) env('IMAGE_PROMPT_RETRY_TIMES', 2),

    'retry_sleep_ms' => (int) env('IMAGE_PROMPT_RETRY_SLEEP_MS', 800),
];
