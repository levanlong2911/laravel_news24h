<?php

namespace App\Services\Video;

use App\Models\Article;
use App\Models\VideoPlanningStage;
use App\Services\VideoRenderPlanService;
use App\Video\Concept\ClaudeConceptDesigner;
use App\Video\Concept\InvalidCreativeConcept;
use App\Video\Inspiration\InspirationBrief;
use App\Video\Llm\LlmUnavailable;
use Illuminate\Support\Facades\Log;

class ConceptStageRunner
{
    private PlanningStageStore $stageStore;

    private VideoRenderPlanService $renderPlanService;

    private VisualIdentityStore $identityStore;

    public function __construct(
        PlanningStageStore $stageStore,
        VideoRenderPlanService $renderPlanService,
        VisualIdentityStore $identityStore,
    ) {
        $this->stageStore = $stageStore;
        $this->renderPlanService = $renderPlanService;
        $this->identityStore = $identityStore;
    }

    /** @return array{0: ?array<string, mixed>, 1: string} */
    public function goToSonnet(
        VideoPlanningStage $stage,
        string $claimToken,
        Article $article,
        InspirationBrief $brief,
        ?string $sessionId = null,
    ): array {
        try {
            $design = $this->renderPlanService->renderConceptStage($article, $brief, $sessionId);
        } catch (\Throwable $e) {
            Log::error('concept-stage: that bai', [
                'stage_id' => $stage->id,
                'article_id' => $article->id,
                'exception' => $e,
            ]);

            $this->stageStore->finishFailed(
                $stage->id, $claimToken, $e->getMessage(), $this->usage(), $this->rawFrom($e),
            );

            return [null, $e->getMessage()];
        }

        $output = $design->concept->toArray();

        $this->stageStore->finishSucceeded(
            $stage->id,
            $claimToken,
            $design->rawResponse,
            $output,
            $this->usage(),
        );

        $identity = $this->identityStore->freezeFromConcept((string) $stage->project_id, $output);

        if ($identity === null) {
            Log::warning('concept-stage: visual identity freeze skipped', [
                'stage_id' => $stage->id,
                'project_id' => $stage->project_id,
            ]);
        }

        return [$output, 'ok'];
    }

    /**
     * Model va phien ban di CUNG so lieu, khong phai thu gan them o duong thanh
     * cong. Giu hai danh sach song song chinh la ly do duong that bai tung mat
     * ca hai truong nay.
     *
     * @return array<string, mixed>
     */
    private function rawFrom(\Throwable $e): string
    {
        if ($e instanceof InvalidCreativeConcept) {
            return $e->rawResponse;
        }

        return $e instanceof LlmUnavailable ? (string) $e->billed?->raw : '';
    }

    private function usage(): array
    {
        $totals = $this->renderPlanService->lastUsage() ?? [];

        return [
            'model' => ClaudeConceptDesigner::MODEL,
            'provider_model' => $totals['provider_model'] ?? null,
            'instruction_version' => ClaudeConceptDesigner::INSTRUCTION_VERSION,
            'tokens_in' => $totals['tokens_in'] ?? 0,
            'tokens_out' => $totals['tokens_out'] ?? 0,
            'thinking_tokens' => $totals['thinking_tokens'] ?? 0,
            'cost_usd' => $totals['cost_usd'] ?? 0,
        ];
    }
}
