<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Prompting;

use App\Services\AI\FilmOS\Prompting\Adapter\DefaultPromptRendererRegistry;
use App\Services\AI\FilmOS\Prompting\Adapter\KlingPromptRenderer;
use App\Services\AI\FilmOS\Prompting\Adapter\ProviderId;
use App\Services\AI\FilmOS\Prompting\Adapter\UnknownProviderException;
use PHPUnit\Framework\TestCase;

final class DefaultPromptRendererRegistryTest extends TestCase
{
    public function test_get_returns_the_registered_renderer(): void
    {
        $kling    = new KlingPromptRenderer();
        $registry = new DefaultPromptRendererRegistry([$kling]);

        $this->assertTrue($registry->has(ProviderId::KLING));
        $this->assertSame($kling, $registry->get(ProviderId::KLING));
    }

    public function test_register_adds_a_renderer_keyed_by_its_provider(): void
    {
        $registry = new DefaultPromptRendererRegistry();
        $this->assertFalse($registry->has(ProviderId::KLING));

        $registry->register(new KlingPromptRenderer());
        $this->assertTrue($registry->has(ProviderId::KLING));
    }

    public function test_unknown_provider_throws(): void
    {
        $registry = new DefaultPromptRendererRegistry();

        $this->expectException(UnknownProviderException::class);
        $registry->get(ProviderId::VEO);
    }
}
