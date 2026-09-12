<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Article;
use App\Services\Video\CreativeProfileResolver;
use App\Services\Video\ExtractionArtifactRecorder;
use App\Video\Article\RawArticle;
use App\Video\Concept\BuildCanonicalConcept;
use App\Video\Concept\Canonical\CanonicalDesignSpec;
use App\Video\Concept\ConceptDesignResult;
use App\Video\Concept\ConceptInput;
use App\Video\Concept\Exceptions\CanonicalValidationException;
use App\Video\Concept\SonnetScreenConceptDesigner;
use App\Video\Concept\Viewpoint;
use App\Video\Profiles\CategoryCreativeProfileResolver as CanonicalProfileResolver;
use App\Video\Inspiration\CategoryCreativeProfile;
use App\Video\Inspiration\ClaudeInspirationAnalyst;
use App\Video\Inspiration\InspirationBrief;
use App\Video\Inspiration\InspirationBriefParser;
use App\Video\Inspiration\InspirationResult;
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

class VideoRenderPlanService
{
    public const USAGE_ACTION = 'video_renderplan';

    public const STAGE_USAGE_ACTION = 'video_concept_stage';

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
        private ExtractionArtifactRecorder $artifactRecorder = new ExtractionArtifactRecorder,
        private CreativeProfileResolver $creativeProfileResolver = new CreativeProfileResolver,
    ) {}

    /**
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
    private function recordUsage(
        Article $article,
        ?string $videoSessionId = null,
        string $action = self::USAGE_ACTION,
    ): void {
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
            $action,
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
        $approvalGate = new CostCeilingGate(config('video.llm_cost_ceiling_usd'));

        return new GatedLlmClient($this->claudeWriterAdapter, $approvalGate);
    }

    /**
     * @param  array<string, mixed>  $renderPlan
     * @return array<string, mixed>
     */
    private function applyCreationArc(array $renderPlan, Article $article): array
    {
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
    ): array {
        $profile = $this->profileOrFail($category);

        $brief = $this->inspirationStage($rawArticle, $profile, $llm)->brief;
        $design = $this->conceptStage($brief, $category);

        return $this->finalizeStage($design->concept, $meta, $llm, $profile);
    }

    /**
     * Chặng Haiku, tách riêng khỏi Sonnet để mỗi chặng có claim và bản lưu của
     * mình. Sonnet hỏng thì chạy lại KHÔNG phải trả tiền Haiku lần nữa.
     */
    public function renderInspirationStage(Article $article, ?string $videoSessionId = null): InspirationResult
    {
        $profile = $this->profileOrFail((string) ($article->category?->slug ?? ''));

        $accumulator = new CostAccumulatingLlmClient($this->spendingLlmClient());
        $this->lastRun = $accumulator;

        $rawArticle = new RawArticle($article->id, $article->title, (string) $article->content);

        try {
            return $this->inspirationStage($rawArticle, $profile, $accumulator);
        } finally {
            $this->recordUsage($article, $videoSessionId, self::STAGE_USAGE_ACTION);
        }
    }

    /** Chặng Sonnet. Nhận brief đã có sẵn — không tự gọi Haiku. */
    public function renderConceptStage(
        Article $article,
        InspirationBrief $brief,
        ?string $videoSessionId = null,
    ): ConceptDesignResult {
        $category = (string) ($article->category?->slug ?? '');

        // Van goi profileOrFail: no la cai chan category chua khai profile, va
        // duong canonical phai tu choi o dung cho do chu khong di tiep bang
        // profile fallback.
        $this->profileOrFail($category);

        $accumulator = new CostAccumulatingLlmClient($this->spendingLlmClient());
        $this->lastRun = $accumulator;

        try {
            return $this->conceptStage($brief, $category);
        } finally {
            $this->recordUsage($article, $videoSessionId, self::STAGE_USAGE_ACTION);
        }
    }

    /**
     * Hình dạng đem lưu. PHẢI bỏ `uncovered_aspects`: InspirationBriefParser từ
     * chối khoá lạ ở gốc, mà khoá đó là giá trị DẪN XUẤT — `uncoveredAspects()`
     * tính lại được. Đã đo: bỏ nó thì vòng đi-về dựng lại brief GIỐNG HỆT.
     *
     * @return array<string, mixed>
     */
    /**
     * Concept cho man hinh /admin/video-projects/{id}/anchor.
     *
     * Chang nay chi goi Sonnet va luu creative_concept shape ma Python
     * image_prompt compiler dang doc. Nut Compile Prompt moi goi Python.
     *
     * @return array<string, mixed>
     */
    public function renderScreenConceptStage(
        Article $article,
        InspirationBrief $brief,
        ?string $videoSessionId = null,
    ): array {
        $category = (string) ($article->category?->slug ?? '');
        $profile = $this->profileOrFail($category);

        $accumulator = new CostAccumulatingLlmClient($this->spendingLlmClient());
        $this->lastRun = $accumulator;

        try {
            return (new SonnetScreenConceptDesigner)->design(
                llm: $accumulator,
                brief: $brief,
                profile: $profile,
                objectType: $category,
            );
        } finally {
            $this->recordUsage($article, $videoSessionId, self::STAGE_USAGE_ACTION);
        }
    }

    /** @return array{call_count:int,tokens_in:int,tokens_out:int,cost_usd:float,latency_ms:int,provider_model:string,thinking_tokens:int}|null */
    public function lastUsage(): ?array
    {
        return $this->lastRun?->totals();
    }

    public function briefForStorage(InspirationBrief $brief, Article $article): array
    {
        $data = $brief->toArray($this->profileOrFail((string) ($article->category?->slug ?? '')));
        unset($data['uncovered_aspects']);

        return $data;
    }

    /** @param  array<string, mixed>  $stored */
    public function briefFromStorage(array $stored): InspirationBrief
    {
        return (new InspirationBriefParser)->parse(json_encode($stored, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function runFinalizeStage(
        Article $article,
        string $conceptRaw,
        RenderPlanMeta $meta,
        ?string $videoSessionId = null,
    ): array {
        $profile = $this->profileOrFail((string) ($article->category?->slug ?? ''));
        // Ban da luu ROI la ban da chuan hoa: normalizer chay o
        // CanonicalConceptProcessor truoc khi dong bang, khong chay lai o day.
        $concept = CanonicalDesignSpec::fromArray(
            (array) json_decode($conceptRaw, true, 512, JSON_THROW_ON_ERROR),
        );

        $accumulator = new CostAccumulatingLlmClient($this->spendingLlmClient());
        $this->lastRun = $accumulator;

        try {
            return $this->finalizeStage($concept, $meta, $accumulator, $profile);
        } finally {
            $this->recordUsage($article, $videoSessionId, self::STAGE_USAGE_ACTION);
        }
    }

    private function profileOrFail(string $category): CategoryCreativeProfile
    {
        $profile = $this->creativeProfileResolver->resolve($category);

        if ($profile === null) {
            throw new \InvalidArgumentException("No creative profile is configured for category: {$category}");
        }

        return $profile;
    }

    private function inspirationStage(
        RawArticle $rawArticle,
        CategoryCreativeProfile $profile,
        LlmClient $llm,
    ): InspirationResult {
        return (new ClaudeInspirationAnalyst($llm))->analyze($rawArticle, $profile);
    }

    /**
     * BuildCanonicalConcept lo tron chang: designer -> validate -> repair DUNG
     * MOT lan -> normalize -> re-validate -> hash -> freeze.
     *
     * KHONG nhan $llm: designer canonical noi chuyen voi Anthropic qua client
     * structured-output rieng, nam ngoai CostAccumulatingLlmClient. Chi phi
     * chang nay doc o bang attempt cua canonical, khong doc o lastUsage().
     *
     * revision = 1 vi seam nay khong giu bo dem revision — duong co revision
     * that la CanonicalConceptExecutionService ben VideoProjectService.
     */
    private function conceptStage(
        InspirationBrief $brief,
        string $objectType,
    ): ConceptDesignResult {
        $input = new ConceptInput(
            objectType: $objectType,
            inspiration: $brief,
            profile: app(CanonicalProfileResolver::class)->resolve($objectType),
        );

        $frozen = app(BuildCanonicalConcept::class)->build($input, 1);

        return new ConceptDesignResult(
            $frozen->spec,
            $frozen->revision,
            $frozen->hash,
            $frozen->canonicalJson,
        );
    }

    /** @return array<string, mixed> */
    private function finalizeStage(
        CanonicalDesignSpec $concept,
        RenderPlanMeta $meta,
        LlmClient $llm,
        CategoryCreativeProfile $profile,
    ): array {
        $phases = (new ClaudeCreativeArcPlanner($llm))->plan($concept, $profile);

        return (new CreativeRenderPlanBuilder)->build($meta, $concept, $phases);
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
            $brief = (new ClaudeInspirationAnalyst($llm))->analyze($rawArticle, $profile)->brief;
            $design = $this->conceptStage($brief, $category);
        } catch (CanonicalValidationException|InvalidInspirationBrief $e) {
            if ($mode !== 'observe') {
                throw $e;
            }

            Log::warning('video_creative_concept_failed', [
                'article_id' => $article->id,
                'video_session_id' => $videoSessionId,
                'category' => $category,
                'exception' => $e::class,
                // Hai nhanh vi hai hinh dang loi khac nhau, KHONG gop bang
                // getMessage(): errorPayload() giu duoc path cua tung loi, do
                // moi la thu doc duoc khi mo log ra.
                'violations' => $e instanceof CanonicalValidationException
                    ? $e->errorPayload()
                    : $e->violations,
            ]);

            return $renderPlan;
        }

        if ($mode === 'observe') {
            Log::info('video_creative_concept_observed', [
                'article_id' => $article->id,
                'video_session_id' => $videoSessionId,
                'category' => $category,
                'revision' => $design->revision,
                'hash' => $design->hash,
                'concept' => $design->concept->toArray(),
            ]);

            return $renderPlan;
        }

        $renderPlan['creative_concept'] = $design->concept->toArray();

        return $renderPlan;
    }
}
