<?php

namespace App\Video\Pipeline;

use App\Video\Article\ArticleNormalizer;
use App\Video\Article\RawArticle;
use App\Video\Director\DirectorInterface;
use App\Video\Editorial\EditorialInterpreter;
use App\Video\Extraction\Extractor;
use App\Video\Gatekeeper\EvidenceGatekeeper;
use App\Video\Intent\IntentPlanner;
use App\Video\Producer\ProducerInterface;
use App\Video\RenderPlan\RenderPlanAssembler;
use App\Video\RenderPlan\RenderPlanMeta;
use App\Video\Scene\ScenePlanner;
use App\Video\Story\StoryPlanner;
use App\Video\Timeline\TimelinePlanner;

/**
 * Noi toan bo Truth Layer + Planning Layer + Creative (Producer/Director) thanh
 * MOT loi goi: RawArticle -> RenderPlan.json. Day la orchestrator con thieu tu
 * dau buoi (ARCHITECTURE.md §7 goi ten "Pipeline/VideoPlanningPipeline").
 *
 * Extractor/Producer/Director la interface — noi Claude* that (co phi, bi
 * GatedLlmClient chan mac dinh — xem ApprovalGate) hoac Fake* (mien phi,
 * deterministic) tuy noi goi quyet dinh. Class nay KHONG biet dang chay that
 * hay gia — dung interface, khong dung class cu the (Rule 2).
 */
final class VideoPlanningPipeline
{
    public function __construct(
        private readonly Extractor $extractor,
        private readonly ProducerInterface $producer,
        private readonly DirectorInterface $director,
        private readonly ArticleNormalizer $normalizer = new ArticleNormalizer(),
        private readonly EvidenceGatekeeper $gatekeeper = new EvidenceGatekeeper(),
        private readonly StoryPlanner $storyPlanner = new StoryPlanner(),
        private readonly ScenePlanner $scenePlanner = new ScenePlanner(),
        private readonly IntentPlanner $intentPlanner = new IntentPlanner(),
        private readonly TimelinePlanner $timelinePlanner = new TimelinePlanner(),
        private readonly EditorialInterpreter $editorial = new EditorialInterpreter(),
        private readonly RenderPlanAssembler $assembler = new RenderPlanAssembler(),
    ) {
    }

    /**
     * @param ?callable(\App\Video\World\VerifiedWorldGraph): void $onWorldVerified Hook quan
     *        sát TUỲ CHỌN, gọi ngay sau Gatekeeper — chỉ để benchmark/đo lường
     *        (vd `video:benchmark`) đọc VerifiedWorldGraph mà KHÔNG phải gọi
     *        Extractor lần 2 (tốn phí gấp đôi) hay chép lại orchestration này.
     *        VideoSessionService (production) không truyền — mặc định null,
     *        không ảnh hưởng hành vi hiện có.
     * @param ?callable(\App\Video\Extraction\ExtractionResult, \App\Video\Evidence\EvidenceIndex): void $onExtracted
     *        Hook quan sát TUỲ CHỌN thứ 2, gọi ngay sau Extractor — CHƯA qua
     *        Gatekeeper. Dùng cho B1 (SemanticClaimPrecisionAnalyzer, xem
     *        project_benchmark_pilot10_findings memory): đo precision
     *        semanticClaims mà KHÔNG ghi gì vào VerifiedWorldGraph. Production
     *        không truyền — mặc định null.
     * @return array<string, mixed> RenderPlan sẵn sàng json_encode + validate schema
     */
    public function plan(RawArticle $article, RenderPlanMeta $meta, float $targetSeconds = 60.0, ?callable $onWorldVerified = null, ?callable $onExtracted = null): array
    {
        // ---- Truth Layer (§11) ----
        $index      = $this->normalizer->normalize($article);
        $extraction = $this->extractor->extract($article, $index);

        if ($onExtracted !== null) {
            $onExtracted($extraction, $index);
        }

        $report = $this->gatekeeper->verify($extraction->candidates, $index);
        $world  = $report->graph;

        if ($onWorldVerified !== null) {
            $onWorldVerified($world);
        }

        // ---- Planning Layer, deterministic (§2) ----
        $story  = $this->storyPlanner->plan($world);
        $scenes = $this->scenePlanner->plan($story, $world);
        $intent = $this->intentPlanner->plan($scenes);
        $timed  = $this->timelinePlanner->plan($intent, $targetSeconds);

        // ---- Creative: Producer (song song, không đụng StoryPlanner — §18.1) ----
        $producerOutput = $this->producer->produce($article, $world);

        // ---- Creative: Director, mỗi scene — chỉ chọn trong candidates (§18.4) ----
        $directorNotesByScene = [];
        foreach ($timed->scenes as $t) {
            $scene = $t->intent->scene;

            $candidates = $this->editorial->candidatesFor($scene, $world);
            if ($candidates['action_candidates'] === []) {
                continue; // không có hành động vật lý hợp lệ nào — bỏ qua, không ép Director chọn
            }

            $selection = $this->director->select($candidates, $world, $producerOutput);
            $resolved  = $selection->resolve($candidates['action_candidates']);
            $chosen    = $candidates['action_candidates'][$selection->primaryCandidateIndex];

            $directorNotesByScene[$scene->id] = array_merge($resolved, [
                'audience_emotion' => $selection->emotion,
                'reveal_strategy'  => $selection->reveal,
                'micro_physics'    => $this->editorial->microPhysicsFor($chosen),
            ]);
        }

        // ---- Emit (§14: RenderPlan bất biến từ đây) ----
        return $this->assembler->assemble($world, $story, $timed, $meta, $producerOutput, $directorNotesByScene);
    }
}
