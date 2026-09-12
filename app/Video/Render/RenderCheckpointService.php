<?php

declare(strict_types=1);

namespace App\Video\Render;

use App\Models\VideoRender;
use App\Models\VideoRenderAttempt;
use App\Video\Concept\Support\Clock;
use App\Video\Render\Cost\RenderCostAccountingService;
use App\Video\Render\DTO\RenderAttemptUsage;
use App\Video\Render\Enums\RenderAttemptStatus;
use App\Video\Render\Enums\RenderStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RenderCheckpointService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly RenderCostAccountingService $costs,
    ) {
    }

    public function complete(
        string $renderId,
        string $claimToken,
        string $requestHash,
        ?string $providerRequestId,
        array $artifactManifest,
        RenderAttemptUsage $usage,
    ): VideoRender {
        return DB::transaction(function () use (
            $renderId,
            $claimToken,
            $requestHash,
            $providerRequestId,
            $artifactManifest,
            $usage,
        ): VideoRender {
            $render = VideoRender::query()->whereKey($renderId)->lockForUpdate()->firstOrFail();

            if ($render->execution_status === RenderStatus::SUCCEEDED) {
                if (! hash_equals((string) $render->request_hash, $requestHash)) {
                    throw new RuntimeException('Succeeded render replay request hash mismatch.');
                }

                return $render;
            }

            if (! hash_equals((string) $render->claim_token, $claimToken)) {
                throw new RuntimeException('Complete claim token mismatch.');
            }

            if (! hash_equals((string) $render->request_hash, $requestHash)) {
                throw new RuntimeException('Complete request hash mismatch.');
            }

            $this->verifyManifest($render, $artifactManifest);

            $attempt = VideoRenderAttempt::query()
                ->where('render_id', $render->id)
                ->where('attempt_no', $render->attempt_count)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                $attempt->provider_request_id !== null
                && $providerRequestId !== null
                && $attempt->provider_request_id !== $providerRequestId
            ) {
                throw new RuntimeException('Provider request ID mismatch.');
            }

            $now = $this->clock->now();

            $attempt->forceFill([
                'status' => RenderAttemptStatus::SUCCEEDED,
                'provider_request_id' => $providerRequestId ?? $attempt->provider_request_id,
                'artifact_manifest' => $artifactManifest,
                'input_tokens' => $usage->inputTokens,
                'output_tokens' => $usage->outputTokens,
                'image_input_tokens' => $usage->imageInputTokens,
                'text_input_tokens' => $usage->textInputTokens,
                'provider_cost_usd' => $usage->providerCostUsd,
                'completed_at' => $now,
            ])->save();

            $this->costs->record($render, $attempt, $usage);

            $render->forceFill([
                'execution_status' => RenderStatus::SUCCEEDED,
                'provider_request_id' => $providerRequestId ?? $render->provider_request_id,
                'artifact_manifest' => $artifactManifest,
                'primary_artifact_hash' => $this->primaryArtifactHash($artifactManifest),
                'failure_class' => null,
                'failure_code' => null,
                'failure_message' => null,
                'claim_token' => null,
                'claimed_by' => null,
                'claimed_at' => null,
                'heartbeat_at' => null,
                'lease_expires_at' => null,
                'next_retry_at' => null,
                'execution_completed_at' => $now,
                'execution_version' => $render->execution_version + 1,
            ])->save();

            return $render;
        }, attempts: 3);
    }

    private function verifyManifest(VideoRender $render, array $manifest): void
    {
        foreach (['render_id', 'request_hash', 'canonical_hash', 'artifacts'] as $key) {
            if (! array_key_exists($key, $manifest)) {
                throw new RuntimeException('Artifact manifest missing '.$key);
            }
        }

        if ($manifest['render_id'] !== $render->id) {
            throw new RuntimeException('Artifact manifest render mismatch.');
        }

        if (! hash_equals((string) $render->request_hash, (string) $manifest['request_hash'])) {
            throw new RuntimeException('Artifact manifest request hash mismatch.');
        }

        if (! hash_equals((string) $render->canonical_hash, (string) $manifest['canonical_hash'])) {
            throw new RuntimeException('Artifact manifest canonical hash mismatch.');
        }

        if (! is_array($manifest['artifacts']) || $manifest['artifacts'] === []) {
            throw new RuntimeException('Artifact manifest has no artifacts.');
        }
    }

    private function primaryArtifactHash(array $manifest): string
    {
        foreach ($manifest['artifacts'] as $artifact) {
            if (($artifact['kind'] ?? null) === 'generated_image') {
                $hash = $artifact['sha256'] ?? null;

                if (is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash)) {
                    return $hash;
                }
            }
        }

        throw new RuntimeException('No generated image artifact found.');
    }
}

