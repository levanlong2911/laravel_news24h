<?php

namespace App\Services;

use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Enums\ImageResolution;
use App\Enums\ImageVariations;
use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Services\Admin\ArticleService;
use App\Services\Video\ConceptStageRunner;
use App\Services\Video\DesignImageQueue;
use App\Services\Video\DesignImageStore;
use App\Services\Video\InspirationStageRunner;
use App\Services\Video\PlanningStageStore;
use App\Services\Video\PythonPromptCompiler;
use App\Video\Concept\Viewpoint;
use Illuminate\Support\Facades\Log;

class VideoProjectService
{
    private VideoProjectRepositoryInterface $videoProjectRepository;

    private ArticleService $articleService;

    private PlanningStageStore $stageStore;

    private InspirationStageRunner $inspirationRunner;

    private ConceptStageRunner $conceptRunner;

    private PythonPromptCompiler $promptCompiler;

    private DesignImageStore $designImageStore;

    private DesignImageQueue $designImageQueue;

    private VideoRenderPlanService $renderPlanService;

    public function __construct(
        VideoProjectRepositoryInterface $videoProjectRepository,
        ArticleService $articleService,
        PlanningStageStore $stageStore,
        InspirationStageRunner $inspirationRunner,
        ConceptStageRunner $conceptRunner,
        VideoRenderPlanService $renderPlanService,
        PythonPromptCompiler $promptCompiler,
        DesignImageStore $designImageStore,
        DesignImageQueue $designImageQueue,
    ) {
        $this->videoProjectRepository = $videoProjectRepository;
        $this->articleService = $articleService;
        $this->stageStore = $stageStore;
        $this->inspirationRunner = $inspirationRunner;
        $this->conceptRunner = $conceptRunner;
        $this->renderPlanService = $renderPlanService;
        $this->promptCompiler = $promptCompiler;
        $this->designImageStore = $designImageStore;
        $this->designImageQueue = $designImageQueue;
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

        if ($project === null) {
            return $this->emptyInspiration('Khong tim thay du an');
        }

        if ($project->article === null) {
            return $this->emptyInspiration('Du an nay khong gan voi bai viet nao');
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

    /** @return array{0: bool, 1: string} */
    public function resetInspiration(string $projectId): array
    {
        $project = $this->videoProjectRepository->getById($projectId);

        if ($project === null) {
            return [false, 'Khong tim thay du an'];
        }

        if ($project->article === null) {
            return [false, 'Du an nay khong gan voi bai viet nao'];
        }

        [$latest] = $this->stageStore->latestStageForProject(
            $project->id,
            PlanningStageName::INSPIRATION,
            $this->inspirationInput($project),
        );

        if ($latest === null) {
            return [false, 'Chua co luot phan tich nao'];
        }

        return $this->stageStore->releaseClaim($latest->id, 'Nguoi dung reset thu cong')
            ? [true, 'ok']
            : [false, 'Luot nay khong con giu claim — khong co gi de reset'];
    }

    /** @return array{0: ?string, 1: string} */
    public function runConcept(string $projectId): array
    {
        $project = $this->videoProjectRepository->getById($projectId);

        if ($project?->article === null) {
            return [null, 'Khong tim thay bai viet cua du an'];
        }

        $briefStored = $this->stageStore->latestOutputForProject($project->id, PlanningStageName::INSPIRATION);

        if ($briefStored === null) {
            return [null, 'Chua co ket qua phan tich — bam Goi Haiku truoc'];
        }

        [$stage, $token, $reason] = $this->stageStore->claimProjectStage(
            $project->id,
            PlanningStageName::CONCEPT,
            $this->conceptInput($project, $briefStored),
        );

        if ($reason === 'already_succeeded') {
            return [$this->anchorPromptFor($project, $stage->output_json ?? []), 'cached'];
        }

        if ($token === null) {
            return [null, 'Dang co mot luot dung concept chay cho du an nay'];
        }

        [$concept, $runReason] = $this->conceptRunner->run(
            $stage,
            $token,
            $project->article,
            $this->renderPlanService->briefFromStorage($briefStored),
        );

        return $concept === null
            ? [null, $runReason]
            : [$this->anchorPromptFor($project, $concept), 'ok'];
    }

    /** @return array<string, mixed> */
    public function latestConcept(string $projectId): array
    {
        $project = $this->videoProjectRepository->getById($projectId);

        if ($project === null) {
            return $this->emptyConcept('Khong tim thay du an');
        }

        if ($project->article === null) {
            return $this->emptyConcept('Du an nay khong gan voi bai viet nao');
        }

        $briefStored = $this->stageStore->latestOutputForProject($project->id, PlanningStageName::INSPIRATION);

        if ($briefStored === null) {
            return $this->emptyConcept();
        }

        [$latest, $matchesInput] = $this->stageStore->latestStageForProject(
            $project->id,
            PlanningStageName::CONCEPT,
            $this->conceptInput($project, $briefStored),
        );

        if ($latest === null) {
            return $this->emptyConcept();
        }

        $succeeded = $latest->status === VideoPlanningStageStatus::SUCCEEDED->value;
        $claimed = $latest->status === VideoPlanningStageStatus::RUNNING->value;

        return [
            'analysed' => $succeeded,
            'status' => $latest->status,
            'running' => $claimed && $latest->lease_expires_at?->isFuture() === true,
            'stuck' => $claimed && $latest->lease_expires_at?->isFuture() !== true,
            'error' => $latest->error_message,
            'can_run' => ! $matchesInput || $latest->status === VideoPlanningStageStatus::FAILED->value,
            'prompt' => $succeeded ? $this->anchorPromptFor($project, $latest->output_json ?? []) : null,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function anchorCells(string $projectId): array
    {
        return $this->designImageStore->anchorCellsFor($projectId);
    }

    /**
     * @return array{0: ?VideoDesignImage, 1: string} [$image, $reason]
     *                                                reason: queued|already_queued|not_enqueueable|image_not_found
     */
    public function enqueueDesignImage(string $projectId, string $imageId): array
    {
        // O phai thuoc DUNG du an tren URL. Khong co cho nao mot id la duoc day
        // o cua du an khac vao hang doi — do la tieu tien cua nguoi khac.
        $owned = VideoDesignImage::query()
            ->whereKey($imageId)
            ->where('project_id', $projectId)
            ->exists();

        if (! $owned) {
            return [null, 'image_not_found'];
        }

        return $this->designImageQueue->enqueue($imageId);
    }

    public function nextImageCode(string $projectId, string $creator): string
    {
        return $this->designImageStore->nextImageCode($projectId, $creator);
    }

    /**
     * @return array{0: ?VideoDesignImage, 1: string} [$image, $reason]
     *                                                reason: created|already_exists|project_not_found|<loi bien dich prompt>
     */
    public function createAnchorImage(
        string $projectId,
        string $creator,
        ImageModel $model,
        ImageQuality $quality,
        ImageResolution $resolution,
        ImageVariations $variations,
    ): array {
        [$prompt, $reason] = $this->compiledAnchorPrompt($projectId);

        if ($prompt === null) {
            return [null, $reason];
        }

        return $this->designImageStore->createCandidate($projectId, $creator, [
            'prompt' => $prompt,
            'model' => $model->value,
            'quality' => $quality->value,
            'size' => $resolution->value,
            'variations' => $variations->value,
        ]);
    }

    /** @return array{0: ?string, 1: string} */
    public function compiledAnchorPrompt(string $projectId): array
    {
        $project = $this->videoProjectRepository->getById($projectId);

        if ($project === null) {
            return [null, 'project_not_found'];
        }

        $category = (string) ($project->article?->category?->slug ?? '');

        if ($category === '') {
            return [null, 'no_category'];
        }

        $concept = $this->stageStore->latestOutputForProject($project->id, PlanningStageName::CONCEPT);

        if ($concept === null) {
            return [null, 'no_concept'];
        }

        return $this->promptCompiler->compile($category, $concept);
    }

    /** @return array{0: bool, 1: string} */
    public function resetConcept(string $projectId): array
    {
        $project = $this->videoProjectRepository->getById($projectId);

        if ($project === null) {
            return [false, 'Khong tim thay du an'];
        }

        if ($project->article === null) {
            return [false, 'Du an nay khong gan voi bai viet nao'];
        }

        $briefStored = $this->stageStore->latestOutputForProject($project->id, PlanningStageName::INSPIRATION);

        if ($briefStored === null) {
            return [false, 'Chua co ket qua phan tich — khong co concept nao de reset'];
        }

        [$latest] = $this->stageStore->latestStageForProject(
            $project->id,
            PlanningStageName::CONCEPT,
            $this->conceptInput($project, $briefStored),
        );

        if ($latest === null) {
            return [false, 'Chua co luot dung concept nao'];
        }

        return $this->stageStore->releaseClaim($latest->id, 'Nguoi dung reset thu cong')
            ? [true, 'ok']
            : [false, 'Luot nay khong con giu claim — khong co gi de reset'];
    }

    /** @return array<string, mixed> */
    private function emptyConcept(?string $reason = null): array
    {
        return [
            'analysed' => false,
            'status' => null,
            'running' => false,
            'stuck' => false,
            'error' => $reason,
            'can_run' => true,
            'prompt' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $briefStored
     * @return array<string, mixed>
     */
    private function conceptInput(VideoProject $project, array $briefStored): array
    {
        return [
            'article_id' => $project->article->id,
            'inspiration_sha256' => hash('sha256', json_encode($briefStored, JSON_THROW_ON_ERROR)),
        ];
    }

    /** @param array<string, mixed> $concept */
    private function anchorPromptFor(VideoProject $project, array $concept): ?string
    {
        return $concept === []
            ? null
            : $this->renderPlanService->anchorPrompt($project->article, $concept, Viewpoint::FrontThreeQuarter);
    }

    /** @return array<string, mixed> */
    private function emptyInspiration(?string $reason = null): array
    {
        return [
            'analysed' => false,
            'status' => null,
            'running' => false,
            'stuck' => false,
            'error' => $reason,
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
