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
        private readonly ArticleNormalizer $normalizer = new ArticleNormalizer,
        private readonly EvidenceGatekeeper $gatekeeper = new EvidenceGatekeeper,
        private readonly StoryPlanner $storyPlanner = new StoryPlanner,
        private readonly ScenePlanner $scenePlanner = new ScenePlanner,
        private readonly IntentPlanner $intentPlanner = new IntentPlanner,
        private readonly TimelinePlanner $timelinePlanner = new TimelinePlanner,
        private readonly EditorialInterpreter $editorial = new EditorialInterpreter,
        private readonly RenderPlanAssembler $assembler = new RenderPlanAssembler,
    ) {}

    /**
     * @param  ?callable(\App\Video\World\VerifiedWorldGraph): void  $onWorldVerified  Hook quan
     *                                                                                 sát TUỲ CHỌN, gọi ngay sau Gatekeeper — chỉ để benchmark/đo lường
     *                                                                                 (vd `video:benchmark`) đọc VerifiedWorldGraph mà KHÔNG phải gọi
     *                                                                                 Extractor lần 2 (tốn phí gấp đôi) hay chép lại orchestration này.
     *                                                                                 VideoSessionService (production) không truyền — mặc định null,
     *                                                                                 không ảnh hưởng hành vi hiện có.
     * @param  ?callable(\App\Video\Extraction\ExtractionResult, \App\Video\Evidence\EvidenceIndex): void  $onExtracted
     *                                                                                                                   Hook quan sát TUỲ CHỌN thứ 2, gọi ngay sau Extractor — CHƯA qua
     *                                                                                                                   Gatekeeper. Dùng cho B1 (SemanticClaimPrecisionAnalyzer, xem
     *                                                                                                                   project_benchmark_pilot10_findings memory): đo precision
     *                                                                                                                   semanticClaims mà KHÔNG ghi gì vào VerifiedWorldGraph. Production
     *                                                                                                                   không truyền — mặc định null.
     * @param  ?callable(\App\Video\World\VerifiedWorldGraph): bool  $creativeNeededFor  CHỐT CHI PHÍ (§18.23).
     *                                                                                   Trả `false` ⇒ BỎ HẲN Producer (1 cú Claude) + Director (N cú). null
     *                                                                                   (mặc định) ⇒ luôn chạy, giữ nguyên hành vi cũ cho mọi caller khác.
     *
     *                                                                                   Là PREDICATE chứ không phải cờ bool vì quyết định chỉ đúng SAU khi
     *                                                                                   có VerifiedWorldGraph — người gọi cần nhìn thế giới thật rồi mới
     *                                                                                   biết. Và nó chỉ nhận World, KHÔNG nhận Article/category: pipeline
     *                                                                                   phải mù chủ đề (§1), nó không được biết LÝ DO người gọi từ chối.
     * @return array<string, mixed> RenderPlan sẵn sàng json_encode + validate schema
     */
    public function plan(RawArticle $article, RenderPlanMeta $meta, float $targetSeconds = 60.0, ?callable $onWorldVerified = null, ?callable $onExtracted = null, ?callable $creativeNeededFor = null): array
    {
        // ---- Truth Layer (§11) ----
        $index = $this->normalizer->normalize($article);

        // GUARD 1 — chốt DUY NHẤT chặn được khi CHƯA tốn đồng nào.
        // Index rỗng = ArticleNormalizer không rút được đoạn chữ nào. Gọi
        // Extractor lúc này chắc chắn trả rác, và vẫn bị tính tiền.
        if ($index->isEmpty()) {
            throw PipelineAborted::articleHasNoText($article->id, mb_strlen($article->html));
        }

        $extraction = $this->extractor->extract($article, $index);   // ← TỐN TIỀN (cú gọi 1)

        if ($onExtracted !== null) {
            $onExtracted($extraction, $index);
        }

        $report = $this->gatekeeper->verify($extraction->candidates, $index);
        $world = $report->graph;

        if ($onWorldVerified !== null) {
            // HAI tham số từ 2026-08-06. `$report` mang `rejections` — thứ duy
            // nhất trả lời được "cổng đã loại cái gì, vì lý do gì", và nó chết
            // cùng scope này nếu không đưa ra ngoài.
            //
            // Callback cũ khai một tham số (`BenchmarkRunner`: `function
            // ($verified)`) vẫn chạy nguyên: PHP bỏ qua tham số thừa với hàm do
            // người dùng định nghĩa. Ghi ra đây vì đó là hợp đồng, không phải
            // một tiểu tiết ngôn ngữ để ai đó tình cờ dựa vào.
            $onWorldVerified($world, $report);
        }

        // GUARD 2 — đứng NGAY TRƯỚC Producer + N Director (1 + N cú gọi nữa).
        // Không entity nào sống sót ⇒ không có gì để kể; đi tiếp là trả tiền để
        // dựng kế hoạch quanh một thế giới rỗng.
        if ($world->entities() === []) {
            throw PipelineAborted::nothingSurvivedVerification($report->candidateCount, $report->rejectedCount());
        }

        // ---- Planning Layer, deterministic (§2) — KHÔNG tốn phí ----
        $story = $this->storyPlanner->plan($world);
        $scenes = $this->scenePlanner->plan($story, $world);
        $intent = $this->intentPlanner->plan($scenes);
        $timed = $this->timelinePlanner->plan($intent, $targetSeconds);

        // GUARD 3 — vẫn trước Producer. Có entity nhưng không cảnh nào dựng được
        // (vd mọi entity đều anchor-only). Producer/Director sẽ không có gì để làm.
        if ($timed->scenes === []) {
            throw PipelineAborted::noSceneCouldBePlanned(count($world->entities()), count($story->acts));
        }

        // GUARD 4 — CHỐT CHI PHÍ LỚN NHẤT (§18.23).
        //
        // Người gọi có quyền nói "tôi KHÔNG cần chỉ đạo sáng tạo cho các scene
        // này". Khi đó bỏ hẳn Producer (1 cú) + Director (N cú) — với bài đã đo
        // thật là 9/10 cú gọi.
        //
        // Pipeline KHÔNG biết vì sao người gọi không cần, và không được biết:
        // predicate chỉ nhận VerifiedWorldGraph, không thấy category/Article
        // (§1 — Planning Layer mù chủ đề). Hiện người gọi duy nhất trả `false`
        // là VideoRenderPlanService khi Creation Arc sắp THAY SẠCH scene
        // (§18.22) — lúc đó `director_notes` và `objective` của scene thật bị
        // vứt cùng scene, nên trả tiền sinh ra chúng là trả tiền cho rác.
        if ($creativeNeededFor !== null && ! $creativeNeededFor($world)) {
            return $this->assembler->assemble($world, $story, $timed, $meta);
        }

        // ---- Creative: Producer (song song, không đụng StoryPlanner — §18.1) ----
        $producerOutput = $this->producer->produce($article, $world);   // ← TỐN TIỀN (cú gọi 2)

        // ---- Creative: Director, mỗi scene — chỉ chọn trong candidates (§18.4) ----
        // 2026-07-23: Director giữ "nhật ký" các scene VỪA quay (tối đa 3 scene
        // gần nhất — xem DirectorInterface::select() docblock) để cảnh sau
        // biết cảnh trước đã chọn hero/emotion/composition gì, giống đạo diễn
        // thật giữ cả phim trong đầu thay vì quyết từng shot độc lập.
        $directorNotesByScene = [];
        $directorLog = [];
        $totalScenes = count($timed->scenes);
        foreach ($timed->scenes as $t) {
            $scene = $t->intent->scene;

            $candidates = $this->editorial->candidatesFor($scene, $world);
            if ($candidates['action_candidates'] === []) {
                continue; // không có hành động vật lý hợp lệ nào — bỏ qua, không ép Director chọn
            }

            $selection = $this->director->select($candidates, $world, $producerOutput, $scene->ordinal, $totalScenes, array_slice($directorLog, -3));
            $resolved = $selection->resolve($candidates['action_candidates']);
            $chosen = $candidates['action_candidates'][$selection->primaryCandidateIndex];

            $directorNotesByScene[$scene->id] = array_merge($resolved, [
                'audience_emotion' => $selection->emotion,
                'reveal_strategy' => $selection->reveal,
                'micro_physics' => $this->editorial->microPhysicsFor($chosen),
                'composition_note' => $selection->compositionNote,
            ]);

            $directorLog[] = [
                'ordinal' => $scene->ordinal,
                'hero' => $selection->heroEntity,
                'emotion' => $selection->emotion,
                'composition_note' => $selection->compositionNote,
            ];
        }

        // ---- Emit (§14: RenderPlan bất biến từ đây) ----
        return $this->assembler->assemble($world, $story, $timed, $meta, $producerOutput, $directorNotesByScene);
    }
}
