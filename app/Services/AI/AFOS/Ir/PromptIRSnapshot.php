<?php

namespace App\Services\AI\AFOS\Ir;

use App\Services\AI\AFOS\Benchmark\SemanticHashPolicy;
use App\Services\AI\AFOS\Compiler\Diagnostics\DiagnosticBag;
use App\Services\AI\AFOS\Creative\Intent;
use App\Services\AI\AFOS\Passes\Optimizer\ExecutionPlan;
use App\Services\AI\AFOS\Passes\Pipeline\CompilerMetrics;
use App\Services\AI\AFOS\Passes\Pipeline\StageCost;
use App\Services\AI\AFOS\Passes\Pipeline\StageProfile;

/**
 * PromptIRSnapshot — immutable record of one compiler run.
 *
 * STRUCTURE
 * ---------
 *   SemanticState   — backend-agnostic semantic IR (what the shot means)
 *   PromptArtifacts — backend-specific output (what Kling/Veo/Runway receives)
 *   DiagnosticBag   — compiler errors/warnings/hints emitted during this run
 *
 * When Runway support is added, a second PromptArtifacts is attached.
 * SemanticState and semanticHash are unchanged — the snapshot is backend-stable.
 *
 * SCHEMA VERSION
 * --------------
 * schemaVersion = SemanticHashPolicy::SCHEMA_VERSION at build time.
 * When new semantic fields are added (e.g. "weather"), the policy version bumps,
 * baselines built with the old version are flagged as schema-incompatible.
 *
 * PRODUCED BY
 * -----------
 * AfosPassManager::compileWithSnapshot(). Benchmark reads this directly.
 * GraphAssembler calls compile() which returns ->artifacts->compiledPrompt.
 */
final class PromptIRSnapshot
{
    public readonly string $semanticHash;

    public function __construct(
        public readonly string          $schemaVersion,
        public readonly string          $shotId,
        public readonly SemanticState   $semantic,
        public readonly PromptArtifacts $artifacts,
        public readonly DiagnosticBag   $diagnostics,
        /** @var StageProfile[] Per-stage timing; ephemeral, not included in toArray(). */
        public readonly array           $profiles       = [],
        /** Pipeline-level cost estimate; ephemeral, not included in toArray(). */
        public readonly ?StageCost      $estimatedCost  = null,
        /** Optimizer execution plan; ephemeral, not included in toArray(). */
        public readonly ?ExecutionPlan  $executionPlan  = null,
    ) {
        $this->semanticHash = (new SemanticHashPolicy())->hash($this->semantic);
    }

    /** Aggregate compile metrics derived from profiles + pipeline cost estimate. Ephemeral — not in toArray(). */
    public function metrics(): CompilerMetrics
    {
        $skipped = count($this->executionPlan?->skippedStages ?? []);
        return CompilerMetrics::fromProfiles($this->profiles, $this->estimatedCost, $skipped);
    }

    public static function build(
        ShotGoalIR      $shot,
        CameraIR        $camera,
        CompositionIR   $composition,
        Intent          $intent,
        string          $compiledPrompt,
        string          $backend       = 'kling',
        ?DiagnosticBag  $diagnostics   = null,
        array           $profiles      = [],
        ?StageCost      $estimatedCost = null,
        ?ExecutionPlan  $executionPlan = null,
    ): self {
        return new self(
            schemaVersion:  SemanticHashPolicy::SCHEMA_VERSION,
            shotId:         $shot->shotId,
            semantic:       SemanticState::build($shot, $camera, $composition, $intent),
            artifacts:      new PromptArtifacts($compiledPrompt, $backend),
            diagnostics:    $diagnostics ?? new DiagnosticBag(),
            profiles:       $profiles,
            estimatedCost:  $estimatedCost,
            executionPlan:  $executionPlan,
        );
    }

    /** Total compile time across all stages in milliseconds. */
    public function totalCompileMs(): float
    {
        return array_sum(array_map(fn(StageProfile $p) => $p->durationMs, $this->profiles));
    }

    public function toArray(): array
    {
        $result = [
            'schema_version' => $this->schemaVersion,
            'shot_id'        => $this->shotId,
            'semantic'       => $this->semantic->toArray(),
            'artifacts'      => $this->artifacts->toArray(),
            'semantic_hash'  => $this->semanticHash,
        ];

        if (!$this->diagnostics->isEmpty()) {
            $result['diagnostics'] = $this->diagnostics->toArray();
        }

        return $result;
    }
}
