<?php

declare(strict_types=1);

namespace Tests\Infrastructure\FilmOS;

use App\Services\AI\FilmOS\Capability\CapabilityDescriptor;
use App\Services\AI\FilmOS\Capability\CapabilityRegistry;
use App\Services\AI\FilmOS\Capability\CapabilityType;
use PHPUnit\Framework\TestCase;

/**
 * CapabilityRegistry contract tests.
 *
 * The registry is the single source of truth for "what can be done and by whom".
 * These tests verify that planners can safely ignore provider names.
 */
final class CapabilityRegistryTest extends TestCase
{
    private CapabilityRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new CapabilityRegistry();
    }

    /** @test */
    public function resolve_returns_empty_for_unregistered_capability(): void
    {
        $this->assertSame([], $this->registry->resolve(CapabilityType::IMAGE_TO_VIDEO));
        $this->assertFalse($this->registry->supports(CapabilityType::IMAGE_TO_VIDEO));
    }

    /** @test */
    public function register_and_resolve_single_provider(): void
    {
        $this->registry->register(new CapabilityDescriptor(
            providerName: 'kling',
            capability:   CapabilityType::IMAGE_TO_VIDEO,
            priority:     100,
        ));

        $result = $this->registry->resolve(CapabilityType::IMAGE_TO_VIDEO);

        $this->assertCount(1, $result);
        $this->assertSame('kling', $result[0]->providerName);
        $this->assertTrue($this->registry->supports(CapabilityType::IMAGE_TO_VIDEO));
    }

    /** @test */
    public function resolve_sorts_by_priority_descending(): void
    {
        $this->registry->register(new CapabilityDescriptor('runway', CapabilityType::IMAGE_TO_VIDEO, priority: 60));
        $this->registry->register(new CapabilityDescriptor('veo',    CapabilityType::IMAGE_TO_VIDEO, priority: 80));
        $this->registry->register(new CapabilityDescriptor('kling',  CapabilityType::IMAGE_TO_VIDEO, priority: 100));

        $result = $this->registry->resolve(CapabilityType::IMAGE_TO_VIDEO);

        $this->assertCount(3, $result);
        $this->assertSame(['kling', 'veo', 'runway'], array_column($result, 'providerName'));
    }

    /** @test */
    public function best_returns_highest_priority_provider(): void
    {
        $this->registry->register(new CapabilityDescriptor('veo',   CapabilityType::IMAGE_TO_VIDEO, priority: 80));
        $this->registry->register(new CapabilityDescriptor('kling', CapabilityType::IMAGE_TO_VIDEO, priority: 100));

        $best = $this->registry->best(CapabilityType::IMAGE_TO_VIDEO);

        $this->assertNotNull($best);
        $this->assertSame('kling', $best->providerName);
    }

    /** @test */
    public function best_returns_null_when_nothing_registered(): void
    {
        $this->assertNull($this->registry->best(CapabilityType::VOICE));
    }

    /** @test */
    public function capabilities_of_returns_all_capabilities_for_provider(): void
    {
        $this->registry->register(new CapabilityDescriptor('kling', CapabilityType::IMAGE_TO_VIDEO));
        $this->registry->register(new CapabilityDescriptor('kling', CapabilityType::MOTION));
        $this->registry->register(new CapabilityDescriptor('flux',  CapabilityType::TEXT_TO_IMAGE));

        $klingCaps = $this->registry->capabilitiesOf('kling');
        $fluxCaps  = $this->registry->capabilitiesOf('flux');

        $this->assertCount(2, $klingCaps);
        $this->assertContains(CapabilityType::IMAGE_TO_VIDEO, $klingCaps);
        $this->assertContains(CapabilityType::MOTION, $klingCaps);

        $this->assertCount(1, $fluxCaps);
        $this->assertSame(CapabilityType::TEXT_TO_IMAGE, $fluxCaps[0]);
    }

    /** @test */
    public function providers_returns_all_unique_provider_names(): void
    {
        $this->registry->register(new CapabilityDescriptor('kling',  CapabilityType::IMAGE_TO_VIDEO));
        $this->registry->register(new CapabilityDescriptor('kling',  CapabilityType::MOTION));
        $this->registry->register(new CapabilityDescriptor('runway', CapabilityType::IMAGE_TO_VIDEO));
        $this->registry->register(new CapabilityDescriptor('flux',   CapabilityType::TEXT_TO_IMAGE));

        $providers = $this->registry->providers();
        sort($providers);

        $this->assertSame(['flux', 'kling', 'runway'], $providers);
    }

    /** @test */
    public function snapshot_returns_full_registry_dump(): void
    {
        $this->registry->register(new CapabilityDescriptor('kling', CapabilityType::IMAGE_TO_VIDEO, priority: 100, dailyQuota: 1000));
        $this->registry->register(new CapabilityDescriptor('veo',   CapabilityType::IMAGE_TO_VIDEO, priority: 80));

        $snapshot = $this->registry->snapshot();

        $this->assertArrayHasKey('image_to_video', $snapshot);
        $this->assertCount(2, $snapshot['image_to_video']);
        $this->assertSame('kling', $snapshot['image_to_video'][0]['provider']);
        $this->assertSame(1000,    $snapshot['image_to_video'][0]['dailyQuota']);
    }

    /** @test */
    public function different_capabilities_are_stored_separately(): void
    {
        $this->registry->register(new CapabilityDescriptor('flux',  CapabilityType::TEXT_TO_IMAGE));
        $this->registry->register(new CapabilityDescriptor('kling', CapabilityType::IMAGE_TO_VIDEO));

        $this->assertTrue($this->registry->supports(CapabilityType::TEXT_TO_IMAGE));
        $this->assertTrue($this->registry->supports(CapabilityType::IMAGE_TO_VIDEO));
        $this->assertFalse($this->registry->supports(CapabilityType::LIPSYNC));

        $this->assertCount(1, $this->registry->resolve(CapabilityType::TEXT_TO_IMAGE));
        $this->assertCount(1, $this->registry->resolve(CapabilityType::IMAGE_TO_VIDEO));
    }
}
