<?php

namespace App\Services\AI\Provider\Kling\Dto;

/**
 * Input contract for submitting a video generation task to Kling.
 * Built by the pipeline layer; consumed by KlingRequestFactory.
 */
final class SubmitVideoRequest
{
    public function __construct(
        public readonly string $prompt,
        public readonly string $negativePrompt,
        public readonly string $model,
        public readonly string $mode,            // 'std' | 'pro'
        public readonly int    $durationSeconds, // 5 | 10
        public readonly string $aspectRatio,     // '16:9' | '9:16' | '1:1'
        public readonly float  $cfgScale,
    ) {}
}
