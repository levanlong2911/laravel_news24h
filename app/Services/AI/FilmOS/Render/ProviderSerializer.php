<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Render;

interface ProviderSerializer
{
    public function provider(): string;

    public function capability(): ProviderCapability;

    public function supports(RenderIR $ir): bool;

    /** @return array<string, mixed> */
    public function serialize(RenderIR $ir): array;
}
