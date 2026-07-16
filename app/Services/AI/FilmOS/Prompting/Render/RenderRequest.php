<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Prompting\Render;

use App\Services\AI\FilmOS\Prompting\Adapter\ProviderId;

/**
 * A vendor-neutral render request — the bridge value between the prompt layer
 * (RenderedPrompt) and the execution layer (ProviderClient payload).
 *
 * Deliberately minimal: it carries only what every video provider needs. Vendor
 * quirks (Kling's mode / cfg_scale, per-provider key names) DO NOT live here —
 * each RenderRequestBuilder maps this into its provider's payload. Adding a
 * knob here means it is universal; anything else belongs in a builder.
 *
 * Immutable.
 */
final class RenderRequest
{
    public function __construct(
        public readonly ProviderId $provider,
        public readonly string     $model,
        public readonly string     $positive,
        public readonly ?string    $negative = null,
        public readonly int        $durationSeconds = 5,
        public readonly string     $aspectRatio = '16:9',
        public readonly ?int       $seed = null,
    ) {}
}
