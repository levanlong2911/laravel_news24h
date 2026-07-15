<?php

declare(strict_types=1);

namespace Tests\Unit\FilmOS\Narrative\Production;

use App\Services\AI\FilmOS\Narrative\Bootstrap\NarrativeBootstrapper;
use App\Services\AI\FilmOS\Narrative\Character\CharacterEventFactory;
use App\Services\AI\FilmOS\Narrative\Production\Conflict;
use App\Services\AI\FilmOS\Narrative\Production\ConflictPlan;
use App\Services\AI\FilmOS\Narrative\Production\ConflictType;
use App\Services\AI\FilmOS\Narrative\Production\ConstraintMode;
use App\Services\AI\FilmOS\Narrative\Production\DirectorIntent;
use App\Services\AI\FilmOS\Narrative\Production\EnergyPoint;
use App\Services\AI\FilmOS\Narrative\Production\HeroMoment;
use App\Services\AI\FilmOS\Narrative\Production\MotifImportance;
use App\Services\AI\FilmOS\Narrative\Production\ProductionEventFactory;
use App\Services\AI\FilmOS\Narrative\Production\ProductionPlan;
use App\Services\AI\FilmOS\Narrative\Production\ProductionPlannedHandler;
use App\Services\AI\FilmOS\Narrative\Production\ShotTiming;
use App\Services\AI\FilmOS\Narrative\Production\VisualConstraint;
use App\Services\AI\FilmOS\Narrative\Production\VisualMotif;
use App\Services\AI\FilmOS\Narrative\Scene\SceneEventFactory;
use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedEventFactory;
use App\Services\AI\FilmOS\Narrative\Timeline\Bridge\ShotPlannedProjectionHandler;
use App\Services\AI\FilmOS\Narrative\Timeline\DefaultTimelineProjector;
use App\Services\AI\FilmOS\Narrative\Timeline\InMemorySemanticTimeline;
use App\Services\AI\FilmOS\Narrative\Timeline\SystemClock;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineOrdinal;
use App\Services\AI\FilmOS\Narrative\Timeline\TimelineRecorder;
use App\Services\AI\FilmOS\Narrative\World\WorldEventFactory;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the Production Layer:
 * planProduction() → Timeline → Projection → NarrativeState::$production
 */
final class ProductionPipelineTest extends TestCase
{
    // ── Invariant: the full plan materializes into the projection ────────────

    public function test_planned_production_appears_in_narrative_state(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->planProduction(new ProductionPlan(
            intent:       new DirectorIntent('The audience should believe the quarterback has already lost.'),
            conflictPlan: new ConflictPlan([
                new Conflict('pocket collapsing',  ConflictType::PHYSICAL),
                new Conflict('three seconds left', ConflictType::TIME),
                new Conflict('receiver too far',   ConflictType::PHYSICAL),
            ]),
            motifs:       [
                new VisualMotif('spiral',      MotifImportance::PRIMARY),
                new VisualMotif('cold breath', MotifImportance::SECONDARY),
            ],
            constraints:  [
                new VisualConstraint(target: 'crowd',    rule: 'blocking the quarterback', mode: ConstraintMode::NEVER),
                new VisualConstraint(target: 'football', rule: 'visible',                  mode: ConstraintMode::ALWAYS),
            ],
            heroMoment:   new HeroMoment(ordinal: 2, description: 'ball exactly overhead, receiver a silhouette'),
            energyPoints: [new EnergyPoint(0, 20), new EnergyPoint(1, 65), new EnergyPoint(2, 100, reason: 'ball leaves hand')],
            timings:      [new ShotTiming(0, 2.0), new ShotTiming(1, 1.2), new ShotTiming(2, 2.5)],
        ));

        $production = $projector->project($timeline)->production;

        $this->assertSame('The audience should believe the quarterback has already lost.', $production->intent()?->objective);
        $this->assertCount(3, $production->conflictPlan()?->conflicts ?? []);
        $this->assertCount(1, $production->conflictPlan()?->ofType(ConflictType::TIME) ?? []);
        $this->assertSame('three seconds left', $production->conflictPlan()?->ofType(ConflictType::TIME)[0]->description);
        $this->assertCount(2, $production->motifs());
        $this->assertSame(MotifImportance::PRIMARY, $production->motifs()[0]->importance);
        $this->assertCount(2, $production->constraints());
        $this->assertSame(ConstraintMode::ALWAYS, $production->constraints()[1]->mode);
        $this->assertSame(2, $production->heroMoment()?->ordinal);
        $this->assertSame('ball leaves hand', $production->energyCurve()[2]->reason, 'reason is benchmark knowledge — WHY energy peaked');
    }

    // ── Point APIs: exact-ordinal lookup, null when unset ─────────────────────

    public function test_energy_and_duration_lookup_by_ordinal(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->planProduction(new ProductionPlan(
            energyPoints: [new EnergyPoint(0, 20), new EnergyPoint(2, 100)],
            timings:      [new ShotTiming(0, 2.0)],
        ));

        $production = $projector->project($timeline)->production;

        $this->assertSame(20,   $production->energyAt(0));
        $this->assertSame(100,  $production->energyAt(2));
        $this->assertNull($production->energyAt(1), 'no point at ordinal 1 — no interpolation in v1');
        $this->assertSame(2.0,  $production->durationAt(0));
        $this->assertNull($production->durationAt(2));
    }

    // ── Collection APIs coexist with point APIs ───────────────────────────────

    public function test_collection_apis_return_full_curve_and_timings(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $points  = [new EnergyPoint(0, 10), new EnergyPoint(1, 45), new EnergyPoint(2, 95)];
        $timings = [new ShotTiming(0, 2.0), new ShotTiming(1, 0.8)];
        $bootstrapper->planProduction(new ProductionPlan(energyPoints: $points, timings: $timings));

        $production = $projector->project($timeline)->production;

        $this->assertSame($points,  $production->energyCurve());
        $this->assertSame($timings, $production->timings());
    }

    // ── Invariant: one plan per production — last-write-wins ─────────────────

    public function test_duplicate_plan_is_last_write_wins(): void
    {
        [$timeline, $projector, $bootstrapper] = $this->buildStack();

        $bootstrapper->planProduction(new ProductionPlan(intent: new DirectorIntent('first')));
        $bootstrapper->planProduction(new ProductionPlan(intent: new DirectorIntent('second')));

        $production = $projector->project($timeline)->production;

        $this->assertSame('second', $production->intent()?->objective);
    }

    // ── A production without a plan is valid ─────────────────────────────────

    public function test_missing_plan_yields_empty_view(): void
    {
        [$timeline, $projector] = $this->buildStack();

        $production = $projector->project($timeline)->production;

        $this->assertNull($production->intent());
        $this->assertNull($production->conflictPlan());
        $this->assertSame([], $production->motifs());
        $this->assertSame([], $production->constraints());
        $this->assertNull($production->heroMoment());
        $this->assertNull($production->energyAt(0));
        $this->assertSame([], $production->energyCurve());
    }

    // ── Event uses BASELINE ordinal ───────────────────────────────────────────

    public function test_production_planned_event_uses_baseline_ordinal(): void
    {
        $factory = new ProductionEventFactory(new SystemClock());
        $event   = $factory->planned(new ProductionPlan());

        $this->assertSame(TimelineOrdinal::BASELINE, $event->shotOrdinal());
        $this->assertSame('production:default', $event->aggregateId());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array{InMemorySemanticTimeline, DefaultTimelineProjector, NarrativeBootstrapper} */
    private function buildStack(): array
    {
        $timeline = new InMemorySemanticTimeline();
        $clock    = new SystemClock();

        $projector = new DefaultTimelineProjector(handlers: [
            new ShotPlannedProjectionHandler(),
            new ProductionPlannedHandler(),
        ]);

        $bootstrapper = new NarrativeBootstrapper(
            worldFactory:      new WorldEventFactory($clock),
            shotFactory:       new ShotPlannedEventFactory(),
            sceneFactory:      new SceneEventFactory($clock),
            characterFactory:  new CharacterEventFactory($clock),
            productionFactory: new ProductionEventFactory($clock),
            recorder:          new TimelineRecorder($timeline),
        );

        return [$timeline, $projector, $bootstrapper];
    }
}
