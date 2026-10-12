<?php

declare(strict_types=1);

namespace App\Video\Concept\Handoff;

/**
 * Phan 12 ky mot chuoi hash tu canonical toi prompt. Laravel tung chi giu moi
 * `prompt` roi tu bam lai sha256 cua no: hai ben trung nhau la may, khong phai
 * duoc bao dam. Lop nay CHO nguyen gia tri Python da ky di tiep, de buoc render
 * dung lai duoc CompiledPrompt thay vi doan lai.
 */
final class CompiledAnchorPrompt
{
    /** @param array<string, mixed> $nativeControls */
    public function __construct(
        public readonly string $prompt,
        public readonly ?string $negativePrompt,
        public readonly array $nativeControls,
        public readonly string $promptVersion,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $canonicalHash,
        public readonly string $projectionHash,
        public readonly string $constraintSetHash,
        public readonly string $coalescedConstraintHash,
        public readonly string $promptSpecHash,
        public readonly string $providerPromptPlanHash,
        public readonly string $promptHash,
        public readonly ?string $negativePromptHash,
        public readonly ?string $promptManifestPath,
        public readonly string $sourceRevisionId,
        public readonly string $assetRole,
        public readonly string $environmentPolicy,
        public readonly string $environmentPolicyVersion,
        public readonly string $subjectState,
        public readonly string $subjectStateVersion,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function lineage(): array
    {
        return [
            'source_revision_id' => $this->sourceRevisionId,
            'asset_role' => $this->assetRole,
            'environment_policy' => $this->environmentPolicy,
            'environment_policy_version' => $this->environmentPolicyVersion,
            'subject_state' => $this->subjectState,
            'subject_state_version' => $this->subjectStateVersion,
            'prompt_version' => $this->promptVersion,
            'provider' => $this->provider,
            'model' => $this->model,
            'canonical_hash' => $this->canonicalHash,
            'projection_hash' => $this->projectionHash,
            'constraint_set_hash' => $this->constraintSetHash,
            'coalesced_constraint_hash' => $this->coalescedConstraintHash,
            'prompt_spec_hash' => $this->promptSpecHash,
            'provider_prompt_plan_hash' => $this->providerPromptPlanHash,
            'prompt_hash' => $this->promptHash,
            'negative_prompt_hash' => $this->negativePromptHash,
            'prompt_manifest_path' => $this->promptManifestPath,
        ];
    }
}
