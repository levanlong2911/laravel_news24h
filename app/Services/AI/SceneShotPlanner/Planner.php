<?php

namespace App\Services\AI\SceneShotPlanner;

use App\DTOs\BeatDTO;
use App\DTOs\PipelineContext;
use App\DTOs\SceneDTO;
use App\DTOs\StoryDTO;
use App\DTOs\TransformationDTO;
use App\Models\PipelineRun;
use App\Models\VideoProject;

/**
 * Orchestrates the 2-step SceneShotPlanner pipeline:
 *   1. VisualExpansionEngine (Claude Haiku) → VisualMomentDTO[] per beat
 *   2. ShotGrammarEngine (Rule-based)       → ShotDTO[] per beat
 *
 * External contract unchanged: StoryDTO → SceneDTO[]
 * VisualMomentDTO lives only in memory; logged minimally to decision_trace.
 */
final class Planner
{
    public function __construct(
        private readonly VisualExpansionEngine $expansionEngine,
        private readonly ShotGrammarEngine     $grammarEngine,
    ) {}

    /**
     * @return SceneDTO[]
     */
    public function run(
        StoryDTO          $story,
        PipelineContext   $pipeline,
        VideoProject      $project,
    ): array {
        $inputData = $story->toArray();
        $cacheHash = $pipeline->cacheHash($inputData);

        // Cache: same story + same versions → skip all Claude calls
        $cached = PipelineRun::where('stage', 'scene_shot')
            ->where('project_id', $project->id)
            ->where('input_hash', $cacheHash)
            ->where('status', 'completed')
            ->first();

        if ($cached) {
            return array_map(fn (array $s) => SceneDTO::fromArray($s), $cached->output_json['scenes']);
        }

        $startedAt       = now();
        $totalInputTokens  = 0;
        $totalOutputTokens = 0;
        $totalCost         = 0.0;
        $allMomentTraces   = [];
        $scenes            = [];

        foreach ($story->beats() as $beat) {
            // Step 1 — Visual Expansion (AI)
            $moments = $this->expansionEngine->expand($beat);

            // Collect traces for decision_trace (minimal: intent + importance only)
            foreach ($moments as $moment) {
                $allMomentTraces[] = $moment->toTraceArray();
            }

            // Step 2 — Shot Grammar (Rule Engine)
            $shots = $this->grammarEngine->expand(
                informationType: $beat->informationType,
                visualPriority:  $beat->visualPriority,
                beatEmotion:     $beat->emotion,
                beatDuration:    $beat->duration,
                moments:         $moments,
            );

            $scenes[] = new SceneDTO(
                sceneId:     'beat-' . $beat->beatNumber,
                sceneNumber: $beat->beatNumber,
                title:       $this->titleFromBeat($beat),
                emotion:     $beat->emotion,
                duration:    $beat->duration,
                shots:       $shots,
            );
        }

        $outputJson = ['scenes' => array_map(fn (SceneDTO $s) => $s->toArray(), $scenes)];

        $decisionTrace = [
            'planner'          => 'SceneShotPlanner',
            'beat_count'       => $story->beatCount(),
            'scene_count'      => count($scenes),
            'visual_expansion' => $allMomentTraces,
        ];

        PipelineRun::create([
            'project_id'       => $project->id,
            'stage'            => 'scene_shot',
            'stage_version'    => $pipeline->plannerVersion,
            'contract_version' => $pipeline->contractVersion,
            'workflow_version' => $pipeline->workflowVersion,
            'input_hash'       => $cacheHash,
            'output_hash'      => hash('sha256', json_encode($outputJson)),
            'input_json'       => $inputData,
            'output_json'      => $outputJson,
            'duration_ms'      => (int) $startedAt->diffInMilliseconds(now()),
            'cost_usd'         => $totalCost,
            'token_input'      => $totalInputTokens,
            'token_output'     => $totalOutputTokens,
            'decision_trace'   => $decisionTrace,
            'status'           => 'completed',
            'started_at'       => $startedAt,
            'finished_at'      => now(),
        ]);

        $project->update(['status' => 'scene_planned']);

        return $scenes;
    }

    /** Short title derived from beat narrative_intent (first clause only). */
    private function titleFromBeat(BeatDTO $beat): string
    {
        $intent = $beat->narrativeIntent;
        $parts  = preg_split('/[,;—\-]/', $intent);
        $title  = trim($parts[0] ?? $intent);
        return mb_strlen($title) > 60 ? mb_substr($title, 0, 57) . '...' : $title;
    }
}
