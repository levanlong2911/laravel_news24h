<?php

namespace App\Services;

use App\Enums\VideoSessionStatus;
use App\Enums\VideoShotStatus;
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

/**
 * Vong doi session/shot + persistence. Approval gate (ADR v1.1):
 * draft → approved/needs_revision/rejected → queued → rendered.
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

    // PATCH /api/video-shots/{id}/result — runner báo kết quả
    public function reportShotResult(string $shotId, bool $success, ?string $artifactPath, float $cost): VideoShot
    {
        $shot = $this->shotRepository->show($shotId);
        $this->shotRepository->update($shotId, [
            'status' => ($success ? VideoShotStatus::RENDERED : VideoShotStatus::FAILED)->value,
            'artifact_path' => $artifactPath,
        ]);
        if ($success) {
            $shot->session->increment('cost_actual', $cost);
        }

        return $shot->refresh();
    }
}
