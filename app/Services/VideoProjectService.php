<?php

namespace App\Services;

use App\Enums\AnchorStage;
use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\ImageVariations;
use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoDesignImage;
use App\Models\VideoProject;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Services\Admin\ArticleService;
use App\Services\Video\ConceptStageRunner;
use App\Services\Video\DesignImageDirectRenderer;
use App\Services\Video\DesignImageQueue;
use App\Services\Video\DesignImageRenderer;
use App\Services\Video\DesignImageStore;
use App\Services\Video\InspirationStageRunner;
use App\Services\Video\PlanningStageStore;
use App\Services\Video\PythonPromptCompiler;
use App\Video\Concept\ClaudeConceptDesigner;
use App\Video\Concept\Provenance;
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

    private DesignImageRenderer $designImageRenderer;

    private DesignImageDirectRenderer $designImageDirectRenderer;

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
        DesignImageRenderer $designImageRenderer,
        DesignImageDirectRenderer $designImageDirectRenderer,
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
        $this->designImageRenderer = $designImageRenderer;
        $this->designImageDirectRenderer = $designImageDirectRenderer;
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

        $dataInput = $this->inspirationInput($project);

        [$stage, $token, $reason] = $this->stageStore->claimProjectStage(
            $project->id,
            PlanningStageName::INSPIRATION,
            $dataInput,
        );

        if ($reason === 'already_succeeded') {
            return [$stage->output_json, 'cached'];
        }

        if ($token === null) {
            return [null, 'Đang có một lượt phân tích chạy cho dự án này — đợi xong rồi thử lại'];
        }

        return $this->inspirationRunner->callInHaiku($stage, $token, $project->article);
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

        // data send Haiku
        $projectById = $this->inspirationInput($project);

        [$latest, $matchesInput] = $this->stageStore->latestStageForProject(
            $project->id,
            PlanningStageName::INSPIRATION,
            $projectById,
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

    /** @return array{0: ?array<string, mixed>, 1: string} */
    public function runConcept(string $projectId, bool $force = false): array
    {
        $project = $this->videoProjectRepository->getById($projectId);

        if ($project?->article === null) {
            return [null, 'Khong tim thay bai viet cua du an'];
        }

        $briefStored = $this->stageStore->latestOutputForProject($project->id, PlanningStageName::INSPIRATION);

        if ($briefStored === null) {
            return [null, 'Chua co ket qua phan tich — bam Goi Haiku truoc'];
        }

        $dataInput = $this->conceptInput($project, $briefStored);

        [$stage, $token, $reason] = $this->stageStore->claimProjectStage(
            $project->id,
            PlanningStageName::CONCEPT,
            $dataInput,
            $force,
        );

        if ($reason === 'already_succeeded') {
            return [$stage->output_json ?? [], 'cached'];
        }

        if ($token === null) {
            return [null, 'Dang co mot luot dung concept chay cho du an nay'];
        }

        $dataHaiku = $this->renderPlanService->briefFromStorage($briefStored);

        [$concept, $runReason] = $this->conceptRunner->goToSonnet(
            $stage,
            $token,
            $project->article,
            $dataHaiku,
        );

        return $concept === null
            ? [null, $runReason]
            : [$concept, 'ok'];
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

        $output = $succeeded ? ($latest->output_json ?? []) : [];
        $decisions = $output['decisions'] ?? [];

        return [
            'analysed' => $succeeded,
            'status' => $latest->status,
            'running' => $claimed && $latest->lease_expires_at?->isFuture() === true,
            'stuck' => $claimed && $latest->lease_expires_at?->isFuture() !== true,
            'error' => $latest->error_message,
            'can_run' => ! $matchesInput || $latest->status === VideoPlanningStageStatus::FAILED->value,
            'thesis' => $output['design_thesis'] ?? null,
            'identity' => $output['design_identity'] ?? [],
            'relationships' => $output['form_relationships'] ?? [],
            'features' => $output['signature_features'] ?? [],
            'decisions' => $decisions,
            'json' => $output,
            'meta' => $succeeded ? [
                'model' => $latest->model,
                'instruction_version' => $latest->instruction_version,
                'tokens_in' => $latest->tokens_in,
                'tokens_out' => $latest->tokens_out,
                'cost_usd' => $latest->cost_usd,
                'finished_at' => $latest->finished_at,
            ] : [],
            'provenance_summary' => $this->provenanceSummary($decisions),
            'frozen_at' => $succeeded ? $latest->finished_at : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $decisions
     * @return array<string, int>|null
     */
    private function provenanceSummary(array $decisions): ?array
    {
        if ($decisions === []) {
            return null;
        }

        $counts = [Provenance::Inspired->value => 0, Provenance::Invented->value => 0];

        foreach ($decisions as $decision) {
            $value = (string) ($decision['provenance'] ?? '');

            if (array_key_exists($value, $counts)) {
                $counts[$value]++;
            }
        }

        return [
            'total' => count($decisions),
            'inspired' => $counts[Provenance::Inspired->value],
            'invented' => $counts[Provenance::Invented->value],
        ];
    }

    /**
     * Duong render dang bat.
     *
     * `direct` la duong DONG BO cua production: claim + lease ngay tren hang du
     * lieu roi goi provider, cung hinh dang voi duong Haiku/Sonnet. Boc mot Job
     * ra ngoai sau nay khong phai sua gi ben trong.
     *
     * `queue` la duong worker Python poll — de danh cho luc chay nen hang loat.
     *
     * Ca hai deu di qua `DesignImageQueue::record()` khi ghi so cai: so cai chi
     * duoc phep co MOT noi ghi.
     */
    private function renderer(): DesignImageRenderer|DesignImageDirectRenderer
    {
        return config('video.render_mode') === 'direct'
            ? $this->designImageDirectRenderer
            : $this->designImageRenderer;
    }

    /** @return list<array<string, mixed>> */
    public function anchorCells(string $projectId): array
    {
        return $this->designImageStore->anchorCellsFor($projectId);
    }

    /**
     * @return array{0: ?VideoDesignImage, 1: string} [$image, $reason]
     *                                                reason: rendered|failed|not_enqueueable|image_not_found
     */
    public function renderDesignImage(string $projectId, string $imageId): array
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

        return $this->renderer()->renderNow($imageId);
    }

    /**
     * Nut Generate KHONG bien dich lai. Prompt da nam trong preview tu luot
     * Compile; form chi gui hash de chung minh nguoi dung dang nhin dung ban do.
     * Hash lech nghia la preview da bi dung lai o cho khac -> tu choi, khong tieu tien.
     *
     * @return array{0: ?VideoDesignImage, 1: string} [$image, $reason]
     *                                                reason: rendered|failed|timed_out|already_exists|
     *                                                anchor_prompt_missing|anchor_prompt_stale|project_not_found
     */
    public function renderAnchorFromPreview(
        string $projectId,
        string $creator,
        string $promptHash,
        ImageModel $model,
        ImageQuality $quality,
        ImageVariations $variations,
    ): array {
        $preview = $this->anchorPromptPreview($projectId);

        if ($preview === null) {
            return [null, 'anchor_prompt_missing'];
        }

        if ($preview['prompt_sha256'] !== $promptHash) {
            return [null, 'anchor_prompt_stale'];
        }

        // Ba enum nay da qua `tryFrom` trong `anchorPromptPreview()`, nen `from()`
        // o day khong the nem.
        [$image, $reason] = $this->createAnchorImage(
            $projectId,
            $creator,
            $preview['prompt'],
            AnchorStage::from($preview['stage']),
            Viewpoint::from($preview['viewpoint']),
            ImageSize::from($preview['size']),
            $model,
            $quality,
            $variations,
        );

        return $image === null ? [null, $reason] : $this->renderer()->renderNow($image->id);
    }

    public function nextImageCode(string $projectId, string $creator): string
    {
        return $this->designImageStore->nextImageCode($projectId, $creator);
    }

    /**
     * @return array{0: ?VideoDesignImage, 1: string} [$image, $reason]
     *                                                reason: created|already_exists|project_not_found
     */
    public function createAnchorImage(
        string $projectId,
        string $creator,
        string $prompt,
        AnchorStage $stage,
        Viewpoint $viewpoint,
        ImageSize $size,
        ImageModel $model,
        ImageQuality $quality,
        ImageVariations $variations,
    ): array {
        return $this->designImageStore->createCandidate($projectId, $creator, [
            'prompt' => $prompt,
            'operation' => 'generate',
            'model' => $model->value,
            'quality' => $quality->value,
            'size' => $size->value,
            'variations' => $variations->value,
            'stage' => $stage->value,
            'viewpoint' => $viewpoint->value,
        ]);
    }

    /** @return array{0: ?string, 1: string} */
    public function compiledAnchorPrompt(
        string $projectId,
        AnchorStage $stage,
        Viewpoint $viewpoint,
        ImageSize $size,
    ): array {
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

        return $this->promptCompiler->compile(
            $category, $concept, $viewpoint->value,
            $size->width(), $size->height(), $stage->value,
        );
    }

    /** @return array{prompt:string,prompt_sha256:string,stage:string,viewpoint:string,size:string,compiled_at:string}|null */
    public function anchorPromptPreview(string $projectId): ?array
    {
        $project = $this->videoProjectRepository->getById($projectId);
        $preview = $project?->metadata_json['anchor_prompt_preview'] ?? null;

        if (! is_array($preview)) {
            return null;
        }

        $size = $preview['size'] ?? $preview['resolution'] ?? null;
        $preview['size'] = $size;

        foreach (['prompt', 'prompt_sha256', 'stage', 'viewpoint', 'size', 'compiled_at'] as $key) {
            if (! is_string($preview[$key] ?? null) || trim($preview[$key]) === '') {
                return null;
            }
        }

        if (AnchorStage::tryFrom($preview['stage']) === null
            || Viewpoint::tryFrom($preview['viewpoint']) === null
            || ImageSize::tryFrom($preview['size']) === null) {
            return null;
        }

        return $preview;
    }

    public function storeAnchorPromptPreview(
        string $projectId,
        AnchorStage $stage,
        Viewpoint $viewpoint,
        ImageSize $size,
        string $prompt,
    ): bool {
        $project = $this->videoProjectRepository->getById($projectId);

        if ($project === null) {
            return false;
        }

        $metadata = $project->metadata_json ?? [];
        $metadata['anchor_prompt_preview'] = [
            'prompt' => $prompt,
            'prompt_sha256' => hash('sha256', $prompt),
            'stage' => $stage->value,
            'viewpoint' => $viewpoint->value,
            'size' => $size->value,
            'compiled_at' => now()->toIso8601String(),
        ];

        $project->metadata_json = $metadata;

        return $project->save();
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
            'thesis' => null,
            'identity' => [],
            'relationships' => [],
            'features' => [],
            'decisions' => [],
            'json' => [],
            'meta' => [],
            'provenance_summary' => null,
            'frozen_at' => null,
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
            'instruction_version' => ClaudeConceptDesigner::INSTRUCTION_VERSION,
        ];
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
