<?php

namespace Tests\Feature\Video;

use App\Services\Video\CreativeProfileResolver;
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

    public function test_unconfigured_category_resolves_to_null(): void
    {
        // null CHƯA phải fail-closed: nó trao cho người gọi đúng cái
        // `$profile !== null ? creative() : evidenceBound()`. Ba mode
        // Creative/EvidenceBound/Disabled chưa được cài — chưa có chỗ gọi nên
        // đổi kiểu trả về sau vẫn không phá gì.
        $this->assertNull((new CreativeProfileResolver)->resolve('unconfigured-category'));
    }
}
