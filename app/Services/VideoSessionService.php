<?php

namespace App\Services;

use App\Enums\PlanningStageName;
use App\Enums\VideoSessionStatus;
use App\Enums\VideoShotStatus;
use App\Jobs\BuildConceptStageJob;
use App\Models\Article;
use App\Models\VideoCostEntry;
use App\Models\VideoFinal;
use App\Models\VideoFinalRender;
use App\Models\VideoRender;
use App\Models\VideoRenderPlan;
use App\Models\VideoSession;
use App\Models\VideoShot;
use App\Repositories\Interfaces\ArticleRepositoryInterface;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Repositories\Interfaces\VideoSessionRepositoryInterface;
use App\Repositories\Interfaces\VideoShotRepositoryInterface;
use App\Services\Admin\AdminService;
use App\Services\Video\InspirationStageRunner;
use App\Services\Video\PlanningStageStore;
use App\Video\Concept\ClaudeConceptDesigner;
use App\Video\Concept\Viewpoint;
use App\Video\Pipeline\PipelineAborted;
use App\Video\RenderPlan\RenderPlanAssembler;
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
            ['model' => 'sonnet', 'instruction_version' => ClaudeConceptDesigner::INSTRUCTION_VERSION],
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

    /** @param  list<string>  $args */
    public function manualArtisanCommandFor(string $command, array $args): string
    {
        return $this->pythonRunner->manualArtisanCommand($command, $args);
    }

    public function listAll(): iterable
    {
        return $this->sessionRepository->listAllWithProjectAndShotCount();
    }

    public function findForShow(string $id)
    {
        return $this->sessionRepository->findWithProjectAndShots($id);
    }

    /**
     * @return array{0: ?VideoSession, 1: bool, 2: string} [$session, $queued, $reason]
     *                                                     reason: admin_not_found|already_in_progress|queue_failed|ok
     */
    public function startVideoPlanning(string $articleId, string $adminId): array
    {
        if (! $this->adminExists($adminId)) {
            return [null, false, 'admin_not_found'];
        }

        [$session, $created] = $this->findOrCreatePlanningSession($articleId, $adminId);
        if (! $created) {
            return [$session, false, 'already_in_progress'];
        }

        $queued = $this->dispatchPlanningJob($session);

        return [$session, $queued, $queued ? 'ok' : 'queue_failed'];
    }

    private function adminExists(string $adminId): bool
    {
        return $this->adminService->getByIdAcc($adminId) !== null;
    }

    /** @return array{0: VideoSession, 1: bool} [$session, $created] */
    private function findOrCreatePlanningSession(string $articleId, string $adminId): array
    {
        /** @var Article $article */
        $article = $this->articleRepository->show($articleId);

        return DB::transaction(
            fn () => $this->findOrCreateLockedPlanningSession($article, $adminId),
        );
    }

    /** @return array{0: VideoSession, 1: bool} [$session, $created] */
    private function findOrCreateLockedPlanningSession(Article $article, string $adminId): array
    {
        $this->lockArticleForPlanning($article);
        $existingSession = $this->findPlanningSession($article->id);

        if ($existingSession !== null) {
            return [$existingSession, false];
        }

        return [$this->createPlanningSession($article, $adminId), true];
    }

    private function lockArticleForPlanning(Article $article): void
    {
        // Hai request đồng thời không được cùng thấy "chưa có session".
        $article->newQuery()
            ->whereKey($article->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function findPlanningSession(string $articleId): ?VideoSession
    {
        return VideoSession::query()
            ->where('article_id', $articleId)
            ->whereIn('status', VideoSessionStatus::nonTerminalValues())
            ->latest()
            ->first();
    }

    private function createPlanningSession(Article $article, string $adminId): VideoSession
    {
        $project = $this->videoProjectRepository->findOrCreateByArticleId($article);

        return $this->sessionRepository->create([
            'project_id' => $project->id,
            'article_id' => $article->id,
            'requested_by_admin_id' => $adminId,
            'code' => 'art_'.substr($article->id, 0, 8).'_'.now()->format('ymd_His').'_'.Str::random(4),
            'status' => VideoSessionStatus::PLANNING->value,
        ]);
    }

    private function dispatchPlanningJob(VideoSession $session): bool
    {
        if ((bool) config('video.planning_queue.sync')) {
            return $this->runPlanningStagesInline($session->code);
        }

        try {
            BuildConceptStageJob::dispatch($session->code);

            return true;
        } catch (\Throwable $e) {
            Log::error('video:build-plan: khong dua duoc job vao queue', [
                'code' => $session->code,
                'exception' => $e,
            ]);

            return false;
        }
    }

    private function runPlanningStagesInline(string $sessionCode): bool
    {
        [$ok, $reason] = $this->runConceptStage($sessionCode);
        Log::info('planning-sync: chang concept', ['code' => $sessionCode, 'ok' => $ok, 'reason' => $reason]);

        if (! $ok) {
            return false;
        }

        [$ok, $reason] = $this->runFinalizeStage($sessionCode);
        Log::info('planning-sync: chang finalize', ['code' => $sessionCode, 'ok' => $ok, 'reason' => $reason]);

        return $ok;
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

    // Duyệt / cần sửa / từ chối MỘT shot
    public function updateShotStatus(string $shotId, string $action, ?string $note): bool
    {
        $attributes = match ($action) {
            'approve' => ['status' => VideoShotStatus::APPROVED->value, 'approved_at' => now(), 'review_note' => null],
            'revise' => ['status' => VideoShotStatus::NEEDS_REVISION->value, 'review_note' => $note ?? ''],
            'reject' => ['status' => VideoShotStatus::REJECTED->value, 'review_note' => $note ?? ''],
            default => null,
        };

        return $attributes !== null && $this->shotRepository->updateReviewStatus($shotId, $attributes);
    }

    // Approve Selected (checkbox)
    public function approveSelectedShots(string $sessionId, array $shotIds): void
    {
        $this->shotRepository->approveByIds($sessionId, $shotIds);
    }

    /**
     * 🎬 Render — CHỈ shot approved mới vào queue.
     *
     * Trả về `[so_shot_vao_queue, da_ban_tien_trinh]`. Số shot cần cho câu
     * thông báo (bấm khi chưa duyệt gì thì đừng nói "đang render"), còn cờ bắn
     * cần để hiện đường lui chạy tay.
     *
     * @return array{0: int, 1: bool}
     */
    public function queueApproved(string $sessionId): array
    {
        $queued = $this->shotRepository->queueApprovedForSession($sessionId);

        // KHONG doi trang thai khi khong co shot nao vao queue: session se ket
        // o `rendering` vinh vien trong khi khong co gi dang render, va nut bam
        // lai cung khong cuu duoc. Blade da khoa nut khi `approved = 0`, nhung
        // day la chot thu hai — request POST van gui tay duoc.
        if ($queued === 0) {
            return [0, false];
        }

        $this->sessionRepository->update($sessionId, ['status' => VideoSessionStatus::RENDERING->value]);

        // BAN SAU khi da doi trang thai: script Python doc `/video-shots/queued`,
        // nen shot phai o `queued` truoc khi no hoi.
        //
        // Dung `findWithProjectAndShots()` (co san) chi de lay `code` — no eager
        // load ca shots, hoi thua. Chap nhan: day la mot lan bam nut, khong phai
        // vong lap; them mot method repository chi de doc mot chuoi la abstraction
        // khong tra duoc tien thue (Rule 0).
        $session = $this->sessionRepository->findWithProjectAndShots($sessionId);

        return [$queued, $this->pythonRunner->spawn(self::RENDER_SCRIPT, $session->code)];
    }

    /**
     * THỬ RENDER — chạy đúng đường render thật nhưng KHÔNG gọi vendor, KHÔNG đổi
     * dữ liệu. Trả nguyên văn thứ script in ra.
     *
     * Vì sao cần: bấm Render là tiêu tiền và mất 6-18 phút, còn lỗi hay gặp nhất
     * lại tầm thường — shot chưa duyệt, thiếu ảnh neo, thiếu ảnh chứng minh trạng
     * thái. Trước bản này cách duy nhất để biết là bấm rồi ngồi đọc log.
     *
     * DANH SÁCH SHOT ĐI QUA FILE, KHÔNG QUA API. Đây là điểm chính của thiết kế:
     * lượt thử KHÔNG CÓ KÊNH nào để đổi dữ liệu, chứ không phải có kênh rồi hứa
     * không dùng. Nó cũng nhìn được shot ở MỌI trạng thái — `/video-shots/queued`
     * chỉ trả `queued`, mà thứ cần soi TRƯỚC khi duyệt lại đang là `draft`.
     *
     * @return array{0: bool, 1: string} [chạy được?, output]
     */
    public function previewRender(string $sessionId): array
    {
        $session = $this->sessionRepository->findWithProjectAndShots($sessionId);
        $shots = $this->shotRepository->findAllOfSessionWithSession($sessionId);

        $payload = [];
        foreach ($shots as $shot) {
            $payload[] = [
                'id' => $shot->id,
                'shot_code' => $shot->shot_code,
                'kind' => $shot->kind,
                'shot_type' => $shot->shot_type,
                'status' => $shot->status,
                'compiled_prompt' => $shot->compiled_prompt,
                'negative_prompt' => $shot->negative_prompt,
                'spec_json' => $shot->spec_json,
                'render_plan' => $shot->render_plan,
                'artifact_path' => $shot->artifact_path,
                'session' => ['code' => $session->code],
            ];
        }

        // Ghi vào thư mục log của runner — nơi ĐÃ có sẵn và đã được tạo/kiểm
        // quyền. Thêm một thư mục tạm thứ hai chỉ để chứa một file sống 1 giây
        // là abstraction không trả được tiền thuê.
        $dir = (string) config('video.runner.log_dir');
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return [false, "Khong tao duoc thu muc: {$dir}"];
        }

        $file = $dir.DIRECTORY_SEPARATOR.sprintf('preflight_%s_%s.json', $session->code, now()->format('His'));
        if (@file_put_contents($file, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
            return [false, "Khong ghi duoc file: {$file}"];
        }

        try {
            return $this->pythonRunner->runAndWait(self::RENDER_SCRIPT, ['--preflight-file='.$file]);
        } finally {
            @unlink($file);
        }
    }

    // POST /api/render-plans — Python đẩy session + shots (spec là INPUT, prompt là OUTPUT)
    public function storeFromPython(array $data): array
    {
        // Ham nay ghi N+2 lan (session + N shot + cap nhat tong chi phi). Khong
        // co transaction thi hong o shot thu 5/15 se de lai 4 shot da ghi va
        // cost_estimate_total khong bao gio duoc cap nhat — du lieu do dang.
        // O day boc transaction la DUNG (khac creatVideoById): toan bo la
        // ghi DB, khong co cu goi mang nao ben trong, chay vai mili giay.
        return DB::transaction(function () use ($data) {
            $existing = VideoSession::query()->where('code', $data['code'])->lockForUpdate()->first();

            if ($existing && $existing->status !== VideoSessionStatus::COMPOSING->value) {
                return [
                    'session_id' => $existing->id, 'shots' => 0,
                    'skipped' => true, 'status' => $existing->status,
                ];
            }

            $incomingKeys = collect($data['shots'])->map(fn (array $s) => $s['shot_code'].'|'.$s['kind'])->all();
            $incomingByKey = collect($data['shots'])->keyBy(fn (array $s) => $s['shot_code'].'|'.$s['kind']);
            $wouldOrphan = collect();

            if ($existing) {
                // lockForUpdate() TREN TOAN BO shot cua session — khoa session o
                // tren KHONG lam gi voi video_shots. Khong khoa o day thi
                // claimForSession() (UPDATE...LIMIT rieng, khong dung transaction
                // nay) co the claim mot shot dang queued NGAY GIUA luc ham nay dang
                // doc, roi bulk supersede ben duoi ghi de len ket qua claim do —
                // dung ca invariant "khong supersede claimed/rendering/rendered" ma
                // check ben duoi tuong da chan. Khoa o day serialize hai duong:
                // claimForSession() phai doi transaction nay commit xong moi UPDATE
                // duoc hang shot, va luc do shot co the da la superseded (khong con
                // queued) nen no tu nhien khong khop nua.
                $currentShots = VideoShot::query()
                    ->where('session_id', $existing->id)
                    ->lockForUpdate()
                    ->get([
                        'id', 'shot_code', 'kind', 'status',
                        'beat', 'compiled_prompt', 'negative_prompt', 'spec_json', 'render_plan',
                    ]);

                $wouldOrphan = $currentShots
                    ->reject(fn (VideoShot $shot) => in_array($shot->shot_code.'|'.$shot->kind, $incomingKeys, true))
                    ->reject(fn (VideoShot $shot) => $shot->status === VideoShotStatus::SUPERSEDED->value);

                $protected = $wouldOrphan->whereIn('status', [
                    VideoShotStatus::CLAIMED->value,
                    VideoShotStatus::RENDERING->value,
                    VideoShotStatus::RENDERED->value,
                    VideoShotStatus::FAILED->value,
                ]);

                // Shot con o payload moi nhung dang claimed/rendering/rendered — con
                // Python co the DA render/dang render dung noi dung CU. KHONG tinh
                // failed vao day: recompose sau mot lan fail thuong CHINH LA de sua
                // prompt hong, chan no o day can tro dung viec sua loi.
                $changedFields = [];
                $protectedChanged = $currentShots
                    ->filter(fn (VideoShot $shot) => in_array($shot->shot_code.'|'.$shot->kind, $incomingKeys, true))
                    ->filter(fn (VideoShot $shot) => in_array($shot->status, [
                        VideoShotStatus::CLAIMED->value,
                        VideoShotStatus::RENDERING->value,
                        VideoShotStatus::RENDERED->value,
                    ], true))
                    ->filter(function (VideoShot $shot) use ($incomingByKey, &$changedFields) {
                        $diff = $this->changedContentFields(
                            $shot,
                            $incomingByKey->get($shot->shot_code.'|'.$shot->kind),
                        );

                        if ($diff === []) {
                            return false;
                        }

                        $changedFields[$shot->id] = $diff;

                        return true;
                    });

                // Payload moi bo mot shot dang claimed/rendering/rendered/failed —
                // KHONG duoc am tham danh dau superseded (mat dau vet tien that/lease
                // dang giu), hoac doi noi dung mot shot dang claimed/rendering/rendered
                // (Python co the da/dang render dung ban CU). Tu choi CA GOI NAY,
                // khong tao/sua gi ca; nguoi van hanh phai tu quyet dinh (cho render
                // xong, huy render, hoac reset session tay) roi compose lai.
                if ($protected->isNotEmpty() || $protectedChanged->isNotEmpty()) {
                    return [
                        'session_id' => $existing->id, 'shots' => 0,
                        'skipped' => true, 'status' => $existing->status,
                        'error' => 'plan_revision_conflict',
                        'protected_shot_ids' => $protected->pluck('id')->values()->all(),
                        'protected_changed_shot_ids' => $protectedChanged->pluck('id')->values()->all(),
                        'changed_fields' => $changedFields,
                    ];
                }
            }

            $newRevision = ($existing->plan_revision ?? 0) + 1;

            $project = $this->videoProjectRepository->firstOrCreateByName($data['project'], $data['subject_id'] ?? null);
            $session = $this->sessionRepository->updateOrCreateByCode($data['code'], [
                'project_id' => $project->id,
                'status' => VideoSessionStatus::REVIEWING->value,
                'plan_revision' => $newRevision,
            ]);
            $this->storeRenderPlan($session, $data['renderplan'] ?? null, $newRevision);

            $total = 0;
            foreach ($data['shots'] as $s) {
                $total += (float) ($s['render_plan']['cost_estimate'] ?? 0);
                $this->shotRepository->updateOrCreateShot(
                    ['session_id' => $session->id, 'shot_code' => $s['shot_code'], 'kind' => $s['kind']],
                    [
                        'beat' => $s['beat'], 'shot_type' => $s['shot_type'] ?? 'establish',
                        'spec_json' => $s['spec'] ?? [], 'compiled_prompt' => $s['compiled_prompt'] ?? '',
                        'negative_prompt' => $s['negative_prompt'] ?? null, 'render_plan' => $s['render_plan'] ?? null,
                        'preview_path' => $s['preview_path'] ?? null,
                        'cost_estimate' => $s['render_plan']['cost_estimate'] ?? 0, 'status' => VideoShotStatus::DRAFT->value,
                        'plan_revision' => $newRevision,
                    ]
                );
            }
            $this->sessionRepository->update($session->id, ['cost_estimate_total' => $total]);

            if ($wouldOrphan->isNotEmpty()) {
                // whereIn(safeSupersedableValues) THAY VI != superseded — phong thu
                // chieu sau: ke ca neu logic tu choi o tren co lo hong nao do, cau
                // UPDATE nay van tu no khong bao gio dong duoc claimed/rendering/
                // rendered. Vi $currentShots da khoa (lockForUpdate) va khong ai
                // khac dung duoc vao giua chung, so dong doi phai khop CHINH XAC
                // $wouldOrphan->count() — lech la dau hieu logic sai o tren, dung
                // ghi mot phan roi coi nhu xong.
                $superseded = VideoShot::query()
                    ->where('session_id', $session->id)
                    ->where('plan_revision', '<', $newRevision)
                    ->whereIn('status', VideoShotStatus::safeSupersedableValues())
                    ->update(['status' => VideoShotStatus::SUPERSEDED->value]);

                if ($superseded !== $wouldOrphan->count()) {
                    throw new \RuntimeException(sprintf(
                        'storeFromPython: so shot superseded (%d) khong khop so du kien (%d) cho session %s',
                        $superseded, $wouldOrphan->count(), $data['code'],
                    ));
                }
            }

            return ['session_id' => $session->id, 'shots' => count($data['shots'])];
        });
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return list<string>
     */
    private function changedContentFields(VideoShot $shot, array $incoming): array
    {
        $candidates = [
            'compiled_prompt' => [$shot->compiled_prompt, $incoming['compiled_prompt'] ?? ''],
            'negative_prompt' => [$shot->negative_prompt, $incoming['negative_prompt'] ?? null],
            'beat' => [$shot->beat, $incoming['beat']],
            'spec_json' => [$shot->spec_json, $incoming['spec'] ?? []],
            'render_plan' => [$shot->render_plan, $incoming['render_plan'] ?? null],
        ];

        $changed = [];
        foreach ($candidates as $field => [$current, $new]) {
            $stripCostEstimate = $field === 'render_plan';
            $equal = is_array($current) || is_array($new)
                ? $this->normalizeForCompare($current, $stripCostEstimate) === $this->normalizeForCompare($new, $stripCostEstimate)
                : $current === $new;

            if (! $equal) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * `render_plan.cost_estimate` la UOC TINH, khong mo ta artifact that — bo
     * qua khi so sanh, CHI cho `render_plan` (`$stripCostEstimate`) — `spec_json`
     * la input that, mot key trung ten tinh co (neu co) van phai duoc so. Sort
     * key de qui de Python doi thu tu key trong dict (JSON object khong dam
     * bao thu tu) khong bi bao la "doi noi dung".
     */
    private function normalizeForCompare(mixed $value, bool $stripCostEstimate): string|false
    {
        if (is_array($value)) {
            if ($stripCostEstimate) {
                unset($value['cost_estimate']);
            }
            $this->recursiveKsort($value);
        }

        return json_encode($value);
    }

    private function recursiveKsort(array &$value): void
    {
        ksort($value);
        foreach ($value as &$v) {
            if (is_array($v)) {
                $this->recursiveKsort($v);
            }
        }
    }

    // GET /api/video-sessions/composing — runner poll de compose prompt
    public function listComposing(): iterable
    {
        // repairAfterDatabaseRoundTrip(): renderplan_json doc lai tu DB da mat
        // phan biet {} object rong / [] array rong (Eloquent array cast). Sua
        // truoc khi Python nhan, khong thi Python cung se thay du lieu sai shape
        // giong het test da bat duoc. Xem RenderPlanAssembler::repairAfterDatabaseRoundTrip().
        // Dung mang TUONG MINH chu khong gan nguoc vao model: renderplan_json gio
        // la thuoc tinh suy ra tu quan he, gan vao no thi accessor doc de len va
        // ban da sua bien mat khong bao loi. Bo khoa phai giong het truoc day.
        return $this->sessionRepository->findComposingWithProject()
            ->map(function (VideoSession $session) {
                $plan = $session->latestRenderPlan?->plan_json;

                return [
                    'id' => $session->id,
                    'project_id' => $session->project_id,
                    'code' => $session->code,
                    'renderplan_json' => is_array($plan)
                        ? RenderPlanAssembler::repairAfterDatabaseRoundTrip($plan)
                        : null,
                    'project' => $session->project === null ? null : [
                        'id' => $session->project->id,
                        'title' => $session->project->title,
                        'subject_id' => $session->project->subject_id,
                    ],
                ];
            });
    }

    /**
     * GET /api/video-sessions/{code}/design-cells
     *
     * Ô thiết kế đã duyệt của DỰ ÁN chứa session này. Python dựng bảng `proven`
     * từ đây thay vì từ shot của session — đó là toàn bộ điểm của bước này: ảnh
     * trạng thái thuộc con tàu, dùng lại được qua nhiều lượt dựng video.
     *
     * CHỈ trả ô `approved` VÀ có artifact. Ô còn là `candidate` chưa chứng minh
     * gì cả — cùng luật mà `resolve_motion_source()` đang áp cho shot `queued`.
     *
     * `source_cell_code` trả về MÃ chứ không phải id: Python không có bảng cell,
     * đưa uuid sang là bắt nó cầm một khoá nó không tra được.
     *
     * @return array<string, mixed>
     */
    public function listDesignCellsForSession(string $sessionCode): array
    {
        $session = VideoSession::query()->where('code', $sessionCode)->first();

        if ($session === null) {
            return ['session_code' => $sessionCode, 'project_id' => null, 'design_cells' => []];
        }

        $cells = DB::table('video_design_images as c')
            ->join('video_artifacts as a', 'a.id', '=', 'c.selected_artifact_id')
            ->leftJoin('video_design_images as src', 'src.id', '=', 'c.source_image_id')
            ->where('c.project_id', $session->project_id)
            ->where('c.status', 'approved')
            ->orderBy('c.image_code')
            ->get([
                'c.image_code', 'c.image_type', 'c.state', 'c.proves_state',
                'c.prompt_sha256', 'a.storage_path', 'a.width', 'a.height',
                'src.image_code as source_image_code',
            ]);

        return [
            'session_code' => $sessionCode,
            'project_id' => $session->project_id,
            'design_cells' => $cells->map(fn ($c) => [
                'cell_code' => $c->image_code,
                'cell_type' => $c->image_type,
                'state' => $c->state,
                'proves_state' => $c->proves_state,
                'source_cell_code' => $c->source_image_code,
                'artifact_path' => $c->storage_path,
                'width' => $c->width,
                'height' => $c->height,
                'prompt_sha256' => $c->prompt_sha256,
            ])->all(),
        ];
    }

    // GET /api/video-shots/queued — runner Python poll
    public function listQueuedShots(): iterable
    {
        return $this->shotRepository->findQueuedWithSession();
    }

    /**
     * @return array{claim_token: string, shots: iterable<int, VideoShot>}
     */
    public function claimQueuedShots(
        string $sessionCode,
        string $workerId,
        int $limit,
        int $leaseSeconds,
    ): array {
        $session = VideoSession::query()->where('code', $sessionCode)->firstOrFail();
        $claimToken = (string) Str::uuid();

        return [
            'claim_token' => $claimToken,
            'shots' => $this->shotRepository->claimForSession(
                $session->id,
                $workerId,
                $claimToken,
                $limit,
                now()->addSeconds($leaseSeconds),
            ),
        ];
    }

    public function heartbeatShotClaim(
        string $shotId,
        string $workerId,
        string $claimToken,
        int $leaseSeconds,
    ): bool {
        return $this->shotRepository->heartbeatClaim(
            $shotId,
            $workerId,
            $claimToken,
            now()->addSeconds($leaseSeconds),
        );
    }

    public function reclaimExpiredShotLeases(): int
    {
        return $this->shotRepository->reclaimExpiredLeases();
    }

    /**
     * PATCH /api/video-shots/{id}/result — runner bao ket qua.
     *
     * Ghi HAI thu, va chung khac ban chat:
     *
     *   video_shots     TRANG THAI HIEN TAI  — ghi de duoc, `artifact_path` la
     *                   con tro toi ban moi nhat
     *   video_renders   SU KIEN DA XAY RA    — chi INSERT, khong bao gio UPDATE
     *
     * `$render` vang thi hanh xu Y NHU TRUOC (chi doi trang thai shot). Runner cu
     * van chay duoc, va mot lan trien khai lech phien ban khong lam hong gi.
     *
     * @param  array<string, mixed>|null  $render
     */
    public function reportShotResult(
        string $shotId,
        bool $success,
        ?string $artifactPath,
        float $cost,
        ?array $render = null,
        ?string $idempotencyKey = null,
        ?string $workerId = null,
        ?string $claimToken = null,
    ): ?VideoShot {
        return DB::transaction(function () use (
            $shotId, $success, $artifactPath, $cost, $render, $idempotencyKey,
            $workerId, $claimToken,
        ): ?VideoShot {
            $sessionId = VideoShot::query()->whereKey($shotId)->valueOrFail('session_id');
            $session = VideoSession::query()->whereKey($sessionId)->lockForUpdate()->firstOrFail();
            $shot = VideoShot::query()->whereKey($shotId)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey !== null && VideoRender::query()
                ->where('shot_id', $shotId)
                ->where('idempotency_key', $idempotencyKey)
                ->exists()) {
                return $shot;
            }

            $checksOwnership = $workerId !== null && $claimToken !== null;
            if ($checksOwnership && (
                $shot->worker_id !== $workerId
                || $shot->claim_token !== $claimToken
                || ! in_array($shot->status, [
                    VideoShotStatus::CLAIMED->value,
                    VideoShotStatus::RENDERING->value,
                ], true)
                || $shot->lease_expires_at === null
                || ! $shot->lease_expires_at->isFuture()
            )) {
                return null;
            }

            $shot->update([
                'status' => ($success ? VideoShotStatus::RENDERED : VideoShotStatus::FAILED)->value,
                'artifact_path' => $artifactPath,
                'worker_id' => null,
                'claim_token' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
            ]);
            if ($success) {
                $this->recordCost($session, $shot, $cost, $render ?? []);
            }

            if ($render !== null || $idempotencyKey !== null) {
                $this->recordRender(
                    $shot, $success, $artifactPath, $cost, $render ?? [], $idempotencyKey,
                );
            }

            $this->syncSessionRenderStatus($session);

            return $shot->refresh();
        });
    }

    private function syncSessionRenderStatus(VideoSession $session): void
    {
        $statuses = $session->shots()->pluck('status');

        if ($statuses->contains(VideoShotStatus::FAILED->value)) {
            $session->update(['status' => VideoSessionStatus::FAILED->value]);

            return;
        }

        $awaitingAction = [
            VideoShotStatus::DRAFT->value,
            VideoShotStatus::APPROVED->value,
            VideoShotStatus::NEEDS_REVISION->value,
            VideoShotStatus::QUEUED->value,
            VideoShotStatus::CLAIMED->value,
            VideoShotStatus::RENDERING->value,
        ];

        if ($statuses->intersect($awaitingAction)->isNotEmpty()) {
            $session->update(['status' => VideoSessionStatus::RENDERING->value]);
        }
    }

    /**
     * @return array{ready: bool, missing: array<int, string>}
     */
    public function finalCompositionReadiness(VideoSession $session): array
    {
        $timeline = data_get($session->renderplan_json, 'timeline', []);

        if ($timeline === [] || ! is_array($timeline)) {
            return ['ready' => false, 'missing' => ['(khong co timeline trong renderplan)']];
        }

        $missing = $this->resolveTimelineClips($session, $timeline)['missing'];

        return ['ready' => $missing === [], 'missing' => $missing];
    }

    /**
     * @param  array<int, mixed>  $timeline
     * @return array{clips: array<int, array>, missing: array<int, string>}
     */
    private function resolveTimelineClips(VideoSession $session, array $timeline): array
    {
        $motionByBeat = VideoShot::query()
            ->where('session_id', $session->id)
            ->where('kind', 'motion')
            ->with('latestRender')
            ->get()
            ->keyBy('beat');

        $clips = [];
        $missing = [];

        foreach (array_values($timeline) as $index => $entry) {
            $sceneId = $entry['scene_id'] ?? null;
            $shot = $sceneId !== null ? $motionByBeat->get($sceneId) : null;
            $render = ($shot && $shot->status === VideoShotStatus::RENDERED->value) ? $shot->latestRender : null;

            if (! $render || ! $render->artifact_path || ! $render->duration_ms) {
                $missing[] = $sceneId ?? "(scene #{$index} thieu scene_id)";

                continue;
            }

            $clips[] = [
                'render_id' => $render->id,
                'path' => $render->artifact_path,
                'duration_ms' => $render->duration_ms,
                'sequence_no' => $index,
            ];
        }

        return ['clips' => $clips, 'missing' => $missing];
    }

    /**
     * @return array{0: ?VideoFinal, 1: bool, 2: string} [$final, $spawned, $reason]
     *                                                   reason: already_composing|not_ready|spawn_failed|ok
     */
    public function startFinalComposition(string $sessionId): array
    {
        // "Da co composing chua -> khong thi tao moi" phai la MOT don vi khong
        // the chia cat: hai request cung luc deu co the thay "chua co" truoc
        // khi ben nao insert xong, sinh hai final + hai tien trinh FFmpeg cung
        // ghi mot file. Khoa hang video_sessions (cung ky thuat reportShotResult()
        // da dung) de tuan tu hoa — request thu hai phai CHO request dau commit
        // xong moi duoc doc, luc do da thay dung ban composing vua tao.
        [$final, $reason] = DB::transaction(function () use ($sessionId) {
            $session = VideoSession::query()->whereKey($sessionId)->lockForUpdate()->firstOrFail();

            $inFlight = VideoFinal::query()
                ->where('session_id', $sessionId)
                ->where('status', 'composing')
                ->latest()
                ->first();

            if ($inFlight) {
                return [$inFlight, 'already_composing'];
            }

            if (! $this->finalCompositionReadiness($session)['ready']) {
                return [null, 'not_ready'];
            }

            $final = VideoFinal::create(['session_id' => $sessionId, 'status' => 'composing']);
            $session->update(['status' => VideoSessionStatus::COMPOSING_FINAL->value]);

            return [$final, 'created'];
        });

        if ($reason !== 'created') {
            return [$final, false, $reason];
        }

        // spawn() ra NGOAI transaction: sinh tien trinh la I/O co the cham, giu
        // khoa DB trong luc do chan moi request khac vao cung session.
        $sessionCode = VideoSession::query()->whereKey($sessionId)->value('code');
        $spawned = $this->pythonRunner->spawn(self::FINAL_COMPOSE_SCRIPT, $sessionCode);

        return [$final, $spawned, $spawned ? 'ok' : 'spawn_failed'];
    }

    /**
     * @return array{status: string, final_id?: string, output_path?: string, clips?: array, error?: string}
     */
    public function buildFinalCompositionPlan(string $sessionCode): array
    {
        $session = VideoSession::query()->where('code', $sessionCode)->firstOrFail();

        $final = VideoFinal::query()
            ->where('session_id', $session->id)
            ->where('status', 'composing')
            ->latest()
            ->first();

        if (! $final) {
            return ['status' => 'error', 'error' => 'khong co final dang composing cho session nay'];
        }

        // Chốt một lần — retry (mất mạng, restart) trả lại đúng plan đã dùng,
        // không tính lại từ video_shots (có thể đã đổi sau khi chốt).
        if (is_array($final->plan_json)) {
            return $final->plan_json;
        }

        $timeline = data_get($session->renderplan_json, 'timeline', []);

        if ($timeline === [] || ! is_array($timeline)) {
            return ['status' => 'error', 'final_id' => $final->id, 'error' => 'renderplan khong co timeline'];
        }

        ['clips' => $clips, 'missing' => $missing] = $this->resolveTimelineClips($session, $timeline);

        if ($missing !== []) {
            return [
                'status' => 'error',
                'final_id' => $final->id,
                'error' => 'thieu render cho cac canh: '.implode(', ', $missing),
            ];
        }

        $plan = [
            'status' => 'ok',
            'final_id' => $final->id,
            'output_path' => "/renders/finals/{$session->code}/{$final->id}.mp4",
            'clips' => $clips,
        ];

        $final->update(['plan_json' => $plan]);

        return $plan;
    }

    /**
     * Đọc `render_id` từ `plan_json` đã chốt lúc GET, không truy vấn lại
     * video_shots — tránh lệch nếu một shot bị render lại giữa lúc FFmpeg chạy.
     */
    public function recordFinalCompositionResult(
        string $finalId,
        bool $success,
        ?string $videoPath,
        ?int $durationMs,
        ?string $errorMessage,
    ): ?VideoFinal {
        return DB::transaction(function () use ($finalId, $success, $videoPath, $durationMs, $errorMessage): ?VideoFinal {
            $final = VideoFinal::query()->whereKey($finalId)->lockForUpdate()->firstOrFail();

            if ($final->status !== 'composing') {
                return $final;
            }

            if (! $success) {
                $final->update(['status' => 'failed', 'error_message' => $errorMessage]);
                $this->markSessionFinalFailed($final, $errorMessage);

                return $final->refresh();
            }

            $clips = data_get($final->plan_json, 'clips', []);

            // success=true nhung khong co plan_json (Python bao qua PATCH truc
            // tiep, bo qua GET /composing) thi KHONG duoc danh dau ready — ready
            // ma khong co cut nao la mot final "xong" nhung khong xem duoc gi.
            if ($clips === []) {
                $errorMessage = 'khong co plan_json hop le luc ghi ket qua (thieu buoc GET /video-finals/composing)';
                $final->update(['status' => 'failed', 'error_message' => $errorMessage]);
                $this->markSessionFinalFailed($final, $errorMessage);

                return $final->refresh();
            }
            $renderIds = array_column($clips, 'render_id');

            $costTotal = VideoRender::query()->whereIn('id', $renderIds)->sum('cost_usd');

            $startMs = 0;
            foreach ($clips as $clip) {
                VideoFinalRender::create([
                    'final_id' => $final->id,
                    'render_id' => $clip['render_id'],
                    'sequence_no' => $clip['sequence_no'],
                    'start_ms' => $startMs,
                    'duration_ms' => $clip['duration_ms'],
                ]);
                $startMs += (int) $clip['duration_ms'];
            }

            $final->update([
                'status' => 'ready',
                'video_path' => $videoPath,
                'duration_seconds' => $durationMs !== null ? intdiv($durationMs, 1000) : null,
                'cost_total' => $costTotal,
            ]);

            VideoSession::whereKey($final->session_id)->update(['status' => VideoSessionStatus::DONE->value]);

            return $final->refresh();
        });
    }

    private function markSessionFinalFailed(VideoFinal $final, ?string $errorMessage): void
    {
        VideoSession::whereKey($final->session_id)->update([
            'status' => VideoSessionStatus::FAILED->value,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * `attempt_no` tinh o day chu khong nhan tu Python: Laravel so huu bang nay,
     * va de Python dem thi hai tien trinh chay song song se cho cung mot so.
     *
     * `source_render_id` khop bang `source_prompt_sha256`, KHONG bang "ban render
     * moi nhat cua shot nguon". Hai phep do cho ket qua khac nhau ngay khi mot mat
     * duoc render lai roi hong: file tren dia van la cua luot cu, con "moi nhat"
     * da tro sang luot moi. Python doc sha tu chinh ho so canh tam anh no THAT SU
     * gui di, nen day la su that chu khong phai suy dien.
     *
     * @param  array<string, mixed>  $render
     */
    // Truoc day chi cong don vao `video_sessions.cost_actual`, nen biet TONG ma
    // khong biet tieu vao dau. Ghi tung dong de tra loi duoc "provider nao, model
    // nao, giai doan nao"; tong la SUM chu khong phai mot cot rieng de lech.
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

    private function recordCost(VideoSession $session, VideoShot $shot, float $cost, array $render): void
    {
        VideoCostEntry::create([
            'project_id' => $session->project_id,
            'session_id' => $session->id,
            'entity_type' => 'shot',
            'entity_id' => $shot->id,
            'stage' => 'render',
            'provider' => $render['provider'] ?? '',
            'model' => $render['model'] ?? '',
            'usage_type' => $render['render_kind'] ?? 'image',
            'quantity' => 1,
            'unit' => 'render',
            'cost_usd' => $cost,
        ]);
    }

    private function recordRender(
        VideoShot $shot,
        bool $success,
        ?string $artifactPath,
        float $cost,
        array $render,
        ?string $idempotencyKey,
    ): void {
        $sourceSha = $render['source_prompt_sha256'] ?? null;
        $sourceId = null;

        if ($sourceSha !== null) {
            $sourceId = VideoRender::query()
                ->where('prompt_sha256', $sourceSha)
                ->whereHas('shot', fn ($q) => $q->where('session_id', $shot->session_id))
                ->value('id');
        }

        VideoRender::create([
            'shot_id' => $shot->id,
            'idempotency_key' => $idempotencyKey,
            // max()+1 chu khong phai count()+1: mot dong bi xoa se lam count() cap
            // lai so cu va dam vao unique(shot_id, attempt_no).
            'attempt_no' => ((int) VideoRender::where('shot_id', $shot->id)->max('attempt_no')) + 1,
            'render_kind' => $render['render_kind'] ?? 'image',
            'provider' => $render['provider'] ?? '',
            'model' => $render['model'] ?? '',
            'sent_prompt' => $render['sent_prompt'] ?? '',
            'prompt_sha256' => $render['prompt_sha256'] ?? '',
            'request_sha256' => $render['request_sha256'] ?? null,
            'negative_prompt' => $render['negative_prompt'] ?? null,
            'source_render_id' => $sourceId,
            'source_kind' => $render['source_kind'] ?? null,
            'requires_state' => $render['requires_state'] ?? null,
            'proves_state' => $render['proves_state'] ?? null,
            'artifact_path' => $artifactPath,
            'artifact_dir' => $render['artifact_dir'] ?? null,
            'width' => $render['width'] ?? null,
            'height' => $render['height'] ?? null,
            'duration_ms' => $render['duration_ms'] ?? null,
            'bytes' => $render['bytes'] ?? null,
            'cost_usd' => $cost,
            'provider_ms' => $render['provider_ms'] ?? null,
            'status' => $success ? 'succeeded' : 'failed',
            'error_message' => $render['error_message'] ?? null,
            'proof_method' => $render['proof_method'] ?? null,
            // KHONG bao gio true o day: duong nay khong soi pixel bao gio.
            'proof_verified' => false,
        ]);
    }

    /**
     * @return array{0: ?string, 1: string} [$prompt, $reason]
     *                                      reason: session_not_found|admin_not_found|claimed_by_other|missing_concept|failed|ok
     */
    public function renderImageAnchor(string $id, Viewpoint $viewpoint = Viewpoint::FrontThreeQuarter): array
    {
        $session = VideoSession::query()->whereKey($id)->first();

        if ($session === null) {
            return [null, 'session_not_found'];
        }

        [$ok, $reason] = $this->runConceptStage($session->code);

        if (! $ok) {
            return [null, $reason];
        }

        $concept = $this->stageStore->outputOf(
            $session->id,
            (int) $session->planning_revision,
            PlanningStageName::CONCEPT,
        );

        if (! is_array($concept) || $concept === []) {
            return [null, 'missing_concept'];
        }

        return [
            $this->renderPlanService->anchorPrompt(
                $this->articleRepository->show($session->article_id),
                $concept,
                $viewpoint,
            ),
            'ok',
        ];
    }
}
