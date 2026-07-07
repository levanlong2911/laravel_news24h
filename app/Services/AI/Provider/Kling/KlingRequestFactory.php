<?php

namespace App\Services\AI\Provider\Kling;

use App\Services\AI\Provider\Kling\Dto\SubmitVideoRequest;

/**
 * Builds Kling API request payloads from domain DTOs.
 * Knows Kling's field names and allowed values; knows nothing about HTTP or auth.
 */
final class KlingRequestFactory
{
    /**
     * Builds the JSON body for POST /v1/videos/text2video.
     *
     * @return array<string, mixed>
     */
    public function buildSubmitPayload(SubmitVideoRequest $request): array
    {
        $payload = [
            'model_name'   => $request->model,
            'prompt'       => $request->prompt,
            'cfg_scale'    => $request->cfgScale,
            'mode'         => $request->mode,
            'duration'     => (string) $request->durationSeconds,
            'aspect_ratio' => $request->aspectRatio,
        ];

        if ($request->negativePrompt !== '') {
            $payload['negative_prompt'] = $request->negativePrompt;
        }

        return $payload;
    }
}
