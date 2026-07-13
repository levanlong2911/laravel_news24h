<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Narrative\World;

use App\Services\AI\FilmOS\Narrative\Timeline\NarrativeStateBuilder;
use App\Services\AI\FilmOS\Narrative\Timeline\ProjectionMetadata;
use App\Services\AI\FilmOS\Narrative\Timeline\NarrativeState;
use App\Services\AI\FilmOS\Narrative\World\WorldFact;
use App\Services\AI\FilmOS\Narrative\World\WorldObject;
use App\Services\AI\FilmOS\Narrative\Shared\AttributeBag;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectType;
use PHPUnit\Framework\TestCase;

final class NarrativeStateBuilderWorldTest extends TestCase
{
    // ── Invariant: upsertWorldObject stores by id (idempotent on same id) ─────

    public function test_upsert_world_object_stores_object(): void
    {
        $builder = new NarrativeStateBuilder();
        $hero    = $this->object('hero');

        $builder->upsertWorldObject($hero);
        $state = $this->build($builder);

        $this->assertSame($hero, $state->world->allObjects()['hero']);
    }

    public function test_upsert_replaces_existing_object_with_same_id(): void
    {
        $builder = new NarrativeStateBuilder();
        $v1      = $this->object('door', ['open' => false]);
        $v2      = $this->object('door', ['open' => true]);

        $builder->upsertWorldObject($v1);
        $builder->upsertWorldObject($v2);
        $state = $this->build($builder);

        $this->assertSame($v2, $state->world->allObjects()['door']);
        $this->assertCount(1, $state->world->allObjects());
    }

    // ── Invariant: removeWorldObject removes by id ────────────────────────────

    public function test_remove_world_object_removes_by_id(): void
    {
        $builder = new NarrativeStateBuilder();
        $builder->upsertWorldObject($this->object('hero'));
        $builder->upsertWorldObject($this->object('door'));

        $builder->removeWorldObject('hero');
        $state = $this->build($builder);

        $this->assertFalse($state->world->hasObject('hero'));
        $this->assertTrue($state->world->hasObject('door'));
    }

    public function test_remove_nonexistent_object_does_not_fail(): void
    {
        $builder = new NarrativeStateBuilder();
        $builder->removeWorldObject('ghost');
        $state = $this->build($builder);

        $this->assertEmpty($state->world->allObjects());
    }

    // ── Invariant: assertWorldFact applies last-write-wins per key ────────────

    public function test_assert_world_fact_stores_fact(): void
    {
        $builder = new NarrativeStateBuilder();
        $fact    = new WorldFact(key: 'weather', value: 'rainy', assertedAt: -1);

        $builder->assertWorldFact($fact);
        $state = $this->build($builder);

        $this->assertSame($fact, $state->world->getFact('weather'));
    }

    public function test_assert_world_fact_last_write_wins_per_key(): void
    {
        $builder = new NarrativeStateBuilder();
        $builder->assertWorldFact(new WorldFact(key: 'weather', value: 'rainy', assertedAt: -1));
        $sunny = new WorldFact(key: 'weather', value: 'sunny', assertedAt: 0);
        $builder->assertWorldFact($sunny);

        $state = $this->build($builder);

        $this->assertSame('sunny', $state->world->getFact('weather')?->value);
        $this->assertCount(1, $state->world->allFacts());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function object(string $id, array $attributes = []): WorldObject
    {
        return new WorldObject(id: $id, type: WorldObjectType::PROP, label: $id, attributes: AttributeBag::from($attributes));
    }

    private function build(NarrativeStateBuilder $builder): \App\Services\AI\FilmOS\Narrative\Timeline\NarrativeState
    {
        return $builder->build(
            NarrativeState::SCHEMA_VERSION,
            new ProjectionMetadata(projectionTimeMs: 0, eventCount: 0, lastOrdinal: -1, generatedAt: time()),
        );
    }
}
