<?php

namespace App\Events\AI;

/**
 * Marker interface for AI render pipeline events that support structured logging.
 * All render lifecycle events implement this so LogRenderEvent can handle them generically.
 */
interface LoggableRenderEvent
{
    /** Returns the key-value context that should be written to the log. */
    public function toLog(): array;
}
