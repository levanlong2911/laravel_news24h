<?php

namespace App\Services;

use App\Models\VideoSession;
use App\Models\VideoShot;
use App\Repositories\Interfaces\ArticleRepositoryInterface;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Repositories\Interfaces\VideoSessionRepositoryInterface;
use App\Repositories\Interfaces\VideoShotRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

    // Nut "Tao Video" tren 1 bai viet: gom project theo tieu de,
    // tao session moi voi status 'composing' de Python Composer poll sinh prompt.
    // Tra ve null khi that bai (article khong ton tai, loi DB...) de Controller
    // quyet dinh redirect thanh cong hay bao loi (giong pattern TagService::addTag).
    public function createFromArticleId(string $articleId): ?VideoSession
    {
        DB::beginTransaction();
        try {
            $article = $this->articleRepository->show($articleId);
            $project = $this->projectRepository->findOrCreateByArticle($article->title, $article->id);

            $session = $this->sessionRepository->create([
                'project_id' => $project->id,
                'code' => 'art_' . substr($article->id, 0, 8) . '_' . now()->format('ymd_His'),
                'status' => 'composing',
                'renderplan_json' => [
                    'article_id' => $article->id,
                    'title'      => $article->title,
                    'content'    => $article->content,
                ],
            ]);
            DB::commit();
            return $session;
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('VideoSessionService::createFromArticleId failed', ['article_id' => $articleId, 'error' => $e->getMessage()]);
            return null;
        }
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
        return $this->sessionRepository->findComposingWithProject();
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
