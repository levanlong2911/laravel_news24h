<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Article;
use App\Services\Video\CreativeProfileResolver;
use App\Services\Video\ExtractionArtifactRecorder;
use App\Video\Article\RawArticle;
use App\Video\Concept\ClaudeConceptDesigner;
use App\Video\Concept\InvalidCreativeConcept;
use App\Video\Inspiration\ClaudeInspirationAnalyst;
use App\Video\Inspiration\InvalidInspirationBrief;
use App\Video\Llm\ClaudeWriterAdapter;
use App\Video\Llm\CostAccumulatingLlmClient;
use App\Video\Llm\CostCeilingGate;
use App\Video\Llm\GatedLlmClient;
use App\Video\Llm\LlmClient;
use App\Video\Pipeline\VideoPipelineFactory;
use App\Video\RenderPlan\CreativeRenderPlanBuilder;
use App\Video\RenderPlan\RenderPlanMeta;
use App\Video\Story\ClaudeCreativeArcPlanner;
use App\Video\Story\CreationArcPlanner;
use App\Video\World\EntityType;
use App\Video\World\VerifiedWorldGraph;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Article -> RenderPlan. KHONG luu gi, KHONG doc/ghi VideoSession. `build()`
 * nhan `$videoSessionId` (2026-08-13) nhung CHI dung lam nhan ghi so — mot
 * chuoi di qua toi `recordUsage()`, khong bao gio query/load VideoSession —
 * giu dung tinh than "khong biet VideoSession ton tai" ban dau.
 *
 * Tach ra khoi VideoSessionService (2026-07-29) vi class do dang ganh HAI
 * trach nhiem: vong doi session (list/approve/queue/report — 8 method ngan) va
 * chay pipeline AI (dung LLM stack, pipeline, creation arc — keo theo 6 import
 * chang lien quan gi den session).
 *
 * Day KHONG phai abstraction moi: no soi guong dung quy uoc da co san phia CMS,
 * ghi trong CLAUDE.md — "ArticlePipelineService::run() is the single entry
 * point for AI writing... The caller owns persistence, status updates". Phia
 * CMS da tach nhu vay tu truoc; phia video den nay moi lam.
 *
 * Loi ich do duoc:
 *   - Test khong con phai di qua VideoSessionService (voi 4 mock repository
 *     chang lien quan) chi de cham toi applyCreationArc() — xem
 *     tests/Feature/Video/VideoRenderPlanServiceCreationArcTest.php.
 *
 * CHUA lam (dung ghi la da lam): VideoBenchmark van tu lap LLM stack + pipeline
 * o 2 cho rieng (benchmarkRunner/semanticRunner) vi no can boc them
 * CostAccumulatingLlmClient de cong don chi phi — thu ma production khong can.
 * Trung lap con do. Da chan duoc phan nguy hiem nhat: ca hai ben gio doc CUNG
 * config('video.llm_cost_ceiling_usd') thay vi benchmark hardcode 0.05, nen
 * khong con configuration drift ve tran chi phi.
 */
class VideoRenderPlanService
{
    /**
     * Nhan `action` trong `claude_usage_logs` — TACH KHOI 'send_to_claude' va
     * 'synthesize' cua phia CMS de bao cao chia duoc chi phi video ra rieng.
     * Hai luong dung chung bang nhung KHONG duoc lan nhau.
     */
    public const USAGE_ACTION = 'video_renderplan';

    /**
     * Accumulator cua lan `build()` GAN NHAT — de `recordUsage()` doc totals
     * trong `finally`, ke ca khi pipeline nem.
     *
     * State trong service la co y va co gioi han: `build()` gan lai o dong dau,
     * va service nay resolve theo tung request (khong bind singleton), nen
     * khong co chuyen hai bai lan token cua nhau.
     */
    private ?CostAccumulatingLlmClient $lastRun = null;

    /**
     * @param  ClaudeWriterAdapter  $claudeWriterAdapter  Client THẬT nhưng CHƯA CÓ CỔNG — cố ý.
     *                                                    Cổng duyệt chi (CostCeilingGate) dựng trong spendingLlmClient(),
     *                                                    không tiêm sẵn qua container: quyền tiêu tiền phải nhìn thấy được
     *                                                    tại chỗ dùng. Client thô thì tiêm được vì tự nó chưa tiêu được xu
     *                                                    nào khi chưa có cổng.
     *
     *        Khai CLASS CỤ THỂ, không phải interface LlmClient — cân nhắc rồi
     *        chọn (2026-07-29):
     *          - Laravel tự resolve được (ClaudeWriterService không có
     *            constructor) ⇒ KHÔNG cần binding nào trong ServiceProvider.
     *          - Đọc constructor là biết ngay đang nói chuyện với Claude, không
     *            phải đi tra provider mới hiểu.
     *          - Tiêm interface chỉ đáng khi seam đó CÓ NGƯỜI DÙNG. Ở đây
     *            không: muốn fake LlmClient thì phải giả lập toàn bộ protocol
     *            của Extractor + Producer + Director×N (kèm evidence_quote khớp
     *            đúng văn bản, không khớp thì Gatekeeper loại hết). Seam đúng
     *            tầng đã có sẵn và đang được dùng: FakeExtractor/FakeProducer/
     *            FakeDirector (xem video:benchmark --extractor=fake).
     *
     *        Khi nào đổi sang interface: lúc thật sự có backend LLM thứ hai
     *        (vd Gemini) chạy song song. Chưa có bằng chứng cần — Rule 0.
     * @param  VideoPipelineFactory  $videoPipelineFactory  Dựng VideoPlanningPipeline thật.
     *                                                      Không có state, không có constructor ⇒ Laravel tự resolve, không
     *                                                      cần binding. Tiêm thay vì gọi static để mọi phụ thuộc của class
     *                                                      này đều đọc được ở một chỗ: constructor.
     */
    public function __construct(
        private ClaudeWriterAdapter $claudeWriterAdapter,
        private VideoPipelineFactory $videoPipelineFactory,
        // Ghi bang chung cua Truth Layer. O DAY chu khong o trong pipeline:
        // `app/Video/` khong duoc biet Eloquent ton tai. Pipeline chi goi mot
        // callable; closure duoc dinh nghia o tang nay.
        private ExtractionArtifactRecorder $artifactRecorder = new ExtractionArtifactRecorder,
        private CreativeProfileResolver $creativeProfileResolver = new CreativeProfileResolver,
    ) {}

    /**
     * Chay that Truth -> Story -> Scene -> Intent -> Editorial -> Producer ->
     * Director -> RenderPlan (VideoPlanningPipeline, §18), roi chen Creation
     * Arc neu category khop.
     *
     * CANH BAO: goi Claude 11+ lan (Extractor + Producer + N x Director),
     * thuc te 25-90 giay. Caller KHONG duoc boc ham nay trong DB transaction.
     *
     * @return array<string, mixed> RenderPlan san sang json_encode
     */
    public function build(Article $article, ?string $videoSessionId = null): array
    {
        // Doc mode TRUOC khi dung pipeline: cau hinh go sai khong duoc phat
        // hien sau khi Extractor da tra tien.
        $conceptMode = $this->creativeConceptMode();

        $accumulator = new CostAccumulatingLlmClient($this->spendingLlmClient());
        $this->lastRun = $accumulator;

        $rawArticle = new RawArticle($article->id, $article->title, (string) $article->content);
        $category = (string) ($article->category?->slug ?? '');

        $meta = new RenderPlanMeta(
            Str::uuid()->toString(),
            $article->id,
            $article->title,
            'en',
            now()->toIso8601String(),
            // Slug category THẬT của bài (dữ liệu động trong CMS), KHÔNG lấy từ
            // config('video.creation_arc.categories') — bảng đó chỉ khai 4/27
            // category và phục vụ việc kích hoạt Creation Arc, không phải việc
            // chọn hồ sơ tri thức ngành bên Python.
            $category,
        );

        if ($conceptMode === 'enabled') {
            try {
                return $this->buildCreativePlan(
                    $rawArticle,
                    $category,
                    $accumulator,
                    $meta,
                    $article,
                    $videoSessionId,
                );
            } finally {
                $this->recordUsage($article, $videoSessionId);
            }
        }

        $productionPolicies = $this->videoPipelineFactory->productionPolicies();
        $pipeline = $this->videoPipelineFactory->claude(
            $accumulator,
            $productionPolicies,
            function (string $code, array $context) use ($article, $videoSessionId): void {
                Log::warning('video_pipeline_'.strtolower($code), $context + [
                    'article_id' => $article->id,
                    'video_session_id' => $videoSessionId,
                ]);
            },
        );

        // CHOT CHI PHI (§18.23): bai thuoc category co Creation Arc thi
        // applyCreationArc() se THAY SACH scene that (§18.22) — keo theo
        // `director_notes` va `objective` cua chung. Sinh ra roi vut di la tra
        // tien cho rac: do that tren bai "The Sixth Sense" la 9/10 cu goi
        // (1 Producer + 8 Director).
        //
        // Predicate chay SAU Gatekeeper vi dieu kien thu hai chi biet duoc luc
        // do: arc chi thay scene khi TIM DUOC hero (entity Vehicle). Category
        // khop nhung bai khong co vehicle nao thi arc KHONG kich hoat, scene
        // that song tiep — luc do van phai co Producer/Director.
        $phaseSet = $this->creationArcPhaseSetFor($article);

        // GHEP hai moc cua pipeline. Chung ban o HAI thoi diem khac nhau —
        // `onExtracted` ngay sau khi tra tien cho Claude, `onWorldVerified` sau
        // Gatekeeper — nen ban ghi phai giu `$extraction` lai cho toi luc co
        // `$report`. Bien cuc bo cua MOT luot chay; recorder khong giu state,
        // neu khong hai request song song se tron bang chung cua nhau.
        $extraction = null;

        try {
            $renderPlan = $pipeline->plan(
                $rawArticle,
                $meta,
                onWorldVerified: function ($world, $report) use (&$extraction, $article): void {
                    if ($extraction === null) {
                        return;
                    }

                    // GHI O DAY, khong doi pipeline xong: Story/Scene/Producer/
                    // Director deu co the nem sau dong nay, va khi do bang chung
                    // ve viec Truth Layer da lam gi van phai con.
                    //
                    // TRY/CATCH O CHO GOI, khong o trong recorder: bao dam "dung
                    // cu do khong bao gio lam sap thu no do" phai dung voi MOI
                    // ban cai dat, khong chi voi ban hien tai. Cung luat voi
                    // `PythonRunner` — spawn hong khong bao gio chi mang.
                    //
                    // Nhung KHONG nuot im lang: hong ma khong ai biet thi mot
                    // ngay artifact bien mat va ta lai tuong Extractor khong
                    // chay — tao ra dung lo hong quan sat ma no sinh ra de bit.
                    try {
                        $this->artifactRecorder->record($article, $extraction, $report);
                    } catch (\Throwable $e) {
                        Log::error('video_extraction_artifact_write_failed', [
                            'article_id' => $article->id,
                            'model' => $extraction->model,
                            'instruction_version' => $extraction->instructionVersion,
                            'exception' => $e::class,
                            'message' => $e->getMessage(),
                        ]);
                    }
                },
                onExtracted: function ($result) use (&$extraction): void {
                    $extraction = $result;
                },
                creativeNeededFor: $phaseSet === null
                    ? null
                    : fn (VerifiedWorldGraph $world) => ! $this->hasVehicle($world),
            );

            $renderPlan = $this->applyCreationArc($renderPlan, $article);
            $renderPlan = $this->withCreativeConcept($renderPlan, $conceptMode, $rawArticle, $category, $accumulator, $article, $videoSessionId);
        } finally {
            // `finally` CHU DICH, khong phai cho dep: lan chay HONG cung phai
            // duoc ghi. Tien da tra roi — mot bai truot Gatekeeper hay bi cat
            // tran van tinh phi. Chi ghi khi thanh cong thi so lieu se GIAU
            // dung phan lang phi ma minh dang chong (bang chung: 5 lan cat tran
            // = ~$0.09 khong het thong ke nao ghi lai).
            $this->recordUsage($article, $videoSessionId);
        }

        return $renderPlan;
    }

    /**
     * Ghi MOT hang ClaudeUsageLog cho MOT lan bam 🎬 — cung do hat voi phia CMS
     * (`ArticleController` ghi mot hang cho mot lan `send_to_claude`), nen bao
     * cao hien co (`ClaudeUsageController`) khong bi lech y nghia: `COUNT(*)`
     * van dem HANH DONG, khong dem cu goi API.
     *
     * Khac phia CMS o CHO GHI: CMS ghi tai Controller vi o do co san ca `$admin`
     * lan `PipelineResult`. O day totals nam sau hai tang, va con phai ghi ca
     * khi build() nem — nen ghi ngay canh accumulator la cho duy nhat lam duoc
     * ca hai ma khong phai keo du lieu qua hai tang.
     *
     * Khong co admin dang dang nhap (CLI: `video:benchmark`, queue) thi BO QUA:
     * `claude_usage_logs.admin_id` co khoa ngoai toi `admins`, khong the ghi
     * hang mo coi. Benchmark da co duong do rieng (BenchmarkResult) nen khong
     * mat du lieu.
     */
    private function recordUsage(Article $article, ?string $videoSessionId = null): void
    {
        $totals = $this->lastRun?->totals();

        // `call_count === 0` = chua cu goi nao ra toi API (vd GUARD 1 chan khi
        // bai rong). Khong ton dong nao thi khong co gi de ghi.
        if ($totals === null || $totals['call_count'] === 0) {
            return;
        }

        $admin = auth()->user();

        if (! $admin instanceof Admin) {
            Log::info('Video pipeline: khong co admin dang nhap nen bo qua ClaudeUsageLog', [
                'article_id' => $article->id,
            ] + $totals);

            return;
        }

        $admin->incrementClaudeUsage(
            $article->title,
            $article->source_url ?? '',
            self::USAGE_ACTION,
            $totals['tokens_in'] + $totals['tokens_out'],
            $totals['cost_usd'],
            $article->id,
            $videoSessionId,
        );
    }

    /**
     * Phase set cua Creation Arc cho bai nay, hoac null neu category khong co.
     *
     * Tach ra vi CO HAI noi hoi cung cau hoi o hai thoi diem khac nhau:
     * `build()` hoi TRUOC khi chay pipeline (de quyet dinh co tra tien cho
     * Producer/Director khong), `applyCreationArc()` hoi SAU (de thay scene).
     * Mot ham, khong the lech nhau.
     *
     * @return array<string, mixed>|null
     */
    private function creationArcPhaseSetFor(Article $article): ?array
    {
        $slug = $article->category?->slug;
        $setKey = $slug === null ? null : (config('video.creation_arc.categories', [])[$slug] ?? null);

        if ($setKey === null) {
            return null;
        }

        $set = config('video.creation_arc.phase_sets', [])[$setKey] ?? [];

        return ($set['phases'] ?? []) === [] ? null : $set;
    }

    /**
     * Doc tren VerifiedWorldGraph (domain object), khac `findHeroEntity()` doc
     * tren RenderPlan (mang da serialize). Cung cau hoi, hai hinh dang du lieu
     * o hai thoi diem — gop lam mot se phai serialize som chi de tra loi mot
     * cau hoi yes/no.
     */
    private function hasVehicle(VerifiedWorldGraph $world): bool
    {
        foreach ($world->entities() as $entity) {
            if ($entity->type === EntityType::Vehicle) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dung LLM client theo 3 lop (Decorator), tu trong ra ngoai.
     *
     * Ten ham co y noi thang "spending" — day la NOI DUY NHAT trong luong
     * production cap quyen tieu tien, `grep spendingLlmClient` ra dung mot cho.
     */
    private function spendingLlmClient(): LlmClient
    {
        // BUOC 1 — CHINH SACH DUYET CHI. Chi tra true/false, khong goi gi ca:
        // cho phep neu chi phi UOC LUONG cua MOT cu goi <= tran. Chan duoc bai
        // qua dai hoac loop bat thuong tao mot cu goi cuc dat.
        $approvalGate = new CostCeilingGate(config('video.llm_cost_ceiling_usd'));

        // BUOC 2 — CONG CHAN, boc quanh client da tiem. Moi lan complete():
        // uoc luong token -> quy ra tien -> hoi $approvalGate. Khong cho thi nem
        // ApprovalRequired va CHUA HE goi API (chua ton dong nao).
        //
        // Mac dinh cua GatedLlmClient la DenyByDefaultGate — TU CHOI TAT CA.
        // Nen viec truyen $approvalGate vao day CHINH LA hanh dong cap quyen
        // tieu tien, co y viet tuong minh tai cho dung chu khong bind an trong
        // ServiceProvider. Client tho ($this->claudeWriterAdapter) thi tiem duoc
        // vi no KHONG tu tieu tien duoc gi khi chua co cong.
        return new GatedLlmClient($this->claudeWriterAdapter, $approvalGate);
    }

    /**
     * Ngoai le CO CHU DICH voi Truth Layer ("khong bang chung -> khong ton
     * tai"): chen cac scene BIA (thiet ke -> thi cong -> hoan thien -> thanh
     * pham) TRUOC scene that, CHI khi category bai viet co mat trong
     * config('video.creation_arc.categories').
     *
     * SO LUONG SCENE do phase_set quyet dinh, KHONG co dinh — dung dem so o
     * day (comment cu ghi "3 scene" da sai sau khi len v2). Xem
     * config/video.php va docs/video/ARCHITECTURE.md §18.16.
     *
     * LUON ap dung khi category khop, khong xet bai bao co bang chung thi cong
     * hay khong (quyet dinh nguoi dung 2026-07-24).
     *
     * @param  array<string, mixed>  $renderPlan
     * @return array<string, mixed>
     */
    private function applyCreationArc(array $renderPlan, Article $article): array
    {
        // v2 (§18.16): categories la MAP slug => ten phase_set, khong con la
        // danh sach phang — vi "can truc ha khoi thuong tang" vo nghia voi o to
        // hay mo to, moi nganh can bo scene rieng. Kien thuc nganh van nam
        // hoan toan o data (config), code tra cuu tong quat — khong co nhanh
        // theo domain nao o day (§1 no domain branching).
        //
        // v3 (§18.17): phase_set co 2 khoa — `phases` (cac scene) va `identity`
        // (nhan dang thi giac cap video, 2 trang thai vong doi).
        //
        // Tra cuu dung CUNG ham ma build() da dung de quyet dinh co goi
        // Producer/Director khong — hai noi khong duoc phep lech nhau (§18.23).
        $set = $this->creationArcPhaseSetFor($article);
        if ($set === null) {
            return $renderPlan;
        }

        $hero = $this->findHeroEntity($renderPlan, $article);
        if ($hero === null) {
            return $renderPlan;
        }

        return (new CreationArcPlanner($set['phases'], $set['identity'] ?? []))
            ->mergeInto($renderPlan, $hero['id'], $hero['identity']['name'] ?? null);
    }

    /**
     * Chu the chinh de Creation Arc bam vao: entity Vehicle DAU TIEN trong
     * world.entities.
     *
     * DAY LA HEURISTIC MONG MANH — VA DA DUOC KIEM CHUNG LA MONG MANH.
     *
     * RenderPlan v1.0 KHONG encode khai niem "hero entity": khong co field nao
     * noi bai bao dang ke ve con tau nao. Ham nay dang doan.
     *
     * Bang chung that (bai "The Sixth Sense", 2026-07-29) — RenderPlan co BON
     * entity Vehicle: sixth_sense, mylin_iv, james_ratcliffe_yacht,
     * mark_cuban_yacht. "Vehicle dau tien" ra sixth_sense = DUNG, nhung dung
     * nho THU TU Extractor tra ve, khong phai do thiet ke.
     *
     * Cac nguon thay the DA THU va DA LOAI (dung kiem chung lai):
     *   - acts[0].entity_ref (StoryPlanner xep theo do trung tam do thi):
     *     ra `mylin_iv` — SAI. Do trung tam do do GIAU THUOC TINH (mylin_iv co
     *     5 attribute, sixth_sense chi co 2), khong do "ai la nhan vat chinh".
     *   - Khop ten trong tieu de: tieu de bai do khong he chua "Sixth Sense".
     *
     * Ket luan: khong co tin hieu nao dang tin hon trong du lieu hien co. KHONG
     * thay heuristic nay bang mot heuristic khac kem hon. Cach sua ben vung la
     * them field `hero_entity` tu tang Planner vao RenderPlan contract — khi do
     * cac tang sau khong phai doan nua. Chua lam vi day la thay doi contract da
     * dong bang (§14), can bang chung tu nhieu bai hon.
     *
     * Trong luc do: canh bao khi co nhieu hon MOT vehicle, de nguoi van hanh
     * kiem tra truoc khi tieu tien render.
     *
     * @param  array<string, mixed>  $renderPlan
     * @return array<string, mixed>|null
     */
    private function findHeroEntity(array $renderPlan, Article $article): ?array
    {
        $vehicles = array_values(array_filter(
            $renderPlan['world']['entities'] ?? [],
            fn ($entity) => ($entity['type'] ?? null) === 'vehicle',
        ));

        if ($vehicles === []) {
            return null;
        }

        $hero = $vehicles[0];

        if (count($vehicles) > 1) {
            // Ghi du ngu canh de QA tra nguoc duoc khi video ra sai con tau:
            // biet ngay da chon cai nao va bo qua nhung cai nao.
            Log::warning('CreationArc: nhieu entity Vehicle, hero chon bang heuristic "vehicle dau tien"', [
                'article_id' => $article->id,
                'article_title' => $article->title,
                'vehicle_count' => count($vehicles),
                'selected' => $hero['id'],
                'candidates' => array_column($vehicles, 'id'),
            ]);
        }

        return $hero;
    }

    private function creativeConceptMode(): string
    {
        $mode = (string) config('video.creative_concept.mode', 'disabled');
        if (! in_array($mode, ['disabled', 'observe', 'enabled'], true)) {
            throw new \InvalidArgumentException("video.creative_concept.mode is not one of disabled|observe|enabled: {$mode}");
        }

        return $mode;
    }

    /** @return array<string, mixed> */
    private function buildCreativePlan(
        RawArticle $rawArticle,
        string $category,
        LlmClient $llm,
        RenderPlanMeta $meta,
        Article $article,
        ?string $videoSessionId,
    ): array {
        $profile = $this->creativeProfileResolver->resolve($category);
        if ($profile === null) {
            throw new \InvalidArgumentException("No creative profile is configured for category: {$category}");
        }

        $brief = (new ClaudeInspirationAnalyst($llm))->analyze($rawArticle, $profile);
        $design = (new ClaudeConceptDesigner($llm))->design($brief, $profile);
        if ($design->warnings !== []) {
            Log::warning('video_creative_concept_warnings', [
                'article_id' => $article->id,
                'video_session_id' => $videoSessionId,
                'category' => $category,
                'attempts' => $design->attempts,
                'warnings' => $design->warningsToArray(),
            ]);
        }

        $phases = (new ClaudeCreativeArcPlanner($llm))->plan($design->concept);

        return (new CreativeRenderPlanBuilder)->build($meta, $design->concept, $phases);
    }

    /**
     * @param  array<string, mixed>  $renderPlan
     * @return array<string, mixed>
     */
    private function withCreativeConcept(
        array $renderPlan,
        string $mode,
        RawArticle $rawArticle,
        string $category,
        LlmClient $llm,
        Article $article,
        ?string $videoSessionId,
    ): array {
        if ($mode === 'disabled' || $category === '') {
            return $renderPlan;
        }

        $profile = $this->creativeProfileResolver->resolve($category);
        if ($profile === null) {
            return $renderPlan;
        }

        try {
            $brief = (new ClaudeInspirationAnalyst($llm))->analyze($rawArticle, $profile);
            $design = (new ClaudeConceptDesigner($llm))->design($brief, $profile);
        } catch (InvalidCreativeConcept|InvalidInspirationBrief $e) {
            if ($mode !== 'observe') {
                throw $e;
            }

            Log::warning('video_creative_concept_failed', [
                'article_id' => $article->id,
                'video_session_id' => $videoSessionId,
                'category' => $category,
                'exception' => $e::class,
                'violations' => $e->violations,
            ]);

            return $renderPlan;
        }

        // Warning KHONG mang noi dung field — chi code/path/so do, vi no vao log.
        if ($design->warnings !== []) {
            Log::warning('video_creative_concept_warnings', [
                'article_id' => $article->id,
                'video_session_id' => $videoSessionId,
                'category' => $category,
                'attempts' => $design->attempts,
                'warnings' => $design->warningsToArray(),
            ]);
        }

        if ($mode === 'observe') {
            Log::info('video_creative_concept_observed', [
                'article_id' => $article->id,
                'video_session_id' => $videoSessionId,
                'category' => $category,
                'attempts' => $design->attempts,
                'concept' => $design->concept->toArray(),
            ]);

            return $renderPlan;
        }

        $renderPlan['creative_concept'] = $design->concept->toArray();

        return $renderPlan;
    }
}
