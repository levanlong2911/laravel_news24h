<?php

declare(strict_types=1);

namespace App\Video\Identity\Services;

use App\Models\RenderQaRun;
use App\Models\VideoRender;
use App\Video\Identity\Enums\AnchorApprovalStatus;
use App\Video\Identity\Models\ApprovedAnchor;
use App\Video\Render\Enums\QaDecision;
use App\Video\Render\Enums\QaStatus;
use App\Video\Render\Enums\RenderStatus;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class AnchorApprovalService
{
    public function approve(VideoRender $render, string $approvedBy): ApprovedAnchor
    {
        return DB::transaction(function () use ($render, $approvedBy): ApprovedAnchor {
            $render = VideoRender::query()
                ->with(['designImage', 'session', 'latestQaRun'])
                ->whereKey($render->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertRenderCanBeApproved($render);

            $qaRun = $this->passedQaRun($render);
            $artifact = $this->primaryImageArtifact($render);
            $projectId = $render->designImage?->project_id ?? $render->session?->project_id;

            if (! is_string($projectId) || $projectId === '') {
                throw new RuntimeException('Approved anchor render has no project.');
            }

            return ApprovedAnchor::query()->firstOrCreate(
                ['render_id' => $render->id],
                [
                    'video_project_id' => $projectId,
                    'canonical_concept_revision_id' => $render->canonical_concept_revision_id,
                    'canonical_hash' => (string) $render->canonical_hash,
                    'projection_hash' => (string) $render->projection_hash,
                    'constraint_set_hash' => (string) $render->constraint_set_hash,
                    'prompt_spec_hash' => (string) $render->prompt_spec_hash,
                    'render_request_hash' => (string) ($render->request_hash ?? $render->request_sha256),
                    'artifact_id' => (string) $artifact['artifact_id'],
                    'artifact_hash' => (string) $artifact['sha256'],
                    'artifact_storage_key' => (string) $artifact['storage_key'],
                    'mime_type' => (string) $artifact['mime_type'],
                    'width' => $artifact['width'] ?? $render->width,
                    'height' => $artifact['height'] ?? $render->height,
                    'qa_run_id' => $qaRun->id,
                    'qa_report_hash' => (string) $qaRun->qa_report_hash,
                    'status' => AnchorApprovalStatus::APPROVED,
                    'approved_by' => $approvedBy,
                    'approved_at' => now(),
                    'metadata_json' => null,
                ],
            );
        }, attempts: 3);
    }

    private function assertRenderCanBeApproved(VideoRender $render): void
    {
        if ($render->execution_status !== RenderStatus::SUCCEEDED) {
            throw new RuntimeException('Only a succeeded render can be approved as anchor.');
        }

        if ($render->qa_approved !== true) {
            throw new RuntimeException('Anchor render must be QA approved.');
        }
    }

    private function passedQaRun(VideoRender $render): RenderQaRun
    {
        $qaRun = $render->latestQaRun;

        if (! $qaRun instanceof RenderQaRun) {
            throw new RuntimeException('Anchor render has no QA run.');
        }

        if ($qaRun->status !== QaStatus::COMPLETED || $qaRun->decision !== QaDecision::PASS) {
            throw new RuntimeException('Anchor render QA must pass before approval.');
        }

        if (! is_string($qaRun->qa_report_hash) || $qaRun->qa_report_hash === '') {
            throw new RuntimeException('Anchor render QA report hash is missing.');
        }

        return $qaRun;
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
                    throw new RuntimeException('Anchor artifact missing '.$key.'.');
                }
            }

            return $artifact;
        }

        throw new RuntimeException('Anchor render has no generated image artifact.');
    }
}
