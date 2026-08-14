<?php

namespace Tests\Feature\Video;

use App\Services\Video\CreativeProfileResolver;
use InvalidArgumentException;
use Tests\TestCase;

class CreativeProfileResolverTest extends TestCase
{
    public function test_configured_categories_resolve_the_same_reusable_profile(): void
    {
        $resolver = new CreativeProfileResolver;

        $this->assertSame('luxury_vessel', $resolver->resolve('yacht')?->key);
        $this->assertSame('luxury_vessel', $resolver->resolve('superyacht')?->key);
        $this->assertCount(12, $resolver->resolve('yacht')?->inspectionAspects ?? []);
    }

    public function test_the_shipped_identity_slots_survive_their_own_preflight(): void
    {
        $slots = (new CreativeProfileResolver)->resolve('yacht')?->identitySlots ?? [];

        $this->assertCount(10, $slots);

        foreach ($slots as $name => $spec) {
            $this->assertContains($spec['type'], ['text', 'integer', 'number'], "slot {$name}");
        }
    }

    public function test_an_unsatisfiable_slot_refuses_to_resolve_before_any_model_call(): void
    {
        config(['video.creative_profiles.profiles.luxury_vessel.identity_slots' => [
            'visible_deck_tiers' => ['type' => 'integer', 'min' => 10, 'max' => 1],
        ]]);

        $this->expectException(InvalidArgumentException::class);

        (new CreativeProfileResolver)->resolve('yacht');
    }

    public function test_unconfigured_category_resolves_to_null(): void
    {
        // null CHƯA phải fail-closed: nó trao cho người gọi đúng cái
        // `$profile !== null ? creative() : evidenceBound()`. Ba mode
        // Creative/EvidenceBound/Disabled chưa được cài — chưa có chỗ gọi nên
        // đổi kiểu trả về sau vẫn không phá gì.
        $this->assertNull((new CreativeProfileResolver)->resolve('unconfigured-category'));
    }
}
