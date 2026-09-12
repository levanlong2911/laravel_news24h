<?php

namespace Tests\Video\Concept\Canonical;

use App\Video\Profiles\CategoryCreativeProfile;
use App\Video\Profiles\CategoryCreativeProfileRegistry;
use App\Video\Profiles\CategoryCreativeProfileResolver;
use Tests\TestCase;

class CategoryCreativeProfileResolverTest extends TestCase
{
    private function registry(): CategoryCreativeProfileRegistry
    {
        return new CategoryCreativeProfileRegistry([
            new CategoryCreativeProfile('generic_physical_object', '1.0', __FILE__, ['shape']),
            new CategoryCreativeProfile('marine_vessel', '1.0', __FILE__, ['hull']),
        ]);
    }

    public function test_it_maps_a_specific_object_type_to_a_broad_profile(): void
    {
        $resolver = new CategoryCreativeProfileResolver(
            $this->registry(),
            ['superyacht' => 'marine_vessel'],
            'generic_physical_object',
        );

        $this->assertSame('marine_vessel', $resolver->resolve('superyacht')->key);
    }

    public function test_it_uses_the_object_type_when_it_is_already_a_profile_key(): void
    {
        $resolver = new CategoryCreativeProfileResolver(
            $this->registry(),
            [],
            'generic_physical_object',
        );

        $this->assertSame('marine_vessel', $resolver->resolve('marine_vessel')->key);
    }

    public function test_it_falls_back_to_generic_when_the_object_type_is_unknown(): void
    {
        $resolver = new CategoryCreativeProfileResolver(
            $this->registry(),
            [],
            'generic_physical_object',
        );

        $this->assertSame('generic_physical_object', $resolver->resolve('unknown_device')->key);
    }
}
