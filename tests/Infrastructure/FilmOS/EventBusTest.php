<?php

declare(strict_types=1);

namespace Tests\Infrastructure\FilmOS;

use App\Services\AI\FilmOS\Capability\CapabilityDescriptor;
use App\Services\AI\FilmOS\Capability\CapabilityRegistry;
use App\Services\AI\FilmOS\Capability\CapabilityType;
use App\Services\AI\FilmOS\EventBus\EventBus;
use App\Services\AI\FilmOS\EventBus\Events\CapabilityResolvedEvent;
use App\Services\AI\FilmOS\EventBus\Events\CheckpointSavedEvent;
use App\Services\AI\FilmOS\EventBus\Events\ExecutionFinishedEvent;
use App\Services\AI\FilmOS\EventBus\Events\ExecutionStartedEvent;
use App\Services\AI\FilmOS\EventBus\Events\NodeCompletedEvent;
use App\Services\AI\FilmOS\EventBus\Events\NodeFailedEvent;
use App\Services\AI\FilmOS\EventBus\Events\QuotaExhaustedEvent;
use App\Services\AI\FilmOS\ExecutionGraph\ExecutionEdge;
use App\Services\AI\FilmOS\ExecutionGraph\ExecutionGraph;
use App\Services\AI\FilmOS\ExecutionGraph\ExecutionNode;
use App\Services\AI\FilmOS\ExecutionGraph\ExecutionRelation;
use App\Services\AI\FilmOS\ExecutionGraph\ExecutionRuntime;
use App\Services\AI\FilmOS\ExecutionGraph\InMemoryCheckpointStore;
use App\Services\AI\FilmOS\Scheduler\ResourceScheduler;
use PHPUnit\Framework\TestCase;

/**
 * EventBus contract tests.
 *
 * Verifies that:
 *   1. Events reach the correct subscribers.
 *   2. Wildcard subscribers see all events.
 *   3. History mode captures events for test introspection.
 *   4. ExecutionRuntime emits lifecycle events correctly.
 *   5. Capability + Scheduler fire events in concert.
 */
final class EventBusTest extends TestCase
{
    private EventBus $bus;

    protected function setUp(): void
    {
        $this->bus = new EventBus(recordHistory: true);
    }

    // ── Core EventBus ─────────────────────────────────────────────────────────

    /** @test */
    public function dispatch_with_no_subscribers_does_not_throw(): void
    {
        $this->bus->dispatch(new ExecutionStartedEvent('exec-1', 5, false));
        $this->assertCount(1, $this->bus->history());
    }

    /** @test */
    public function subscribe_receives_matching_event(): void
    {
        $received = null;
        $this->bus->subscribe('execution.started', function ($e) use (&$received) {
            $received = $e;
        });

        $event = new ExecutionStartedEvent('exec-1', 3, false);
        $this->bus->dispatch($event);

        $this->assertSame($event, $received);
    }

    /** @test */
    public function subscribe_does_not_receive_other_events(): void
    {
        $called = false;
        $this->bus->subscribe('execution.node.failed', function () use (&$called) {
            $called = true;
        });

        $this->bus->dispatch(new ExecutionStartedEvent('exec-1', 3, false));

        $this->assertFalse($called);
    }

    /** @test */
    public function multiple_subscribers_for_same_event_all_called(): void
    {
        $log = [];
        $this->bus->subscribe('execution.started', function () use (&$log) { $log[] = 'A'; });
        $this->bus->subscribe('execution.started', function () use (&$log) { $log[] = 'B'; });
        $this->bus->subscribe('execution.started', function () use (&$log) { $log[] = 'C'; });

        $this->bus->dispatch(new ExecutionStartedEvent('x', 1, false));

        $this->assertSame(['A', 'B', 'C'], $log);
    }

    /** @test */
    public function wildcard_subscriber_receives_all_events(): void
    {
        $count = 0;
        $this->bus->subscribeAll(function () use (&$count) { $count++; });

        $this->bus->dispatch(new ExecutionStartedEvent('x', 1, false));
        $this->bus->dispatch(new NodeCompletedEvent('x', 'n1', 'render', 50.0));
        $this->bus->dispatch(new ExecutionFinishedEvent('x', true, 1, 0, 0, 100.0));

        $this->assertSame(3, $count);
    }

    /** @test */
    public function history_records_all_dispatched_events(): void
    {
        $this->bus->dispatch(new ExecutionStartedEvent('x', 5, false));
        $this->bus->dispatch(new NodeCompletedEvent('x', 'n1', 'task1', 10.0));
        $this->bus->dispatch(new ExecutionFinishedEvent('x', true, 1, 0, 0, 50.0));

        $this->assertCount(3, $this->bus->history());
    }

    /** @test */
    public function history_of_filters_by_event_name(): void
    {
        $this->bus->dispatch(new NodeCompletedEvent('x', 'n1', 'task1', 10.0));
        $this->bus->dispatch(new NodeFailedEvent('x', 'n2', 'task2', 'timeout', 0));
        $this->bus->dispatch(new NodeCompletedEvent('x', 'n3', 'task3', 20.0));
        $this->bus->dispatch(new ExecutionFinishedEvent('x', false, 2, 1, 0, 80.0));

        $completed = $this->bus->historyOf('execution.node.completed');
        $failed    = $this->bus->historyOf('execution.node.failed');

        $this->assertCount(2, $completed);
        $this->assertCount(1, $failed);
    }

    /** @test */
    public function count_of_returns_correct_dispatch_count(): void
    {
        $this->bus->dispatch(new NodeCompletedEvent('x', 'n1', 't1', 1.0));
        $this->bus->dispatch(new NodeCompletedEvent('x', 'n2', 't2', 1.0));
        $this->bus->dispatch(new NodeFailedEvent('x', 'n3', 't3', 'err', 0));

        $this->assertSame(2, $this->bus->countOf('execution.node.completed'));
        $this->assertSame(1, $this->bus->countOf('execution.node.failed'));
        $this->assertSame(0, $this->bus->countOf('execution.started'));
    }

    /** @test */
    public function clear_history_empties_the_log(): void
    {
        $this->bus->dispatch(new ExecutionStartedEvent('x', 1, false));
        $this->bus->clearHistory();

        $this->assertCount(0, $this->bus->history());
    }

    // ── Event payload correctness ─────────────────────────────────────────────

    /** @test */
    public function events_carry_correct_payload(): void
    {
        $checkpointEvent = new CheckpointSavedEvent('exec-42', 4096, 3);
        $this->assertSame([
            'executionId'         => 'exec-42',
            'checkpointSizeBytes' => 4096,
            'completedNodeCount'  => 3,
        ], $checkpointEvent->payload());

        $capEvent = new CapabilityResolvedEvent(CapabilityType::IMAGE_TO_VIDEO, 'kling', 997, 0.08);
        $this->assertSame([
            'capability'       => 'image_to_video',
            'chosenProvider'   => 'kling',
            'quotaRemaining'   => 997,
            'estimatedCostUsd' => 0.08,
        ], $capEvent->payload());

        $exhaustedEvent = new QuotaExhaustedEvent(CapabilityType::VOICE, 'elevenLabs');
        $this->assertSame([
            'capability'            => 'voice',
            'lastProviderAttempted' => 'elevenLabs',
        ], $exhaustedEvent->payload());
    }

    /** @test */
    public function event_occurred_at_is_set_on_construction(): void
    {
        $before = microtime(true);
        $event  = new ExecutionStartedEvent('x', 1, false);
        $after  = microtime(true);

        $this->assertGreaterThanOrEqual($before, $event->occurredAt());
        $this->assertLessThanOrEqual($after,     $event->occurredAt());
    }

    // ── ExecutionRuntime integration ──────────────────────────────────────────

    /** @test */
    public function execution_runtime_emits_lifecycle_events(): void
    {
        $graph = new ExecutionGraph('test-lifecycle', 'prod-1');
        $graph->addNode(new ExecutionNode('n1', 'task.alpha'));
        $graph->addNode(new ExecutionNode('n2', 'task.beta'));
        $graph->addEdge(new ExecutionEdge('n1', 'n2', ExecutionRelation::REQUIRES));

        $runtime = new ExecutionRuntime(new InMemoryCheckpointStore(), $this->bus);
        $runtime->run('exec-lifecycle', $graph, [
            'task.alpha' => fn() => 'alpha done',
            'task.beta'  => fn() => 'beta done',
        ]);

        $this->assertSame(1, $this->bus->countOf('execution.started'));
        $this->assertSame(2, $this->bus->countOf('execution.node.completed'));
        $this->assertSame(0, $this->bus->countOf('execution.node.failed'));
        $this->assertSame(1, $this->bus->countOf('execution.finished'));

        $started = $this->bus->historyOf('execution.started')[0];
        $this->assertInstanceOf(ExecutionStartedEvent::class, $started);
        $this->assertSame('exec-lifecycle', $started->executionId);
        $this->assertFalse($started->resumedFromCheckpoint);

        $finished = $this->bus->historyOf('execution.finished')[0];
        $this->assertInstanceOf(ExecutionFinishedEvent::class, $finished);
        $this->assertTrue($finished->fullyCompleted);
        $this->assertSame(2, $finished->completedCount);
    }

    /** @test */
    public function execution_runtime_emits_node_failed_event(): void
    {
        $graph = new ExecutionGraph('test-failure', 'prod-1');
        $graph->addNode(new ExecutionNode('n1', 'task.fail'));

        $runtime = new ExecutionRuntime(new InMemoryCheckpointStore(), $this->bus);
        $runtime->run('exec-failure', $graph, [
            'task.fail' => fn() => throw new \RuntimeException('provider:kling timeout'),
        ]);

        $this->assertSame(1, $this->bus->countOf('execution.node.failed'));

        $failed = $this->bus->historyOf('execution.node.failed')[0];
        $this->assertInstanceOf(NodeFailedEvent::class, $failed);
        $this->assertSame('n1', $failed->nodeId);
        $this->assertSame('kling', $failed->provider);
    }

    /** @test */
    public function execution_runtime_emits_checkpoint_events(): void
    {
        $graph = new ExecutionGraph('test-checkpoints', 'prod-1');
        $graph->addNode(new ExecutionNode('n1', 'task.a'));
        $graph->addNode(new ExecutionNode('n2', 'task.b'));

        $runtime = new ExecutionRuntime(new InMemoryCheckpointStore(), $this->bus);
        $runtime->run('exec-chk', $graph, [
            'task.a' => fn() => 'done',
            'task.b' => fn() => 'done',
        ]);

        // One checkpoint after each completed/failed/skipped node
        $this->assertSame(2, $this->bus->countOf('execution.checkpoint.saved'));
    }

    // ── Capability + Scheduler + EventBus integration ─────────────────────────

    /** @test */
    public function capability_registry_and_scheduler_work_with_event_bus(): void
    {
        $registry  = new CapabilityRegistry();
        $scheduler = new ResourceScheduler($registry);

        $registry->register(new CapabilityDescriptor('kling', CapabilityType::IMAGE_TO_VIDEO, priority: 100, dailyQuota: 2));
        $registry->register(new CapabilityDescriptor('veo',   CapabilityType::IMAGE_TO_VIDEO, priority: 80));

        $providers = [];

        for ($i = 0; $i < 4; $i++) {
            $decision = $scheduler->schedule(CapabilityType::IMAGE_TO_VIDEO);
            $this->assertNotNull($decision);
            $scheduler->recordUsage($decision->provider);
            $providers[] = $decision->provider;

            $this->bus->dispatch(new CapabilityResolvedEvent(
                $decision->capability,
                $decision->provider,
                $decision->quotaRemaining(),
                $decision->estimatedCostUsd,
            ));
        }

        // First 2 = kling, then fallback to veo
        $this->assertSame(['kling', 'kling', 'veo', 'veo'], $providers);

        // EventBus recorded all 4 resolutions
        $this->assertSame(4, $this->bus->countOf('capability.resolved'));

        $events = $this->bus->historyOf('capability.resolved');
        $this->assertSame('kling', $events[0]->payload()['chosenProvider']);
        $this->assertSame('kling', $events[1]->payload()['chosenProvider']);
        $this->assertSame('veo',   $events[2]->payload()['chosenProvider']);
        $this->assertSame('veo',   $events[3]->payload()['chosenProvider']);
    }
}
