<?php

namespace Tests\Video\Pipeline;

use App\Video\Editorial\EditorialPolicy;
use App\Video\Pipeline\VideoPipelineFactory;
use Tests\TestCase;

/**
 * Tách khỏi VideoPipelineFactoryTest vì cần config() (Laravel bootstrap thật)
 * — claude()/EditorialPolicy còn lại là pure PHP, không cần framework.
 */
class VideoPipelineFactoryProductionPoliciesTest extends TestCase
{
    public function test_production_policies_reads_from_config(): void
    {
        config(['video.editorial_policies' => [
            ['match' => ['builder' => 'Feadship'], 'prohibit_attribute' => 'domes', 'prohibit_value' => true, 'reason' => 'integrated satellite receivers instead of exposed radomes (2025 refit)'],
        ]]);

        $policies = VideoPipelineFactory::productionPolicies();

        $this->assertCount(1, $policies);
        $this->assertInstanceOf(EditorialPolicy::class, $policies[0]);
        $this->assertSame(['builder' => 'Feadship'], $policies[0]->match);
        $this->assertSame('domes', $policies[0]->prohibitAttribute);
        $this->assertTrue($policies[0]->prohibitValue);
    }

    public function test_production_policies_empty_when_config_empty(): void
    {
        config(['video.editorial_policies' => []]);

        $this->assertSame([], VideoPipelineFactory::productionPolicies());
    }

    public function test_the_real_shipped_config_has_the_feadship_domes_policy(): void
    {
        // Đúng ADR §12: prohibition thật đầu tiên, không phải fixture giả.
        // KHÔNG override config ở đây — đọc đúng config/video.php thật đang ship.
        $policies = VideoPipelineFactory::productionPolicies();

        $this->assertNotEmpty($policies, 'config/video.php phải có ít nhất policy Feadship/domes thật');
        $feadship = current(array_filter($policies, fn ($p) => ($p->match['builder'] ?? null) === 'Feadship'));
        $this->assertNotFalse($feadship);
        $this->assertSame('domes', $feadship->prohibitAttribute);
    }
}
