<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Narrative\World;

use App\Services\AI\FilmOS\Narrative\Timeline\Projection\WorldProjection;
use App\Services\AI\FilmOS\Narrative\World\WorldFact;
use App\Services\AI\FilmOS\Narrative\World\WorldObject;
use App\Services\AI\FilmOS\Narrative\Shared\AttributeBag;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectType;
use PHPUnit\Framework\TestCase;

final class WorldProjectionTest extends TestCase
{
    // ── Invariant: hasObject reflects what was provided ───────────────────────

    public function test_has_object_returns_true_for_existing_id(): void
    {
        $obj        = $this->object('hero');
        $projection = new WorldProjection(objects: ['hero' => $obj]);

        $this->assertTrue($projection->hasObject('hero'));
        $this->assertFalse($projection->hasObject('villain'));
    }

    // ── Invariant: getFact returns null for missing key ───────────────────────

    public function test_get_fact_returns_null_for_unknown_key(): void
    {
        $projection = new WorldProjection();

        $this->assertNull($projection->getFact('weather'));
    }

    public function test_get_fact_returns_fact_for_known_key(): void
    {
        $fact       = new WorldFact(key: 'weather', value: 'rainy', assertedAt: 0);
        $projection = new WorldProjection(facts: ['weather' => $fact]);

        $this->assertSame($fact, $projection->getFact('weather'));
    }

    // ── Invariant: allObjects / allFacts return full keyed arrays ─────────────

    public function test_all_objects_returns_full_map(): void
    {
        $hero    = $this->object('hero');
        $door    = $this->object('door');
        $proj    = new WorldProjection(objects: ['hero' => $hero, 'door' => $door]);

        $this->assertSame(['hero' => $hero, 'door' => $door], $proj->allObjects());
    }

    public function test_all_facts_returns_full_map(): void
    {
        $fact = new WorldFact(key: 'time_of_day', value: 'night', assertedAt: -1);
        $proj = new WorldProjection(facts: ['time_of_day' => $fact]);

        $this->assertSame(['time_of_day' => $fact], $proj->allFacts());
    }

    // ── Invariant: empty projection is valid ──────────────────────────────────

    public function test_empty_projection_is_valid(): void
    {
        $proj = new WorldProjection();

        $this->assertEmpty($proj->allObjects());
        $this->assertEmpty($proj->allFacts());
    }

    private function object(string $id): WorldObject
    {
        return new WorldObject(id: $id, type: WorldObjectType::CHARACTER, label: ucfirst($id), attributes: AttributeBag::empty());
    }
}
