<?php

declare(strict_types=1);

namespace Tests\Infrastructure\FilmOS;

use App\Services\AI\FilmOS\Capability\CapabilityDescriptor;
use App\Services\AI\FilmOS\Capability\CapabilityRegistry;
use App\Services\AI\FilmOS\Capability\CapabilityType;
use App\Services\AI\FilmOS\Scheduler\ResourceScheduler;
use PHPUnit\Framework\TestCase;

/**
 * ResourceScheduler contract tests.
 *
 * The scheduler must:
 *   - Pick the highest-priority provider with remaining quota
 *   - Fall back down the priority list when a provider is exhausted
 *   - Return null when all providers are exhausted
 *   - Track usage and costs accurately
 */
final class ResourceSchedulerTest extends TestCase
{
    private CapabilityRegistry $registry;
    private ResourceScheduler  $scheduler;

    protected function setUp(): void
    {
        $this->registry  = new CapabilityRegistry();
        $this->scheduler = new ResourceScheduler($this->registry);
    }

    /** @test */
    public function schedule_returns_highest_priority_provider_when_quota_available(): void
    {
        $this->registry->register(new CapabilityDescriptor('runway', CapabilityType::IMAGE_TO_VIDEO, priority: 60,  dailyQuota: 500));
        $this->registry->register(new CapabilityDescriptor('kling',  CapabilityType::IMAGE_TO_VIDEO, priority: 100, dailyQuota: 1000));

        $decision = $this->scheduler->schedule(CapabilityType::IMAGE_TO_VIDEO);

        $this->assertNotNull($decision);
        $this->assertSame('kling', $decision->provider);
        $this->assertSame(0, $decision->quotaUsedBefore);
    }

    /** @test */
    public function schedule_falls_back_when_primary_provider_exhausted(): void
    {
        $this->registry->register(new CapabilityDescriptor('kling',  CapabilityType::IMAGE_TO_VIDEO, priority: 100, dailyQuota: 2));
        $this->registry->register(new CapabilityDescriptor('runway', CapabilityType::IMAGE_TO_VIDEO, priority: 60,  dailyQuota: 500));

        // Exhaust kling
        $this->scheduler->recordUsage('kling');
        $this->scheduler->recordUsage('kling');

        $decision = $this->scheduler->schedule(CapabilityType::IMAGE_TO_VIDEO);

        $this->assertNotNull($decision);
        $this->assertSame('runway', $decision->provider, 'Should fall back to runway after kling exhausted');
    }

    /** @test */
    public function schedule_returns_null_when_all_providers_exhausted(): void
    {
        $this->registry->register(new CapabilityDescriptor('kling',  CapabilityType::IMAGE_TO_VIDEO, priority: 100, dailyQuota: 1));
        $this->registry->register(new CapabilityDescriptor('runway', CapabilityType::IMAGE_TO_VIDEO, priority: 60,  dailyQuota: 1));

        $this->scheduler->recordUsage('kling');
        $this->scheduler->recordUsage('runway');

        $this->assertNull($this->scheduler->schedule(CapabilityType::IMAGE_TO_VIDEO));
    }

    /** @test */
    public function record_usage_increments_daily_counter(): void
    {
        $this->scheduler->recordUsage('kling');
        $this->scheduler->recordUsage('kling');
        $this->scheduler->recordUsage('runway');

        $this->assertSame(2, $this->scheduler->usageFor('kling'));
        $this->assertSame(1, $this->scheduler->usageFor('runway'));
        $this->assertSame(0, $this->scheduler->usageFor('veo'));
    }

    /** @test */
    public function record_usage_tracks_cost(): void
    {
        $this->scheduler->recordUsage('kling', 0.10);
        $this->scheduler->recordUsage('kling', 0.12);
        $this->scheduler->recordUsage('runway', 0.05);

        $this->assertEqualsWithDelta(0.22, $this->scheduler->totalCostFor('kling'),  0.001);
        $this->assertEqualsWithDelta(0.05, $this->scheduler->totalCostFor('runway'), 0.001);
        $this->assertEqualsWithDelta(0.27, $this->scheduler->totalCostAllProviders(), 0.001);
    }

    /** @test */
    public function reset_daily_usage_clears_all_counters(): void
    {
        $this->registry->register(new CapabilityDescriptor('kling', CapabilityType::IMAGE_TO_VIDEO, dailyQuota: 2));

        $this->scheduler->recordUsage('kling');
        $this->scheduler->recordUsage('kling');
        $this->assertNull($this->scheduler->schedule(CapabilityType::IMAGE_TO_VIDEO), 'Exhausted before reset');

        $this->scheduler->resetDailyUsage();

        $this->assertNotNull($this->scheduler->schedule(CapabilityType::IMAGE_TO_VIDEO), 'Available after reset');
        $this->assertSame(0, $this->scheduler->usageFor('kling'));
    }

    /** @test */
    public function has_capacity_reflects_quota_state(): void
    {
        $this->registry->register(new CapabilityDescriptor('kling', CapabilityType::IMAGE_TO_VIDEO, dailyQuota: 1));

        $this->assertTrue($this->scheduler->hasCapacity(CapabilityType::IMAGE_TO_VIDEO));

        $this->scheduler->recordUsage('kling');

        $this->assertFalse($this->scheduler->hasCapacity(CapabilityType::IMAGE_TO_VIDEO));
    }

    /** @test */
    public function quota_snapshot_includes_all_registered_providers(): void
    {
        $this->registry->register(new CapabilityDescriptor('kling',  CapabilityType::IMAGE_TO_VIDEO, dailyQuota: 1000));
        $this->registry->register(new CapabilityDescriptor('runway', CapabilityType::IMAGE_TO_VIDEO, dailyQuota: 500));

        $this->scheduler->recordUsage('kling', 0.10);

        $snapshot = $this->scheduler->quotaSnapshot();

        $this->assertArrayHasKey('kling',  $snapshot);
        $this->assertArrayHasKey('runway', $snapshot);
        $this->assertSame(1,    $snapshot['kling']['used']);
        $this->assertSame(999,  $snapshot['kling']['remaining']);
        $this->assertSame(0,    $snapshot['runway']['used']);
        $this->assertSame(500,  $snapshot['runway']['remaining']);
    }

    /** @test */
    public function decision_carries_correct_quota_metadata(): void
    {
        $this->registry->register(new CapabilityDescriptor('kling', CapabilityType::IMAGE_TO_VIDEO,
            priority: 100, costPerCallUsd: 0.08, dailyQuota: 100));

        $this->scheduler->recordUsage('kling');
        $this->scheduler->recordUsage('kling');

        $decision = $this->scheduler->schedule(CapabilityType::IMAGE_TO_VIDEO);

        $this->assertNotNull($decision);
        $this->assertSame(2,    $decision->quotaUsedBefore);
        $this->assertSame(97,   $decision->quotaRemaining());
        $this->assertEqualsWithDelta(0.08, $decision->estimatedCostUsd, 0.001);
    }

    /** @test */
    public function unlimited_provider_is_never_exhausted(): void
    {
        $this->registry->register(new CapabilityDescriptor('veo', CapabilityType::IMAGE_TO_VIDEO));

        for ($i = 0; $i < 10000; $i++) {
            $this->scheduler->recordUsage('veo');
        }

        $decision = $this->scheduler->schedule(CapabilityType::IMAGE_TO_VIDEO);

        $this->assertNotNull($decision, 'Unlimited provider is never exhausted');
        $this->assertTrue($decision->isUnlimited());
    }

    /** @test */
    public function priority_waterfall_across_three_providers(): void
    {
        $this->registry->register(new CapabilityDescriptor('kling',  CapabilityType::IMAGE_TO_VIDEO, priority: 100, dailyQuota: 3));
        $this->registry->register(new CapabilityDescriptor('runway', CapabilityType::IMAGE_TO_VIDEO, priority: 80,  dailyQuota: 2));
        $this->registry->register(new CapabilityDescriptor('veo',    CapabilityType::IMAGE_TO_VIDEO, priority: 60));

        $providerSequence = [];
        for ($i = 0; $i < 7; $i++) {
            $decision = $this->scheduler->schedule(CapabilityType::IMAGE_TO_VIDEO);
            $this->assertNotNull($decision);
            $this->scheduler->recordUsage($decision->provider);
            $providerSequence[] = $decision->provider;
        }

        // 3 kling + 2 runway + 2 veo (unlimited)
        $this->assertSame(['kling', 'kling', 'kling', 'runway', 'runway', 'veo', 'veo'], $providerSequence);
    }
}
