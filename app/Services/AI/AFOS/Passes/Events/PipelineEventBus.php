<?php

namespace App\Services\AI\AFOS\Passes\Events;

/**
 * PipelineEventBus — receives events emitted by AfosPassManager.
 *
 * Implementations: NullEventBus (default, no-op), CallbackEventBus (testing/benchmark).
 * Future: WebSocketEventBus, OpenTelemetryEventBus, LogEventBus.
 */
interface PipelineEventBus
{
    public function dispatch(PipelineEvent $event): void;
}
