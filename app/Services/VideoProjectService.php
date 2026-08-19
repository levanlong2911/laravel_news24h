<?php

namespace App\Services;

use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoProject;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Services\Admin\ArticleService;
use App\Services\Video\InspirationStageRunner;
use App\Services\Video\PlanningStageStore;
use Illuminate\Support\Facades\Log;

class VideoProjectService
{
    private VideoProjectRepositoryInterface $videoProjectRepository;

    private ArticleService $articleService;

    private PlanningStageStore $stageStore;

    private InspirationStageRunner $inspirationRunner;

    public function __construct(
        VideoProjectRepositoryInterface $videoProjectRepository,
        ArticleService $articleService,
        PlanningStageStore $stageStore,
        InspirationStageRunner $inspirationRunner,
    ) {
        $this->videoProjectRepository = $videoProjectRepository;
        $this->articleService = $articleService;
        $this->stageStore = $stageStore;
        $this->inspirationRunner = $inspirationRunner;
    }

    public function listAll(): iterable
    {
        return $this->videoProjectRepository->listAllWithCounts();
    }

    /** @return array{0: ?VideoProject, 1: string} */
    public function getdataByArticleId(string $articleId): array
    {
        $article = $this->articleService->getInfoArticleId($articleId);

        if ($article === null) {
            return [null, 'Khong tim thay bai viet'];
        }

        try {
            return [$this->videoProjectRepository->findOrCreateByArticleId($article), 'ok'];
        } catch (\Throwable $e) {
            Log::error('video-project: tao du an that bai', [
                'article_id' => $articleId,
                'exception' => $e,
            ]);

            return [null, $e->getMessage()];
        }
    }

    public function getdataByprojectId(string $id): ?VideoProject
    {
        return $this->videoProjectRepository->getById($id);
    }

    /** @return array{0: ?array<string, mixed>, 1: string} */
    public function runInspiration(string $projectId): array
    {
        $project = $this->videoProjectRepository->getById($projectId);

        if ($project === null) {
            return [null, 'Khong tim thay du an'];
        }

        if ($project->article === null) {
            return [null, 'Du an nay khong gan voi bai viet nao'];
        }

        [$stage, $token, $reason] = $this->stageStore->claimProjectStage(
            $project->id,
            PlanningStageName::INSPIRATION,
            $this->inspirationInput($project),
        );

        if ($reason === 'already_succeeded') {
            return [$stage->output_json, 'cached'];
        }

        if ($token === null) {
            return [null, 'Đang có một lượt phân tích chạy cho dự án này — đợi xong rồi thử lại'];
        }

        return $this->inspirationRunner->run($stage, $token, $project->article);
    }

    /** @return array<string, mixed> */
    public function latestInspiration(string $projectId): array
    {
        $project = $this->videoProjectRepository->getById($projectId);

        if ($project === null || $project->article === null) {
            return $this->emptyInspiration();
        }

        [$latest, $matchesInput] = $this->stageStore->latestStageForProject(
            $project->id,
            PlanningStageName::INSPIRATION,
            $this->inspirationInput($project),
        );

        if ($latest === null) {
            return $this->emptyInspiration();
        }

        $succeeded = $latest->status === VideoPlanningStageStatus::SUCCEEDED->value;
        $claimed = $latest->status === VideoPlanningStageStatus::RUNNING->value;
        $stored = $succeeded ? ($latest->output_json ?? []) : [];

        return [
            'analysed' => $succeeded,
            'status' => $latest->status,
            'running' => $claimed && $latest->lease_expires_at?->isFuture() === true,
            'stuck' => $claimed && $latest->lease_expires_at?->isFuture() !== true,
            'error' => $latest->error_message,
            'can_run' => ! $matchesInput || $latest->status === VideoPlanningStageStatus::FAILED->value,
            'focus' => (string) ($stored['article_focus'] ?? ''),
            'insights' => $stored['source_insights'] ?? [],
            'patterns' => $stored['article_patterns'] ?? [],
        ];
    }

    public function resetInspiration(string $projectId): bool
    {
        $project = $this->videoProjectRepository->getById($projectId);

        if ($project === null || $project->article === null) {
            return false;
        }

        [$latest] = $this->stageStore->latestStageForProject(
            $project->id,
            PlanningStageName::INSPIRATION,
            $this->inspirationInput($project),
        );

        if ($latest === null) {
            return false;
        }

        return $this->stageStore->releaseClaim($latest->id, 'Nguoi dung reset thu cong');
    }

    /** @return array<string, mixed> */
    private function emptyInspiration(): array
    {
        return [
            'analysed' => false,
            'status' => null,
            'running' => false,
            'stuck' => false,
            'error' => null,
            'can_run' => true,
            'focus' => '',
            'insights' => [],
            'patterns' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function inspirationInput(VideoProject $project): array
    {
        return [
            'article_id' => $project->article->id,
            'title' => $project->article->title,
            'content' => (string) $project->article->content,
        ];
    }
}
