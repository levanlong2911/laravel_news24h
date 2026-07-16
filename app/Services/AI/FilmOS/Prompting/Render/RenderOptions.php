<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Render;

/**
 * The caller's execution choices for one render — kept separate from the prompt
 * so the same RenderedPrompt can be rendered at different durations, aspect
 * ratios, or seeds without recompiling.
 *
 * `model` is optional: null means "let the provider's builder pick its default"
 * (e.g. KlingRenderRequestBuilder defaults to its frozen v1.6/standard model).
 *
 * Immutable.
 */
final class RenderOptions
{
    public function __construct(
        public readonly int     $durationSeconds = 5,
        public readonly string  $aspectRatio = '16:9',
        public readonly ?int    $seed = null,
        public readonly ?string $model = null,
    ) {}
}
