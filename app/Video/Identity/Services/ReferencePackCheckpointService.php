<?php

declare(strict_types=1);

namespace App\Video\Identity\Services;

use App\Models\RenderQaRun;
use App\Models\VideoRender;
use App\Video\Identity\Enums\ReferenceAssetStatus;
use App\Video\Identity\Enums\ReferencePackStatus;
use App\Video\Identity\Models\ReferencePackAsset;
use App\Video\Render\Enums\QaDecision;
use App\Video\Render\Enums\QaStatus;
use App\Video\Render\Enums\RenderStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ReferencePackCheckpointService
{
    public function markReferencePassed(
        ReferencePackAsset $asset,
        VideoRender $render,
        RenderQaRun $qaRun,
    ): ReferencePackAsset {
        return DB::transaction(function () use ($asset, $render, $qaRun): ReferencePackAsset {
            $asset = ReferencePackAsset::query()
                ->with('referencePack.assets')
                ->whereKey($asset->id)
                ->lockForUpdate()
                ->firstOrFail();

            $render = VideoRender::query()->whereKey($render->id)->lockForUpdate()->firstOrFail();
            $qaRun = RenderQaRun::query()->whereKey($qaRun->id)->firstOrFail();

            if ($render->execution_status !== RenderStatus::SUCCEEDED) {
                throw new RuntimeException('Reference render must succeed before checkpoint.');
            }

            if ($qaRun->render_id !== $render->id) {
                throw new RuntimeException('Reference QA run does not belong to render.');
            }

            if ($qaRun->status !== QaStatus::COMPLETED || $qaRun->decision !== QaDecision::PASS) {
                throw new RuntimeException('Reference QA must pass before checkpoint.');
            }

            $artifact = $this->primaryImageArtifact($render);

            $asset->forceFill([
                'status' => ReferenceAssetStatus::PASSED,
                'render_id' => $render->id,
                'artifact_id' => (string) $artifact['artifact_id'],
                'artifact_hash' => (string) $artifact['sha256'],
                'artifact_storage_key' => (string) $artifact['storage_key'],
                'mime_type' => (string) $artifact['mime_type'],
                'width' => $artifact['width'] ?? $render->width,
                'height' => $artifact['height'] ?? $render->height,
                'qa_run_id' => $qaRun->id,
                'qa_report_hash' => $qaRun->qa_report_hash,
            ])->save();

            if ($this->allRequiredPassed($asset)) {
                $asset->referencePack->forceFill([
                    'status' => ReferencePackStatus::QA_PENDING,
                ])->save();
            }

            return $asset->refresh();
        }, attempts: 3);
    }

    private function allRequiredPassed(ReferencePackAsset $asset): bool
    {
        foreach ($asset->referencePack->assets as $packAsset) {
            if ($packAsset->required && $packAsset->status !== ReferenceAssetStatus::PASSED) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function primaryImageArtifact(VideoRender $render): array
    {
        foreach (($render->artifact_manifest['artifacts'] ?? []) as $artifact) {
            if (($artifact['kind'] ?? null) !== 'generated_image') {
                continue;
            }

            foreach (['artifact_id', 'sha256', 'storage_key', 'mime_type'] as $key) {
                if (! is_string($artifact[$key] ?? null) || $artifact[$key] === '') {
                    throw new RuntimeException('Reference artifact missing '.$key.'.');
                }
            }

            return $artifact;
        }

        throw new RuntimeException('Reference render has no generated image artifact.');
    }
}
