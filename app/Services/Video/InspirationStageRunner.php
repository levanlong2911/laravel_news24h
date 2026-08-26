<?php

namespace App\Services\Video;

use App\Models\Article;
use App\Models\VideoPlanningStage;
use App\Services\VideoRenderPlanService;
use App\Video\Inspiration\ClaudeInspirationAnalyst;
use App\Video\Inspiration\InvalidInspirationBrief;
use Illuminate\Support\Facades\Log;

class InspirationStageRunner
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
    public function callInHaiku(
        VideoPlanningStage $stage,
        string $claimToken,
        Article $article,
        ?string $sessionId = null,
    ): array {
        try {
            $result = $this->renderPlanService->renderInspirationStage($article, $sessionId);
        } catch (\Throwable $e) {
            Log::error('inspiration-stage: that bai', [
                'stage_id' => $stage->id,
                'article_id' => $article->id,
                'exception' => $e,
            ]);

            return $this->fail(
                $stage->id, $claimToken, $e->getMessage(),
                $e instanceof InvalidInspirationBrief ? $e->rawResponse : '',
            );
        }

        $output = $this->renderPlanService->briefForStorage($result->brief, $article);
        $empty = $this->emptinessOf($output);

        if ($empty !== null) {
            Log::error('inspiration-stage: brief rong', [
                'stage_id' => $stage->id,
                'article_id' => $article->id,
                'output' => $output,
            ]);

            // Haiku DA tra loi xong o duong nay — raw dang nam trong tay, chi la
            // truoc day khong ai cam no.
            return $this->fail($stage->id, $claimToken, $empty, $result->rawResponse);
        }

        $this->stageStore->finishSucceeded(
            $stage->id,
            $claimToken,
            $result->rawResponse,
            $output,
            $this->usage(),
        );

        return [$output, 'ok'];
    }

    /** @param array<string, mixed> $output */
    private function emptinessOf(array $output): ?string
    {
        $focus = trim((string) ($output['article_focus'] ?? ''));
        $insights = $output['source_insights'] ?? [];
        $patterns = $output['article_patterns'] ?? [];

        if ($focus !== '' && $insights !== []) {
            return null;
        }

        return sprintf(
            'Haiku tra ve brief rong — focus %s, insights %d, patterns %d',
            $focus === '' ? 'trong' : 'co',
            count($insights),
            count($patterns),
        );
    }

    /** @return array{0: null, 1: string} */
    private function fail(
        string $stageId,
        string $claimToken,
        string $reason,
        string $rawResponse = '',
    ): array {
        $this->stageStore->finishFailed($stageId, $claimToken, $reason, $this->usage(), $rawResponse);

        return [null, $reason];
    }

    /**
     * Xem ghi chu cung ten o ConceptStageRunner.
     *
     * @return array<string, mixed>
     */
    private function usage(): array
    {
        $totals = $this->renderPlanService->lastUsage() ?? [];

        return [
            'model' => 'haiku',
            'provider_model' => $totals['provider_model'] ?? null,
            'instruction_version' => ClaudeInspirationAnalyst::INSTRUCTION_VERSION,
            'tokens_in' => $totals['tokens_in'] ?? 0,
            'tokens_out' => $totals['tokens_out'] ?? 0,
            'thinking_tokens' => $totals['thinking_tokens'] ?? 0,
            'cost_usd' => $totals['cost_usd'] ?? 0,
        ];
    }
}
