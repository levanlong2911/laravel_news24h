<?php

namespace App\Listeners\AI;

use App\Events\AI\LoggableRenderEvent;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Writes a structured log entry for every AI render lifecycle event.
 *
 * ShouldQueue: the listener is dispatched as a queued job so log I/O
 * (Datadog, CloudWatch, Loki, Elasticsearch) does not block the render worker.
 *
 * ShouldHandleEventsAfterCommit: the queued dispatch is deferred until after
 * the DB transaction commits, preventing log entries for rolled-back writes.
 *
 * The log channel defaults to Laravel's 'stack' channel.
 * Override AI_RENDER_LOG_CHANNEL in .env to route to a dedicated channel.
 */
final class LogRenderEvent implements ShouldQueue, ShouldHandleEventsAfterCommit
{
    public function handle(LoggableRenderEvent $event): void
    {
        Log::channel((string) config('ai.log_channel', config('logging.default', 'stack')))
            ->info(class_basename($event), $event->toLog());
    }
}
