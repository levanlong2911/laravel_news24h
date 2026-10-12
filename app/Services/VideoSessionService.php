<?php

namespace App\Services;

use App\Enums\PlanningStageName;
use App\Enums\VideoSessionStatus;
use App\Models\VideoRenderPlan;
use App\Models\VideoSession;
use App\Repositories\Interfaces\ArticleRepositoryInterface;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Repositories\Interfaces\VideoSessionRepositoryInterface;
use App\Repositories\Interfaces\VideoShotRepositoryInterface;
use App\Services\Admin\AdminService;
use App\Services\Video\InspirationStageRunner;
use App\Services\Video\PlanningStageStore;
use App\Video\Pipeline\PipelineAborted;
use App\Video\RenderPlan\RenderPlanMeta;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Vong doi session/shot + persistence. Approval gate (ADR v1.1):
 * shot: draft → approved/needs_revision/rejected → queued → rendered/failed.
 * session: draft → composing → reviewing → rendering → done/failed.
 * Khong co duong render nao bo qua duyet.
 *
 * Class nay KHONG chay pipeline AI — viec do o VideoRenderPlanService (tach
 * 2026-07-29). Cung mo hinh voi phia CMS: ArticlePipelineService chay pipeline,
 * caller lo persistence.
 */
class VideoSessionService
{
    /** Script biên dịch prompt — chạy vài giây, $0 (§18.25). */
    public const COMPOSE_SCRIPT = 'session_runner.py';

    /** Script render — chạy 6-18 phút, TỐN TIỀN THẬT (§18.25). */
    public const RENDER_SCRIPT = 'render_queued_shots.py';

    /** Script ghép final — chỉ FFmpeg cục bộ, không gọi vendor, $0 (§18.25). */
    public const FINAL_COMPOSE_SCRIPT = 'compose_final.py';

    public function __construct(
        private AdminService $adminService,
        private ArticleRepositoryInterface $articleRepository,
        private VideoProjectRepositoryInterface $videoProjectRepository,
        private VideoSessionRepositoryInterface $sessionRepository,
        private VideoShotRepositoryInterface $shotRepository,
        private VideoRenderPlanService $renderPlanService,
        private PythonRunner $pythonRunner,
        private InspirationStageRunner $inspirationRunner,
        private PlanningStageStore $stageStore = new PlanningStageStore,
    ) {}

    /**
     * Chặng Haiku. Claim RIÊNG nên chạy lại chỉ tốn tiền một lần: lần thứ hai
     * trả `already_succeeded` và brief được dựng lại từ `output_json`.
     *
     * @return array{0: bool, 1: string} [$ranOrSkipped, $reason]
     *                                   reason: session_not_found|admin_not_found|claimed_by_other|already_succeeded|ok|failed
     */
    public function runInspirationStage(string $sessionCode): array
    {
        [$session, $admin, $article, $err] = $this->stageContext($sessionCode, 'inspiration-stage');

        if ($err !== null) {
            return [false, $err];
        }

        [$stage, $token, $reason] = $this->stageStore->claim(
            $session->id,
            (int) $session->planning_revision,
            PlanningStageName::INSPIRATION,
            ['article_id' => $article->id, 'title' => $article->title, 'content' => (string) $article->content],
        );

        if ($token === null) {
            return [$reason === 'already_succeeded', $reason];
        }

        Auth::loginUsingId($admin->id);

        [$output] = $this->inspirationRunner->callInHaiku($stage, $token, $article, $session->id);

        return [$output !== null, $output !== null ? 'ok' : 'failed'];
    }

    /**
     * Chặng Sonnet. Gọi Inspiration trước — nếu chặng đó đã xong thì brief dựng
     * lại từ DB và Haiku KHÔNG chạy lần nữa.
     *
     * @return array{0: bool, 1: string} [$ranOrSkipped, $reason]
     *                                   reason: session_not_found|admin_not_found|claimed_by_other|already_succeeded|missing_inspiration|ok|failed
     */
    public function runConceptStage(string $sessionCode): array
    {
        [$inspirationOk, $inspirationReason] = $this->runInspirationStage($sessionCode);

        if (! $inspirationOk) {
            return [false, $inspirationReason];
        }

        [$session, $admin, $article, $err] = $this->stageContext($sessionCode, 'concept-stage');

        if ($err !== null) {
            return [false, $err];
        }

        $stored = $this->stageStore->outputOf(
            $session->id, (int) $session->planning_revision, PlanningStageName::INSPIRATION,
        );

        if (! is_array($stored) || $stored === []) {
            Log::error('concept-stage: chua co inspiration da succeeded', ['code' => $sessionCode]);

            return [false, 'missing_inspiration'];
        }

        [$stage, $token, $reason] = $this->stageStore->claim(
            $session->id,
            (int) $session->planning_revision,
            PlanningStageName::CONCEPT,
            ['article_id' => $article->id, 'inspiration_sha256' => hash('sha256', json_encode($stored))],
        );

        if ($token === null) {
            return [$reason === 'already_succeeded', $reason];
        }

        Auth::loginUsingId($admin->id);

        try {
            $brief = $this->renderPlanService->briefFromStorage($stored);
            $design = $this->renderPlanService->renderConceptStage($article, $brief, $session->id);
        } catch (\Throwable $e) {
            Log::error('concept-stage: that bai', ['code' => $sessionCode, 'exception' => $e]);
            $this->stageStore->finishFailed($stage->id, $token, $e->getMessage());

            return [false, 'failed'];
        }

        $this->stageStore->finishSucceeded(
            $stage->id,
            $token,
            $design->rawResponse,
            $design->concept->toArray(),
            [
                'model' => 'canonical',
                'provider_model' => (string) config('canonical_concept.anthropic.model'),
                'instruction_version' => (string) config('canonical_concept.prompt_version'),
            ],
        );

        return [true, 'ok'];
    }

    /**
     * Ba câu kiểm mà cả hai chặng đều cần. Gộp lại vì chúng phải giống nhau —
     * lệch một điều kiện là hai chặng có hai luật khác nhau về "được chạy không".
     *
     * @return array{0: ?VideoSession, 1: mixed, 2: mixed, 3: ?string}
     */
    private function stageContext(string $sessionCode, string $logTag): array
    {
        $session = VideoSession::query()->where('code', $sessionCode)->first();

        if ($session === null) {
            Log::error($logTag.': khong tim thay session', ['code' => $sessionCode]);

            return [null, null, null, 'session_not_found'];
        }

        $admin = $session->requested_by_admin_id === null
            ? null
            : $this->adminService->getByIdAcc($session->requested_by_admin_id);

        if (! $admin) {
            Log::error($logTag.': admin khong ton tai, tu choi goi Claude', ['code' => $sessionCode]);

            return [null, null, null, 'admin_not_found'];
        }

        return [$session, $admin, $this->articleRepository->show($session->article_id), null];
    }

    /** Câu lệnh chạy tay tương đương, để hiện lên màn hình khi bắn hỏng. */
    public function manualCommandFor(string $script, string $sessionCode): string
    {
        return $this->pythonRunner->manualCommand($script, $sessionCode);
    }

    public function listAll(): iterable
    {
        return $this->sessionRepository->listAllWithProjectAndShotCount();
    }

    /**
     * @return array{0: bool, 1: string} [$ok, $reason]
     *                                   reason: session_not_found|admin_not_found|claimed_by_other|already_succeeded|missing_concept|ok|failed
     */
    public function runFinalizeStage(string $sessionCode): array
    {
        $session = VideoSession::query()->where('code', $sessionCode)->first();

        if ($session === null) {
            Log::error('finalize-stage: khong tim thay session', ['code' => $sessionCode]);

            return [false, 'session_not_found'];
        }

        $admin = $session->requested_by_admin_id === null
            ? null
            : $this->adminService->getByIdAcc($session->requested_by_admin_id);

        if (! $admin) {
            Log::error('finalize-stage: admin khong ton tai, tu choi goi Claude', ['code' => $sessionCode]);

            return [false, 'admin_not_found'];
        }

        $revision = (int) $session->planning_revision;
        $conceptRaw = $this->stageStore->rawResponseOf($session->id, $revision, PlanningStageName::CONCEPT);

        if ($conceptRaw === null) {
            Log::error('finalize-stage: chua co concept da succeeded', ['code' => $sessionCode]);

            return [false, 'missing_concept'];
        }

        $article = $this->articleRepository->show($session->article_id);

        [$stage, $token, $reason] = $this->stageStore->claim(
            $session->id, $revision, PlanningStageName::FINALIZE, ['concept_sha256' => hash('sha256', $conceptRaw)],
        );

        if ($token === null) {
            return [$reason === 'already_succeeded', $reason];
        }

        Auth::loginUsingId($admin->id);

        $meta = new RenderPlanMeta(
            Str::uuid()->toString(),
            $article->id,
            $article->title,
            'en',
            now()->toIso8601String(),
            (string) ($article->category?->slug ?? ''),
        );

        try {
            $renderPlan = $this->renderPlanService->runFinalizeStage($article, $conceptRaw, $meta, $session->id);
        } catch (\Throwable $e) {
            Log::error('finalize-stage: that bai', ['code' => $sessionCode, 'exception' => $e]);
            $this->stageStore->finishFailed($stage->id, $token, $e->getMessage());
            $session->update(['status' => VideoSessionStatus::FAILED->value, 'error_message' => $e->getMessage()]);

            return [false, 'failed'];
        }

        $this->stageStore->finishSucceeded($stage->id, $token, '', $renderPlan);
        $this->storeRenderPlan($session, $renderPlan);
        $session->update(['status' => VideoSessionStatus::COMPOSING->value]);

        if (! $this->pythonRunner->spawn(self::COMPOSE_SCRIPT, $sessionCode)) {
            Log::warning('finalize-stage: khong ban duoc session_runner.py, can chay tay', [
                'code' => $sessionCode,
                'manual' => $this->manualCommandFor(self::COMPOSE_SCRIPT, $sessionCode),
            ]);
        }

        return [true, 'ok'];
    }

    /**
     * CLAIM KHÔNG TỰ HẾT HẠN. Một cú Claude đơn lẻ gặp 529 Overloaded có thể mất
     * tới ~10 phút riêng nó (MAX_RETRIES × timeout + backoff), mà pipeline gọi
     * 11+ lần — hạn theo thời gian sẽ để worker cũ còn sống trong khi worker mới
     * tưởng nó đã chết, rồi cả hai cùng gọi Claude. Mở lại claim CHỈ qua
     * `video:reset-planning-claim`.
     *
     * @return bool `true` = da luu renderplan_json thanh cong (co the van
     *              khong ban duoc Python — xem log canh bao rieng), `false` =
     *              pipeline that bai HOAN TOAN hoac ket qua bi bo vi mat quyen
     *              so huu claim.
     */
    public function runVideoPlanningPipeline(string $sessionCode): bool
    {
        $this->debugPlanningStep('claim_start', ['session_code' => $sessionCode]);
        $claimToken = (string) Str::uuid();

        $claim = DB::transaction(function () use ($sessionCode, $claimToken) {
            $session = VideoSession::query()->where('code', $sessionCode)->lockForUpdate()->first();

            if (! $session) {
                return ['session' => null, 'claimed' => false];
            }

            if ($session->status !== VideoSessionStatus::PLANNING->value || $session->planning_claimed_at !== null) {
                return ['session' => $session, 'claimed' => false];
            }

            // KHÔNG đổi status sang `composing` ở đây: Python poll
            // `GET /video-sessions/composing` và sẽ nhận một session chưa có
            // renderplan_json.
            $session->update(['planning_claimed_at' => now(), 'planning_claim_token' => $claimToken]);

            return ['session' => $session, 'claimed' => true];
        });

        $session = $claim['session'];

        $this->debugPlanningStep('claim_result', [
            'session_code' => $sessionCode,
            'session_found' => $session !== null,
            'claimed' => $claim['claimed'],
            'status' => $session?->status,
        ]);

        if (! $session) {
            Log::error('video:build-plan: khong tim thay session', ['code' => $sessionCode]);

            return false;
        }

        if (! $claim['claimed']) {
            // Hai kha nang, ca hai deu KHONG phai loi cua LAN GOI NAY: (a) da
            // xu ly xong roi (vd chay lai tay tren session da xong), hoac (b)
            // mot tien trinh khac dang giu claim (chua reset thu cong) — dung
            // lai, KHONG giam len.
            Log::info('video:build-plan: session khong con planning hoac da co claim, bo qua', [
                'code' => $sessionCode, 'status' => $session->status,
                'claimed_at' => $session->planning_claimed_at,
            ]);

            return true;
        }

        // Admin::find(null) tu nhien tra null (WHERE id = NULL khong khop
        // gi) — mot duong DUY NHAT xu ly ca "chua tung co admin" lan "admin
        // da bi xoa", khong can nhanh rieng cho truong hop dau.
        $admin = $session->requested_by_admin_id === null
            ? null
            : $this->adminService->getByIdAcc($session->requested_by_admin_id);

        if (! $admin) {
            $this->finishClaimedPlanning($sessionCode, $claimToken, [
                'status' => VideoSessionStatus::FAILED->value,
                'error_message' => 'Admin da kich hoat khong con ton tai — dung truoc khi goi Claude.',
            ]);
            Log::error('video:build-plan: admin khong ton tai, tu choi goi Claude', [
                'code' => $sessionCode, 'admin_id' => $session->requested_by_admin_id,
            ]);

            return false;
        }

        // Guard 'web' mac dinh (config/auth.php: provider 'users' -> model
        // Admin::class) — DUNG guard ma auth()->user() o recordUsage() doc.
        Auth::loginUsingId($admin->id);

        try {
            // Cac GUARD trong VideoPlanningPipeline::plan() dung TRUOC tung cu
            // goi ton tien — bai rong thi dung khi CHUA ton dong nao.
            $article = $this->articleRepository->show($session->article_id);
            $this->debugPlanningStep('render_plan_build_start', [
                'session_code' => $sessionCode,
                'article_id' => $session->article_id,
            ]);
            $renderPlan = $this->renderPlanService->build($article, $session->id);
            $this->debugPlanningStep('render_plan_build_finished', [
                'session_code' => $sessionCode,
                'scene_count' => count($renderPlan['scenes'] ?? []),
            ]);

            // Luu ke hoach SAU khi gianh duoc quyen ghi, khong phai truoc. Cau
            // UPDATE co dieu kien trong finishClaimedPlanning() la cho DUY NHAT
            // kiem tra claim con la cua minh; ghi ke hoach truoc no la ghi de
            // ket qua cua worker da chiem lai session.
            $wroteResult = $this->finishClaimedPlanning($sessionCode, $claimToken, [
                'status' => VideoSessionStatus::COMPOSING->value,
            ]);

            if ($wroteResult) {
                $this->storeRenderPlan($session, $renderPlan);
            }

            if (! $wroteResult) {
                // Claim da bi reset + chiem lai boi tien trinh khac trong luc
                // build() dang chay — bo ket qua nay, KHONG spawn Python cho
                // mot session ma quyen so huu khong con la cua minh.
                Log::warning('video:build-plan: mat quyen so huu claim luc ghi ket qua, bo renderplan da tinh', [
                    'code' => $sessionCode,
                ]);

                return false;
            }

            $this->debugPlanningStep('render_plan_saved', [
                'session_code' => $sessionCode,
                'status' => VideoSessionStatus::COMPOSING->value,
            ]);

            // BAN tien trinh Python o nen (§18.25), SAU khi da luu — script se
            // goi nguoc lai API doc session nay.
            $spawned = $this->pythonRunner->spawn(self::COMPOSE_SCRIPT, $sessionCode);
            $this->debugPlanningStep('python_compose_dispatched', [
                'session_code' => $sessionCode,
                'spawned' => $spawned,
            ]);

            if (! $spawned) {
                Log::warning('video:build-plan: khong ban duoc session_runner.py, can chay tay', [
                    'code' => $sessionCode,
                    'manual' => $this->manualCommandFor(self::COMPOSE_SCRIPT, $sessionCode),
                ]);
            }

            return true;
        } catch (\Throwable $e) {
            // 'exception' => $e chu KHONG phai $e->getMessage(): Laravel ghi ca
            // class, file:line va stack trace. Chi log message thi khong biet
            // hong o Extractor, Producer, Director hay applyCreationArc.
            Log::error('video:build-plan: pipeline that bai', [
                'code' => $sessionCode,
                'article_id' => $session->article_id,
                'stage' => $e instanceof PipelineAborted ? $e->stage : null,
                'spent_money' => $e instanceof PipelineAborted ? $e->spentMoney : true,
                'exception' => $e,
            ]);

            $wroteFailure = $this->finishClaimedPlanning($sessionCode, $claimToken, [
                'status' => VideoSessionStatus::FAILED->value,
                'error_message' => $e->getMessage(),
            ]);

            if (! $wroteFailure) {
                // Cung mat quyen so huu nhu nhanh thanh cong o tren — chi khac
                // o cho pipeline THAT BAI thay vi thanh cong. Van dang KHONG
                // ghi de (an toan), nhung thieu dong log nay thi khong ai biet
                // ca hai lan ghi (thanh cong lan truoc, that bai lan nay) deu
                // bi bo vi claim da doi chu.
                Log::warning('video:build-plan: mat quyen so huu claim luc ghi loi, bo trang thai failed da tinh', [
                    'code' => $sessionCode,
                ]);
            }

            return false;
        }
    }

    /**
     * Ghi ket qua CO DIEU KIEN dung token — chi worker con giu claim moi ghi
     * duoc. Xoa ca hai cot claim trong CUNG luot ghi (khong phai lan rieng):
     * ket qua da chot thi claim khong con y nghia gi nua.
     *
     * Kiểm lúc claim chỉ chứng minh quyền tại thời điểm bắt đầu — session có thể
     * bị reset rồi worker khác chiếm lại trong lúc Claude đang chạy.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function finishClaimedPlanning(string $sessionCode, string $claimToken, array $attributes): bool
    {
        $updated = VideoSession::query()
            ->where('code', $sessionCode)
            ->where('planning_claim_token', $claimToken)
            ->update($attributes + ['planning_claimed_at' => null, 'planning_claim_token' => null]);

        return $updated > 0;
    }

    /** @param array<string, mixed> $context */
    private function debugPlanningStep(string $step, array $context): void
    {
        if (! app()->environment('local') || ! (bool) config('app.debug')) {
            return;
        }

        dump(['planning_step' => $step] + $context);
    }

    public function resetPlanningClaim(string $sessionCode): bool
    {
        return VideoSession::query()
            ->where('code', $sessionCode)
            ->where('status', VideoSessionStatus::PLANNING->value)
            ->whereNotNull('planning_claimed_at')
            ->whereNotNull('planning_claim_token')
            ->update(['planning_claimed_at' => null, 'planning_claim_token' => null]) > 0;
    }

    public function reclaimExpiredShotLeases(): int
    {
        return $this->shotRepository->reclaimExpiredLeases();
    }

    // Moi lan luu la MOT BAN GHI MOI chu khong de len ban truoc: cot
    // renderplan_json cu bi ghi de nen ke hoach truoc do bien mat khong dau vet.
    // `revision` la khoa sap xep, khong dung thoi diem ghi.
    private function storeRenderPlan(VideoSession $session, ?array $plan, ?int $revision = null): ?VideoRenderPlan
    {
        if ($plan === null) {
            return null;
        }

        $revision ??= ((int) $session->renderPlans()->max('revision')) + 1;
        $encoded = json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $stored = VideoRenderPlan::updateOrCreate(
            ['session_id' => $session->id, 'revision' => $revision],
            [
                'schema_version' => (string) ($plan['schema_version'] ?? ''),
                'builder_version' => (string) ($plan['builder_version'] ?? ''),
                'status' => 'active',
                'scene_count' => count($plan['scenes'] ?? []),
                'aspect_ratio' => $plan['aspect_ratio'] ?? null,
                'width' => $plan['width'] ?? null,
                'height' => $plan['height'] ?? null,
                'target_duration_ms' => $plan['target_duration_ms'] ?? null,
                'plan_json' => $plan,
                'plan_hash' => hash('sha256', $encoded === false ? '' : $encoded),
            ],
        );

        $session->setRelation('latestRenderPlan', $stored);

        return $stored;
    }
}
