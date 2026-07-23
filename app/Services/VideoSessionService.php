<?php

namespace App\Services;

use App\Models\Article;
use App\Models\VideoSession;
use App\Models\VideoShot;
use App\Repositories\Interfaces\ArticleRepositoryInterface;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Repositories\Interfaces\VideoSessionRepositoryInterface;
use App\Repositories\Interfaces\VideoShotRepositoryInterface;
use App\Services\Admin\ClaudeWriterService;
use App\Video\Article\RawArticle;
use App\Video\Llm\ClaudeWriterAdapter;
use App\Video\Llm\CostCeilingGate;
use App\Video\Llm\GatedLlmClient;
use App\Video\Pipeline\VideoPipelineFactory;
use App\Video\RenderPlan\RenderPlanAssembler;
use App\Video\RenderPlan\RenderPlanMeta;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Approval gate (ADR v1.1): draft → approved/needs_revision/rejected
 * → queued → rendered. Không có đường render nào bỏ qua duyệt.
 */
class VideoSessionService
{
    public function __construct(
        private ArticleRepositoryInterface $articleRepository,
        private VideoProjectRepositoryInterface $projectRepository,
        private VideoSessionRepositoryInterface $sessionRepository,
        private VideoShotRepositoryInterface $shotRepository,
        private ClaudeWriterService $claudeWriter,
    ) {
    }

    public function listAll(): iterable
    {
        return $this->sessionRepository->listAllWithProjectAndShotCount();
    }

    public function findForShow(string $id)
    {
        return $this->sessionRepository->findWithProjectAndShots($id);
    }

    // Nut "Tao Video" tren 1 bai viet: chay that Truth->Story->Scene->Intent->
    // Editorial->Producer->Director->RenderPlan (VideoPlanningPipeline, §18) roi
    // luu ket qua vao renderplan_json — KHONG con luu title/content tho.
    // Tra ve null khi that bai (article khong ton tai, ApprovalRequired vi Claude
    // that chua duoc duyet, loi DB...) de Controller quyet dinh redirect thanh
    // cong hay bao loi (giong pattern TagService::addTag).
    public function createFromArticleId(string $articleId): ?VideoSession
    {
        DB::beginTransaction();
        try {
            $article = $this->articleRepository->show($articleId);
            $project = $this->projectRepository->findOrCreateByArticle($article->title, $article->id);

            $renderPlan = $this->buildRenderPlan($article);

            $session = $this->sessionRepository->create([
                'project_id' => $project->id,
                'code' => 'art_' . substr($article->id, 0, 8) . '_' . now()->format('ymd_His'),
                'status' => 'composing',
                'renderplan_json' => $renderPlan,
            ]);
            DB::commit();
            return $session;
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('VideoSessionService::createFromArticleId failed', ['article_id' => $articleId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Tran chi phi CHO 1 CU GOI Claude (Extractor/Producer/Director deu tinh
     * rieng) — khong phai gioi han tong ca session. Thay DenyByDefaultGate mac
     * dinh cua GatedLlmClient bang tuong minh o day (CostCeilingGate) — cap
     * quyen la HANH DONG CO CHU Y, khong phai an theo default an toan cua he
     * thong. Doi so nay neu can sua tran.
     */
    private const LLM_COST_CEILING_USD = 0.05;

    /**
     * VideoPlanningPipeline noi Truth+Planning+Creative that. GatedLlmClient bay
     * gio dung CostCeilingGate (khong phai DenyByDefaultGate mac dinh) — MOI cu
     * goi Claude (Extractor/Producer/Director) that su chay, chi bi chan neu 1
     * cu goi don le vuot tran chi phi. Xem ARCHITECTURE.md §18.
     *
     * @return array<string, mixed> RenderPlan
     */
    private function buildRenderPlan(Article $article): array
    {
        $llm = new GatedLlmClient(
            new ClaudeWriterAdapter($this->claudeWriter),
            new CostCeilingGate(self::LLM_COST_CEILING_USD),
        );

        $pipeline = VideoPipelineFactory::claude($llm, VideoPipelineFactory::productionPolicies());

        $rawArticle = new RawArticle($article->id, $article->title, (string) $article->content);
        $meta = new RenderPlanMeta(
            Str::uuid()->toString(),
            $article->id,
            $article->title,
            'en',
            now()->toIso8601String(),
        );

        return $pipeline->plan($rawArticle, $meta);
    }

    // Duyệt / cần sửa / từ chối MỘT shot
    public function updateShotStatus(string $shotId, string $action, ?string $note): void
    {
        $attributes = match ($action) {
            'approve' => ['status' => 'approved', 'approved_at' => now(), 'review_note' => null],
            'revise'  => ['status' => 'needs_revision', 'review_note' => $note ?? ''],
            'reject'  => ['status' => 'rejected', 'review_note' => $note ?? ''],
            default   => null,
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

    // 🎬 Render — CHỈ shot approved mới vào queue
    public function queueApproved(string $sessionId): void
    {
        $this->shotRepository->queueApprovedForSession($sessionId);
        $this->sessionRepository->update($sessionId, ['status' => 'rendering']);
    }

    // POST /api/render-plans — Python đẩy session + shots (spec là INPUT, prompt là OUTPUT)
    public function storeFromPython(array $data): array
    {
        $project = $this->projectRepository->firstOrCreateByName($data['project'], $data['subject_id'] ?? null);
        $session = $this->sessionRepository->updateOrCreateByCode($data['code'], [
            'project_id' => $project->id,
            'renderplan_json' => $data['renderplan'] ?? null,
            'status' => 'reviewing',
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
                    'cost_estimate' => $s['render_plan']['cost_estimate'] ?? 0, 'status' => 'draft',
                ]
            );
        }
        $this->sessionRepository->update($session->id, ['cost_estimate_total' => $total]);

        return ['session_id' => $session->id, 'shots' => count($data['shots'])];
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
            'status' => $success ? 'rendered' : 'failed',
            'artifact_path' => $artifactPath,
        ]);
        if ($success) {
            $shot->session->increment('cost_actual', $cost);
        }
        return $shot->refresh();
    }
}
