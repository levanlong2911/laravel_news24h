<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Provider;

use RuntimeException;

/**
 * Thrown by MockKlingProvider when a forced failure is triggered.
 *
 * Message convention: "provider:<name> <code> <reason>"
 * ExecutionRuntime extracts the provider name from this prefix
 * to record per-provider failure counts in ExecutionMetrics.
 */
final class ProviderException extends RuntimeException {}
