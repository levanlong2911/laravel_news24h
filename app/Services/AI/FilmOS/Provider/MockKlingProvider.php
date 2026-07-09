<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Provider;

/**
 * Deterministic mock of the Kling AI provider.
 *
 * All failures are explicitly forced — no randomness, no real HTTP calls.
 * Same taskId + same forced mode → same outcome, every run.
 *
 * Failure modes and their effect on ExecutionGraph:
 *   OK               → COMPLETED  (happy path)
 *   RATE_LIMIT       → FAILED     (429; retryable)
 *   SERVER_ERROR     → FAILED     (500; provider crash)
 *   TIMEOUT          → FAILED     (no response within deadline)
 *   PARTIAL_UPLOAD   → FAILED     (upload started, no completion signal)
 *   STREAM_INTERRUPTED → FAILED   (response stream cut mid-transfer)
 *   PAYLOAD_CORRUPTION → COMPLETED with bad data (caller must validate)
 *   PROVIDER_RESTART → FAILED     (provider went down; retry after warm-up)
 *
 * PAYLOAD_CORRUPTION is the only mode that returns a ProviderResponse (not throws).
 * The caller is responsible for validating the response before treating it as success.
 * In ExecutionGraph terms: the node will be COMPLETED but the result is poisoned.
 *
 * Usage:
 *   $provider = new MockKlingProvider();
 *   $provider->forceFailure('render_F2', ProviderFailureMode::SERVER_ERROR);
 *   $provider->render('render_F1', $prompt); // → ProviderResponse (success)
 *   $provider->render('render_F2', $prompt); // → throws ProviderException (500)
 */
final class MockKlingProvider
{
    /** @var array<string, ProviderFailureMode>  taskId → forced failure mode */
    private array $forced = [];

    public function forceFailure(string $taskId, ProviderFailureMode $mode): void
    {
        $this->forced[$taskId] = $mode;
    }

    /**
     * Simulate a provider render call.
     *
     * @throws ProviderException on any failure mode except OK and PAYLOAD_CORRUPTION.
     */
    public function render(string $taskId, string $prompt): ProviderResponse
    {
        $mode = $this->forced[$taskId] ?? ProviderFailureMode::OK;

        return match ($mode) {
            ProviderFailureMode::OK => new ProviderResponse(
                taskId:         $taskId,
                videoUrl:       "https://mock.kling.ai/{$taskId}.mp4",
                prompt:         $prompt,
                latencyMs:      42.0,
                providerTaskId: "kling_{$taskId}",
            ),

            ProviderFailureMode::PAYLOAD_CORRUPTION => new ProviderResponse(
                taskId:         $taskId,
                videoUrl:       "https://mock.kling.ai/{$taskId}-corrupted.mp4\x00\xFF",
                prompt:         $prompt,
                latencyMs:      38.0,
                providerTaskId: "kling_{$taskId}_corrupt",
            ),

            ProviderFailureMode::RATE_LIMIT => throw new ProviderException(
                "provider:kling 429 Rate limit exceeded [{$taskId}]",
            ),

            ProviderFailureMode::SERVER_ERROR => throw new ProviderException(
                "provider:kling 500 Internal server error [{$taskId}]",
            ),

            ProviderFailureMode::TIMEOUT => throw new ProviderException(
                "provider:kling timeout No response within deadline [{$taskId}]",
            ),

            ProviderFailureMode::PARTIAL_UPLOAD => throw new ProviderException(
                "provider:kling 408 Upload incomplete — no completion signal [{$taskId}]",
            ),

            ProviderFailureMode::STREAM_INTERRUPTED => throw new ProviderException(
                "provider:kling stream_error Response stream interrupted mid-transfer [{$taskId}]",
            ),

            ProviderFailureMode::PROVIDER_RESTART => throw new ProviderException(
                "provider:kling 503 Provider restarting — retry after warm-up [{$taskId}]",
            ),
        };
    }

    /** All forced overrides — for test assertions. */
    public function forcedFailures(): array
    {
        return $this->forced;
    }
}
