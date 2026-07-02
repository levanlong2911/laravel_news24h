<?php

namespace App\Services\AI\TransformationPlanner;

use App\DTOs\ArticleContextDTO;
use App\DTOs\PipelineContext;
use App\DTOs\TransformationDTO;
use App\Models\PipelineRun;
use App\Models\VideoProject;
use App\Services\AI\PromptCompiler\Libraries\LibraryVersions;
use App\Services\AI\Validators\TransformationValidator;

/**
 * Rule-based: zero Claude cost. Reads config/video_planning.php to pick
 * the template. Writes decision_trace to pipeline_runs for auditability.
 *
 * Rule 1 enforced: output contains no provider names.
 */
final class Planner
{
    public function __construct(
        private readonly TransformationValidator $validator,
    ) {}

    public function run(ArticleContextDTO $ctx, PipelineContext $pipeline, VideoProject $project): TransformationDTO
    {
        $startedAt = now();

        [$templateKey, $matchedRule] = $this->resolveTemplateKey($ctx->domain, $ctx->category);
        $template = config("video_planning.templates.{$templateKey}");

        $duration = (int) config('video_planning.duration.default_seconds', 15);

        $dto = new TransformationDTO(
            theme:        $ctx->domain,
            style:        $template['style'],
            duration:     $duration,
            emotionArc:   $template['emotion_arc'],
            colorPalette: $template['color_palette'],
            pacing:       $template['pacing'],
        );

        $this->validator->validate($dto);

        $decisionTrace = [
            'planner'           => 'TransformationPlanner',
            'rules'             => [
                "matched_keyword={$matchedRule}",
                "selected_template={$templateKey}",
                "duration={$duration}s",
                'template_version=' . config('video_planning.template_version', '1.0'),
            ],
            'selected_template'  => $templateKey,
            'input_domain'       => $ctx->domain,
            'input_category'     => $ctx->category,
            'library_versions'   => LibraryVersions::all(),
        ];

        $inputData = $ctx->toArray();
        $cacheHash = $pipeline->cacheHash($inputData);

        PipelineRun::create([
            'project_id'       => $project->id,
            'stage'            => 'transformation',
            'stage_version'    => $pipeline->plannerVersion,
            'contract_version' => $pipeline->contractVersion,
            'workflow_version' => $pipeline->workflowVersion,
            'input_hash'       => $cacheHash,
            'output_hash'      => hash('sha256', json_encode($dto->toArray())),
            'input_json'       => $inputData,
            'output_json'      => $dto->toArray(),
            'duration_ms'      => (int) $startedAt->diffInMilliseconds(now()),
            'cost_usd'         => 0.0,
            'token_input'      => 0,
            'token_output'     => 0,
            'decision_trace'   => $decisionTrace,
            'status'           => 'completed',
            'started_at'       => $startedAt,
            'finished_at'      => now(),
        ]);

        $project->update([
            'theme'               => $dto->theme,
            'style'               => $dto->style,
            'color_palette'       => $dto->colorPalette,
            'pacing'              => $dto->pacing,
            'emotion_arc'         => $dto->emotionArc,
            'transformation_json' => $dto->toArray(),
            'status'              => 'transformation_planned',
        ]);

        return $dto;
    }

    /** @return array{string, string} [templateKey, matchedRule] */
    private function resolveTemplateKey(string $domain, string $category): array
    {
        $text    = strtolower($domain . ' ' . $category);
        $domainMap = config('video_planning.domain_map', []);

        foreach ($domainMap as $keyword => $templateKey) {
            if (str_contains($text, $keyword)) {
                return [$templateKey, $keyword];
            }
        }

        return ['default', 'no_match'];
    }
}
