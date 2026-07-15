<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Narrative\World;

use App\Services\AI\FilmOS\Narrative\Bootstrap\NarrativeBootstrapper;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEventFactory;
use App\Services\AI\FilmOS\Narrative\Production\ProductionEventFactory;
use App\Services\AI\FilmOS\Narrative\Scene\SceneEventFactory;
use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedEventFactory;
use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedProjectionHandler;
use App\Services\AI\FilmOS\Narrative\Timeline\DefaultTimelineProjector;
use App\Services\AI\FilmOS\Narrative\Timeline\InMemorySemanticTimeline;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineRecorder;
use App\Services\AI\FilmOS\Narrative\Timeline\SystemClock;
use App\Services\AI\FilmOS\Narrative\World\WorldEventFactory;
use App\Services\AI\FilmOS\Narrative\World\WorldFact;
use App\Services\AI\FilmOS\Narrative\World\WorldFactAssertedHandler;
use App\Services\AI\FilmOS\Narrative\World\WorldObject;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectPlacedHandler;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectRemovedHandler;
use App\Services\AI\FilmOS\Narrative\Shared\AttributeBag;
use App\Services\AI\FilmOS\Narrative\World\WorldObjectType;
use App\Services\AI\FilmOS\Planning\GoalNode;
use App\Services\AI\FilmOS\Planning\GoalNodeType;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the full D3 pipeline:
 * WorldContext + GoalNodes → NarrativeBootstrapper → Timeline → Projection → NarrativeState
 */
final class D3PipelineTest extends TestCase
{
    // ── Invariant: world objects appear in projection ─────────────────────────

    public function test_bootstrap_projects_world_objects_into_narrative_state(): void
    {
        [$timeline, $projector] = $this->buildStack();

        $hero  = new WorldObject(id: 'hero',  type: WorldObjectType::CHARACTER, label: 'Hero',  attributes: AttributeBag::empty());
        $villa = new WorldObject(id: 'villa', type: WorldObjectType::LOCATION,  label: 'Villa', attributes: AttributeBag::empty());

        $this->bootstrapper($timeline)->bootstrap(
            worldObjects: [$hero, $villa],
            worldFacts:   [],
            goalNodes:    [],
        );

        $state = $projector->project($timeline);

        $this->assertTrue($state->world->hasObject('hero'));
        $this->assertTrue($state->world->hasObject('villa'));
        $this->assertCount(2, $state->world->allObjects());
    }

    // ── Invariant: world facts appear in projection ───────────────────────────

    public function test_bootstrap_projects_world_facts(): void
    {
        [$timeline, $projector] = $this->buildStack();

        $this->bootstrapper($timeline)->bootstrap(
            worldObjects: [],
            worldFacts:   [
                new WorldFact(key: 'time_of_day', value: 'night', assertedAt: -1),
                new WorldFact(key: 'weather',     value: 'clear', assertedAt: -1),
            ],
            goalNodes: [],
        );

        $state = $projector->project($timeline);

        $this->assertSame('night', $state->world->getFact('time_of_day')?->value);
        $this->assertSame('clear', $state->world->getFact('weather')?->value);
    }

    // ── Invariant: world events use ordinal -1 (before shot 0) ───────────────

    public function test_world_events_have_ordinal_minus_one(): void
    {
        $timeline = new InMemorySemanticTimeline();
        (new TimelineRecorder($timeline))->appendMany(
            ...(new WorldEventFactory(new SystemClock()))->fromWorldContext(
                objects: [new WorldObject(id: 'obj', type: WorldObjectType::PROP, label: 'Obj', attributes: AttributeBag::empty())],
                facts:   [],
            ),
        );

        $events = iterator_to_array($timeline->events());

        $this->assertSame(-1, $events[0]->shotOrdinal());
    }

    // ── Invariant: world baseline does not inflate shot metadata ─────────────

    public function test_world_baseline_events_dont_appear_in_last_ordinal(): void
    {
        [$timeline, $projector] = $this->buildStack();

        $this->bootstrapper($timeline)->bootstrap(
            worldObjects: [new WorldObject(id: 'hero', type: WorldObjectType::CHARACTER, label: 'Hero', attributes: AttributeBag::empty())],
            worldFacts:   [],
            goalNodes:    ['shot_a' => $this->leaf('shot_a', 'Opening shot')],
        );

        $state = $projector->project($timeline);

        // lastOrdinal should be 0 (the shot), not -1 (the world baseline)
        $this->assertSame(0, $state->metadata->lastOrdinal);
    }

    // ── Invariant: D0 + D3 coexist — both story and world in one projection ──

    public function test_d0_and_d3_coexist_in_same_projection(): void
    {
        [$timeline, $projector] = $this->buildStack();

        $this->bootstrapper($timeline)->bootstrap(
            worldObjects: [new WorldObject(id: 'hero', type: WorldObjectType::CHARACTER, label: 'Hero', attributes: AttributeBag::empty())],
            worldFacts:   [new WorldFact(key: 'weather', value: 'sunny', assertedAt: -1)],
            goalNodes:    [
                'hook' => $this->leaf('hook', 'Hook shot'),
                'cta'  => $this->leaf('cta',  'CTA shot'),
            ],
        );

        $state = $projector->project($timeline);

        // D0 story layer intact
        $this->assertCount(2, $state->story->allShots());
        // D3 world layer intact
        $this->assertTrue($state->world->hasObject('hero'));
        $this->assertSame('sunny', $state->world->getFact('weather')?->value);
    }

    // ── Invariant: removed object no longer appears in projection ─────────────

    public function test_removed_object_is_absent_from_projection(): void
    {
        $timeline = new InMemorySemanticTimeline();
        $recorder = new TimelineRecorder($timeline);
        $factory  = new WorldEventFactory(new SystemClock());

        $hero = new WorldObject(id: 'hero', type: WorldObjectType::CHARACTER, label: 'Hero', attributes: AttributeBag::empty());

        $recorder->appendMany(...$factory->fromWorldContext([$hero], [], ordinal: -1));
        $recorder->appendMany(...$factory->removals(['hero'], ordinal: 0));

        [, $projector] = $this->buildStack();
        $state = $projector->project($timeline);

        $this->assertFalse($state->world->hasObject('hero'));
    }

    // ── Invariant: duplicate Placed events → projection has exactly 1 object ──

    public function test_duplicate_placed_events_result_in_single_object(): void
    {
        $timeline = new InMemorySemanticTimeline();
        $recorder = new TimelineRecorder($timeline);
        $factory  = new WorldEventFactory(new SystemClock());

        $hero = new WorldObject(id: 'hero', type: WorldObjectType::CHARACTER, label: 'Hero', attributes: AttributeBag::empty());

        // Place the same hero 3 times (e.g. redundant bootstrap calls)
        $recorder->appendMany(...$factory->fromWorldContext([$hero], []));
        $recorder->appendMany(...$factory->fromWorldContext([$hero], []));
        $recorder->appendMany(...$factory->fromWorldContext([$hero], []));

        [, $projector] = $this->buildStack();
        $state = $projector->project($timeline);

        $this->assertTrue($state->world->hasObject('hero'));
        $this->assertCount(1, $state->world->allObjects(),
            'upsertWorldObject must be idempotent — duplicate Placed events must not create duplicates.'
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array{InMemorySemanticTimeline, DefaultTimelineProjector} */
    private function buildStack(): array
    {
        return [
            new InMemorySemanticTimeline(),
            new DefaultTimelineProjector(handlers: [
                new ShotPlannedProjectionHandler(),
                new WorldObjectPlacedHandler(),
                new WorldObjectRemovedHandler(),
                new WorldFactAssertedHandler(),
            ]),
        ];
    }

    private function bootstrapper(InMemorySemanticTimeline $timeline): NarrativeBootstrapper
    {
        return new NarrativeBootstrapper(
            worldFactory:     new WorldEventFactory(new SystemClock()),
            shotFactory:      new ShotPlannedEventFactory(),
            sceneFactory:     new SceneEventFactory(new SystemClock()),
            characterFactory:  new CharacterEventFactory(new SystemClock()),
            productionFactory: new ProductionEventFactory(new SystemClock()),
            recorder:         new TimelineRecorder($timeline),
        );
    }

    private function leaf(string $id, string $description): GoalNode
    {
        return new GoalNode(id: $id, description: $description, type: GoalNodeType::LEAF, priority: 1.0);
    }
}
