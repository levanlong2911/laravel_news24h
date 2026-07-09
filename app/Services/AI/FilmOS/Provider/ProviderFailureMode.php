<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Provider;

/**
 * Failure scenarios MockKlingProvider can simulate.
 *
 * Effect on ExecutionGraph node:
 *   OK                  → COMPLETED  (happy path)
 *   PAYLOAD_CORRUPTION  → COMPLETED  (response returned but data is poisoned)
 *   RATE_LIMIT          → FAILED     (429; retryable)
 *   SERVER_ERROR        → FAILED     (500; provider crashed)
 *   TIMEOUT             → FAILED     (no response within deadline)
 *   PARTIAL_UPLOAD      → FAILED     (upload started, no completion signal)
 *   STREAM_INTERRUPTED  → FAILED     (response stream cut mid-transfer)
 *   PROVIDER_RESTART    → FAILED     (503; provider restarting)
 */
enum ProviderFailureMode: string
{
    case OK                 = 'ok';
    case PAYLOAD_CORRUPTION = 'payload_corruption';
    case RATE_LIMIT         = 'rate_limit';
    case SERVER_ERROR       = 'server_error';
    case TIMEOUT            = 'timeout';
    case PARTIAL_UPLOAD     = 'partial_upload';
    case STREAM_INTERRUPTED = 'stream_interrupted';
    case PROVIDER_RESTART   = 'provider_restart';
}
