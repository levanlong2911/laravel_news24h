<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Prompting\Render;

use App\Services\AI\FilmOS\Prompting\Adapter\ProviderId;
use App\Services\AI\FilmOS\Prompting\Render\DefaultRenderRequestBuilderRegistry;
use App\Services\AI\FilmOS\Prompting\Render\KlingRenderRequestBuilder;
use App\Services\AI\FilmOS\Prompting\Render\UnknownRenderBuilderException;
use PHPUnit\Framework\TestCase;

final class DefaultRenderRequestBuilderRegistryTest extends TestCase
{
    public function test_resolves_a_registered_builder_by_provider(): void
    {
        $registry = new DefaultRenderRequestBuilderRegistry([new KlingRenderRequestBuilder()]);

        $this->assertTrue($registry->has(ProviderId::KLING));
        $this->assertInstanceOf(KlingRenderRequestBuilder::class, $registry->get(ProviderId::KLING));
    }

    public function test_has_is_false_for_an_unregistered_provider(): void
    {
        $registry = new DefaultRenderRequestBuilderRegistry();

        $this->assertFalse($registry->has(ProviderId::VEO));
    }

    public function test_get_throws_for_an_unregistered_provider(): void
    {
        $registry = new DefaultRenderRequestBuilderRegistry();

        $this->expectException(UnknownRenderBuilderException::class);
        $this->expectExceptionMessage("veo");

        $registry->get(ProviderId::VEO);
    }
}
