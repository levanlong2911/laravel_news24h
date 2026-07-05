<?php

namespace App\Services\AI\AFOS\Passes\Events;

/**
 * CallbackEventBus — dispatches every event to a single closure.
 *
 * Useful in tests (collect events for assertion) and benchmark
 * tooling (stream events to console or storage in real-time).
 *
 * Usage:
 *   $bus = new CallbackEventBus(function (PipelineEvent $e) {
 *       if ($e instanceof StageStarted) { echo "→ {$e->stageName}\n"; }
 *       if ($e instanceof StageFinished) { echo "✓ {$e->stageName} {$e->profile->durationMs}ms\n"; }
 *   });
 *   $manager = AfosPassManager::defaults()->withEventBus($bus);
 */
final class CallbackEventBus implements PipelineEventBus
{
    public function __construct(private readonly \Closure $callback) {}

    public function dispatch(PipelineEvent $event): void
    {
        ($this->callback)($event);
    }
}
