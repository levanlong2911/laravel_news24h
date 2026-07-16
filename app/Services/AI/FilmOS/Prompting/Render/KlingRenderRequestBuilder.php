<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Render;

use App\Services\AI\FilmOS\Prompting\Adapter\ProviderId;
use App\Services\AI\FilmOS\Prompting\Adapter\RenderedPrompt;

/**
 * The ONLY place Kling render-request quirks live. `build()` produces a neutral
 * RenderRequest; `toPayload()` maps it into the exact array ProviderClient
 * (KlingClient) expects — adding Kling's mode + cfg_scale and renaming keys.
 *
 * Values mirror KlingSerializer so the benchmark path and the production path
 * submit identical Kling payloads. The FAL model route (v1.6/standard, the
 * frozen rule) is chosen downstream by FalKlingApiClient::fromConfig().
 *
 * `toPayload()` is intentionally NOT on the RenderRequestBuilder interface —
 * it is Kling-specific and would impose an ill-fitting contract on Veo/Runway.
 */
final class KlingRenderRequestBuilder implements RenderRequestBuilder
{
    private const DEFAULT_MODEL    = 'kling-v1';
    private const MODE             = 'std';   // frozen: Kling v1.6/standard
    private const CFG_SCALE        = 0.5;
    private const VALID_DURATIONS  = [5, 10];

    public function provider(): ProviderId
    {
        return ProviderId::KLING;
    }

    public function build(RenderedPrompt $prompt, RenderOptions $options): RenderRequest
    {
        return new RenderRequest(
            provider:        ProviderId::KLING,
            model:           $options->model ?? self::DEFAULT_MODEL,
            positive:        $prompt->positive,
            negative:        $prompt->negative,
            durationSeconds: $options->durationSeconds,
            aspectRatio:     $options->aspectRatio,
            seed:            $options->seed,
        );
    }

    /**
     * Map a RenderRequest into the payload ProviderClient::submit() consumes.
     * Kling-specific: injects mode + cfg_scale, clamps duration to 5 or 10,
     * and only emits negative_prompt when there is one.
     *
     * @return array<string, mixed>
     */
    public function toPayload(RenderRequest $request): array
    {
        $payload = [
            'model_name'   => $request->model,
            'prompt'       => $request->positive,
            'cfg_scale'    => self::CFG_SCALE,
            'mode'         => self::MODE,
            'duration'     => (string) $this->clampDuration($request->durationSeconds),
            'aspect_ratio' => $request->aspectRatio,
        ];

        if ($request->negative !== null && $request->negative !== '') {
            $payload['negative_prompt'] = $request->negative;
        }

        return $payload;
    }

    /** Kling accepts only 5 or 10 seconds — pick the nearest valid value. */
    private function clampDuration(int $seconds): int
    {
        return abs($seconds - 5) <= abs($seconds - 10) ? 5 : 10;
    }
}
