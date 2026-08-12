<?php

namespace App\Services;

use App\Enums\VideoSessionStatus;
use App\Enums\VideoShotStatus;
use App\Models\VideoFinal;
use App\Models\VideoFinalRender;
use App\Models\VideoRender;
use App\Models\VideoSession;
use App\Models\VideoShot;
use App\Repositories\Interfaces\ArticleRepositoryInterface;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Repositories\Interfaces\VideoSessionRepositoryInterface;
use App\Repositories\Interfaces\VideoShotRepositoryInterface;
use App\Video\Pipeline\PipelineAborted;
use App\Video\RenderPlan\RenderPlanAssembler;
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

    /**
     * Lần `creatVideoById()` gần nhất có bắn được tiến trình nền không.
     *
     * Cùng khuôn với `VideoRenderPlanService::$lastRun` và cùng lý do: hàm đã
     * phải trả về `VideoSession` (Controller cần để redirect), nên thông tin
     * phụ này không nhét vào giá trị trả về được mà không đổi chữ ký. Service
     * resolve theo từng request nên không có chuyện hai lần bấm lẫn nhau.
     */
    private bool $composeSpawned = false;

    public function __construct(
        private ArticleRepositoryInterface $articleRepository,
        private VideoProjectRepositoryInterface $projectRepository,
        private VideoSessionRepositoryInterface $sessionRepository,
        private VideoShotRepositoryInterface $shotRepository,
        private VideoRenderPlanService $renderPlanService,
        private PythonRunner $pythonRunner,
    ) {}

    /**
     * `false` = Controller PHẢI hiện đường lui chạy tay, nếu không người dùng
     * sẽ ngồi chờ một tiến trình không bao giờ chạy.
     */
    public function composeWasSpawned(): bool
    {
        return $this->composeSpawned;
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

    public function findForShow(string $id)
    {
        return $this->sessionRepository->findWithProjectAndShots($id);
    }

    /**
     * Nut "Tao Video" tren 1 bai viet: chay that Truth->Story->Scene->Intent->
     * Editorial->Producer->Director->RenderPlan (VideoPlanningPipeline, §18) roi
     * luu ket qua vao renderplan_json.
     *
     * NEM ra ngoai khi that bai, KHONG nuot thanh null (doi 2026-07-30).
     *
     * Ban cu bat het `\Throwable` roi `return null`, Controller chi biet "that
     * bai" va hien mot cau chung chung — LY DO bi mat sach. Hau qua thuc te:
     * phai cam `dd()` vao giua pipeline de mo xem hong o dau, ma moi lan bam lai
     * de mo la them mot cu goi Claude bi tinh tien. Nem ra ngoai thi Controller
     * doc duoc `getMessage()` va hien thang len man hinh.
     *
     * Van LOG day du o day (co stack trace) truoc khi nem — nem lai khong thay
     * the log, vi Controller chi hien mot dong cho nguoi dung.
     *
     * @throws \App\Video\Pipeline\PipelineAborted Dung som CO CHU DICH (thieu du
     *                                             lieu). Doc `->stage` de biet hong chang nao, `->spentMoney` de biet
     *                                             da ton phi chua.
     * @throws \Throwable Moi loi khac (article khong ton tai, ApprovalRequired,
     *                    LlmUnavailable, loi DB...).
     */
    public function creatVideoById(string $id): VideoSession
    {
        try {
            $article = $this->articleRepository->show($id);

            // build() goi Claude 11+ lan (Extractor + Producer + N x Director).
            // Bang chung log that: ~25 giay cho bai it scene, de len 60-90 giay
            // cho bai nhieu scene. CO Y de NGOAI transaction — giu mot
            // transaction mo suot ngan ay thoi gian se khoa hang video_projects
            // va giu connection; vai nguoi bam nut Tao Video cung luc la can
            // connection pool hoac dinh lock wait timeout.
            //
            // Cac GUARD trong VideoPlanningPipeline::plan() dung TRUOC tung cu
            // goi ton tien — bai rong thi dung khi CHUA ton dong nao.
            $renderPlan = $this->renderPlanService->build($article);

            // Transaction CHI bao phan ghi DB — vai mili giay. DB::transaction()
            // tu rollback khi co exception nen khong can beginTransaction/
            // rollBack thu cong.
            $session = DB::transaction(function () use ($article, $renderPlan) {
                $project = $this->projectRepository->findOrCreateByArticle($article->title, $article->id);

                return $this->sessionRepository->create([
                    'project_id' => $project->id,
                    // Ghi THANG khoa, khong de suy nguoc tu tien to `code`. Ma
                    // session mang 8 ky tu dau uuid — du de doan nhung van la
                    // doan, va 8 ky tu hex thi dung duoc.
                    'article_id' => $article->id,
                    'code' => 'art_'.substr($article->id, 0, 8).'_'.now()->format('ymd_His'),
                    'status' => VideoSessionStatus::COMPOSING->value,
                    'renderplan_json' => $renderPlan,
                ]);
            });

            // BAN tien trinh Python o nen (§18.25) — SAU transaction, vi script
            // se goi nguoc lai API doc session nay; ban trong transaction thi
            // no co the doc truoc khi commit xong va khong thay gi.
            //
            // KHONG chan neu ban hong: RenderPlan da luu, session da ton tai.
            // Controller doc `spawned` de hien duong lui chay tay.
            $this->composeSpawned = $this->pythonRunner->spawn(self::COMPOSE_SCRIPT, $session->code);

            return $session;
        } catch (\Throwable $e) {
            // 'exception' => $e chu KHONG phai $e->getMessage(): Laravel ghi ca
            // class, file:line va stack trace. Chi log message thi khong biet
            // hong o Extractor, Producer, Director hay applyCreationArc.
            Log::error('VideoSessionService::creatVideoById failed', [
                'article_id' => $id,
                'stage' => $e instanceof PipelineAborted ? $e->stage : null,
                'spent_money' => $e instanceof PipelineAborted ? $e->spentMoney : true,
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    // Duyệt / cần sửa / từ chối MỘT shot
    public function updateShotStatus(string $shotId, string $action, ?string $note): void
    {
        $attributes = match ($action) {
            'approve' => ['status' => VideoShotStatus::APPROVED->value, 'approved_at' => now(), 'review_note' => null],
            'revise' => ['status' => VideoShotStatus::NEEDS_REVISION->value, 'review_note' => $note ?? ''],
            'reject' => ['status' => VideoShotStatus::REJECTED->value, 'review_note' => $note ?? ''],
            default => null,
        };
        if ($attributes !== null) {
            $this->shotRepository->update($shotId, $attributes);
        }
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
            $project = $this->projectRepository->firstOrCreateByName($data['project'], $data['subject_id'] ?? null);
            $session = $this->sessionRepository->updateOrCreateByCode($data['code'], [
                'project_id' => $project->id,
                'renderplan_json' => $data['renderplan'] ?? null,
                'status' => VideoSessionStatus::REVIEWING->value,
            ]);

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
                    ]
                );
            }
            $this->sessionRepository->update($session->id, ['cost_estimate_total' => $total]);

            return ['session_id' => $session->id, 'shots' => count($data['shots'])];
        });
    }

    // GET /api/video-sessions/composing — runner poll de compose prompt
    public function listComposing(): iterable
    {
        // repairAfterDatabaseRoundTrip(): renderplan_json doc lai tu DB da mat
        // phan biet {} object rong / [] array rong (Eloquent array cast). Sua
        // truoc khi Python nhan, khong thi Python cung se thay du lieu sai shape
        // giong het test da bat duoc. Xem RenderPlanAssembler::repairAfterDatabaseRoundTrip().
        return $this->sessionRepository->findComposingWithProject()
            ->map(function (VideoSession $session) {
                if (is_array($session->renderplan_json)) {
                    $session->renderplan_json = RenderPlanAssembler::repairAfterDatabaseRoundTrip(
                        $session->renderplan_json,
                    );
                }

                return $session;
            });
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
                $shot->session()->increment('cost_actual', $cost);
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

            return;
        }

        if ($statuses->contains(VideoShotStatus::RENDERED->value)) {
            $session->update(['status' => VideoSessionStatus::DONE->value]);
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
     *         reason: already_composing|not_ready|spawn_failed|ok
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

            return [
                VideoFinal::create(['session_id' => $sessionId, 'status' => 'composing']),
                'created',
            ];
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

                return $final->refresh();
            }

            $clips = data_get($final->plan_json, 'clips', []);

            // success=true nhung khong co plan_json (Python bao qua PATCH truc
            // tiep, bo qua GET /composing) thi KHONG duoc danh dau ready — ready
            // ma khong co cut nao la mot final "xong" nhung khong xem duoc gi.
            if ($clips === []) {
                $final->update([
                    'status' => 'failed',
                    'error_message' => 'khong co plan_json hop le luc ghi ket qua (thieu buoc GET /video-finals/composing)',
                ]);

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

            return $final->refresh();
        });
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
}
