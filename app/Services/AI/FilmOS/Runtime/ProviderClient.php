<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Runtime;

interface ProviderClient
{
    /** @param array<string, mixed> $payload Provider-specific serialized payload from ProviderSerializer */
    public function submit(string $traceId, array $payload): ProviderResult;

    public function poll(ProviderResult $result): ProviderResult;

    public function download(ProviderResult $result): ProviderResult;
}
