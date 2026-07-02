<?php

namespace App\Services\AI\StoryPlanner;

use App\DTOs\ArticleContextDTO;
use App\DTOs\PipelineContext;
use App\DTOs\StoryDTO;
use App\DTOs\TransformationDTO;
use App\Models\PipelineRun;
use App\Models\VideoProject;
use App\Services\Admin\ClaudeWriterService;
use App\Services\AI\Validators\StoryValidator;

final class Planner
{
    public function __construct(
        private readonly ClaudeWriterService $claude,
        private readonly StoryValidator      $validator,
    ) {}

    public function run(
        ArticleContextDTO  $ctx,
        TransformationDTO  $transformation,
        PipelineContext    $pipeline,
        VideoProject       $project,
    ): StoryDTO {
        $inputData = array_merge($ctx->toArray(), $transformation->toArray());
        $cacheHash = $pipeline->cacheHash($inputData);

        // Cache: same input + same versions → skip Claude call
        $cached = PipelineRun::where('stage', 'story')
            ->where('input_hash', $cacheHash)
            ->where('status', 'completed')
            ->first();

        if ($cached) {
            $story = StoryDTO::fromArray($cached->output_json);
            $this->validator->validate($story, $transformation);
            return $story;
        }

        $prompt    = Prompt::build($ctx, $transformation);
        $startedAt = now();
        $response  = $this->claude->generate($prompt, 'sonnet', '');
        $durationMs = (int) ($startedAt->diffInMilliseconds(now()));

        $parsed = $this->parseJson($response->text);
        if ($parsed === null || empty($parsed['beats'])) {
            throw new \RuntimeException("StoryPlanner: Claude returned unparseable JSON for project {$project->id}");
        }

        // beat_number assigned by code (1..N), never by Claude
        foreach ($parsed['beats'] as $i => &$beat) {
            $beat['beat_number'] = $i + 1;
        }
        unset($beat);

        $story = StoryDTO::fromArray($parsed);
        $this->validator->validate($story, $transformation);

        // Record to pipeline_runs event log
        PipelineRun::create([
            'project_id'       => $project->id,
            'stage'            => 'story',
            'stage_version'    => $pipeline->plannerVersion,
            'contract_version' => $pipeline->contractVersion,
            'workflow_version' => $pipeline->workflowVersion,
            'input_hash'       => $cacheHash,
            'output_hash'      => hash('sha256', json_encode($story->toArray())),
            'input_json'       => $inputData,
            'output_json'      => $story->toArray(),
            'duration_ms'      => $durationMs,
            'cost_usd'         => ClaudeWriterService::costUsd($response->inputTokens, $response->outputTokens, 'sonnet'),
            'token_input'      => $response->inputTokens,
            'token_output'     => $response->outputTokens,
            'status'           => 'completed',
            'started_at'       => $startedAt,
            'finished_at'      => now(),
        ]);

        $project->update([
            'story_json' => $story->toArray(),
            'status'     => 'story_planned',
        ]);

        return $story;
    }

    private function parseJson(string $text): ?array
    {
        $text = trim(preg_replace(['/^```(?:json)?\s*/i', '/\s*```$/'], '', $text));
        $decoded = json_decode($text, true);
        return is_array($decoded) ? $decoded : null;
    }
}
