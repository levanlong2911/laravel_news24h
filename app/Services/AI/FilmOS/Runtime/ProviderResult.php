<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Runtime;

final class ProviderResult
{
    public function __construct(
        public readonly string       $traceId,
        public readonly string       $provider,
        public readonly string       $requestId,
        public readonly RuntimeEvent $status,
        public readonly string       $assetUrl   = '',
        public readonly float        $duration   = 0.0,
        public readonly float        $cost       = 0.0,
        public readonly float        $latency    = 0.0,
        public readonly array        $metadata   = [],
    ) {}
}
