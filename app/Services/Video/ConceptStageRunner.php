<?php

namespace App\Services\Video;

use App\Models\Article;
use App\Models\VideoPlanningStage;
use App\Services\VideoRenderPlanService;
use App\Video\Concept\ClaudeConceptDesigner;
use App\Video\Concept\InvalidCreativeConcept;
use App\Video\Inspiration\InspirationBrief;
use Illuminate\Support\Facades\Log;

class ConceptStageRunner
{
    private PlanningStageStore $stageStore;

    private VideoRenderPlanService $renderPlanService;

    public function __construct(
        PlanningStageStore $stageStore,
        VideoRenderPlanService $renderPlanService,
    ) {
        $this->stageStore = $stageStore;
        $this->renderPlanService = $renderPlanService;
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
                $stage->id, $claimToken, $e->getMessage(), $this->usage(),
                $e instanceof InvalidCreativeConcept ? $e->rawResponse : '',
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

        return [$output, 'ok'];
    }

    /**
     * Model va phien ban di CUNG so lieu, khong phai thu gan them o duong thanh
     * cong. Giu hai danh sach song song chinh la ly do duong that bai tung mat
     * ca hai truong nay.
     *
     * @return array<string, mixed>
     */
    private function usage(): array
    {
        $totals = $this->renderPlanService->lastUsage() ?? [];

        return [
            'model' => 'sonnet',
            'instruction_version' => ClaudeConceptDesigner::INSTRUCTION_VERSION,
            'tokens_in' => $totals['tokens_in'] ?? 0,
            'tokens_out' => $totals['tokens_out'] ?? 0,
            'cost_usd' => $totals['cost_usd'] ?? 0,
        ];
    }
}
