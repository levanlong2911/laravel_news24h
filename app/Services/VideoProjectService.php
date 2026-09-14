<?php

namespace App\Services;

use App\Enums\AnchorStage;
use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\ImageVariations;
use App\Enums\DesignImageStatus;
use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\VideoArtifact;
use App\Models\Admin;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Models\VideoRenderScene;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Services\Admin\ArticleService;
use App\Services\Video\CreativeProfileResolver;
use App\Services\Video\CanonicalImageRenderer;
use App\Services\Video\DesignImageDirectRenderer;
use App\Services\Video\DesignImageQueue;
use App\Services\Video\DesignImageRenderer;
use App\Services\Video\DesignImageStore;
use App\Services\Video\InspirationStageRunner;
use App\Services\Video\OpenAiImageClient;
use App\Services\Video\PlanningStageStore;
use App\Services\Video\CanonicalPromptCompiler;
use App\Video\Concept\Handoff\CompiledAnchorPrompt;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Services\Video\PythonPromptCompiler;
use App\Services\Video\VisualIdentityStore;
use App\Video\Article\RawArticle;
use App\Video\Concept\Orchestration\CanonicalConceptInputBuilder;
use App\Video\Concept\Canonical\Enums\ProvenanceOrigin;
use App\Video\Concept\Persistence\CanonicalConceptExecutionService;
use App\Video\Concept\Viewpoint;
use App\Video\Environment\EnvironmentPlatePrompt;
use App\Video\Media\MediaModelRegistry;
use App\Video\Profiles\CategoryCreativeProfileResolver as CanonicalProfileResolver;
use App\Video\Reference\IdentityPreservationPrompt;
use App\Video\Reference\ReferenceEnvironment;
use App\Video\Reference\ReferenceView;
use App\Video\Scene\SceneProfile;
use App\Video\Scene\ScenePlanAuthor;
use App\Video\Scene\ScenePlanException;
use App\Video\Scene\ScenePlanReviewer;
use App\Video\Scene\ScenePlanReviewResult;
use App\Video\Scene\ScenePreservationPrompt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class VideoProjectService
{
    private const LOCKED_CAMERA = 'The camera stays exactly where the supplied frame was taken from: '
        .'same position, same lens, same framing, for the whole shot.';

    private const SCENE_IMAGE_MODEL = ImageModel::GPT_IMAGE_2;

    private const SCENE_IMAGE_SIZE = ImageSize::VERTICAL_2K;

    private const SCENE_IMAGE_QUALITY = ImageQuality::LOW;

    private const SCENE_SPEC_VERSION = 'scene-keyframe-v2';

    private const SCENE_IDENTITY_KEYS = [
        'operation', 'spec_version', 'render_scene_id', 'reference_manifest_hash',
    ];

    private const MANIFEST_ROLES = [
        'anchor', 'source_keyframe', 'identity', 'environment', 'geometry',
    ];

    private const ROLE_IMAGE_TYPES = [
        'anchor' => [DesignImageStore::ANCHOR_TYPE],
        'source_keyframe' => [DesignImageStore::SCENE_KEYFRAME_TYPE],
        'identity' => [DesignImageStore::ANCHOR_TYPE, DesignImageStore::REFERENCE_TYPE],
        'environment' => [DesignImageStore::ENVIRONMENT_TYPE],
        'geometry' => [DesignImageStore::REFERENCE_TYPE, DesignImageStore::SCENE_KEYFRAME_TYPE],
    ];

    /** Lua chon san pham, khong phai tran cua provider — tai lieu cho toi 16. */
    public const SCENE_MAX_SOURCE_IMAGES = 4;

    /**
     * Chi song trong MOT luot doc cua `sceneSourceCells()`. Duong render de
     * `null`, nen no khong bao gio doc lai mot ket qua cu.
     *
     * @var array<string, mixed>|null
     */
    private ?array $sourceMemo = null;

    private const CURRENT_CANDIDATE_STATUSES = [
        'candidate', 'queued', 'claimed', 'rendering', 'rendered', 'failed',
    ];

    private const COUNT_PATTERN = '/\b(two|three|four|five|six|seven|eight|nine|ten)'
        .'([- ]\w+){0,2}[- ](tiers?|decks?|cabins?|levels?)\b/i';

    private const MOTION_PATTERN = '/\b(lowers|lowering|raises|raising|moves|moving|'
        .'rotates|rotating|slides|sliding|descends|descending|rises|rising|'
        .'swings|swinging|travels|travelling|approaches|approaching|'
        .'begins|beginning|continues|continuing)\b/i';

    private const CANVAS_LEXICON = [
        'landscape', 'portrait', 'aspect ratio', 'resolution', 'pixels',
        '16:9', '9:16', '3:2', '2:3', '1024', '1536', '2048', '3840',
    ];

    private VideoProjectRepositoryInterface $videoProjectRepository;

    private ArticleService $articleService;

    private PlanningStageStore $stageStore;

    private InspirationStageRunner $inspirationRunner;

    private PythonPromptCompiler $promptCompiler;

    private CanonicalPromptCompiler $canonicalPromptCompiler;

    private DesignImageStore $designImageStore;

    private DesignImageQueue $designImageQueue;

    private DesignImageRenderer $designImageRenderer;

    private DesignImageDirectRenderer $designImageDirectRenderer;

    private CanonicalImageRenderer $canonicalImageRenderer;

    private VideoRenderPlanService $renderPlanService;

    private VisualIdentityStore $identityStore;

    private CanonicalConceptInputBuilder $canonicalConceptInputBuilder;

    private CreativeProfileResolver $creativeProfileResolver;

    private CanonicalProfileResolver $canonicalProfileResolver;

    private CanonicalConceptExecutionService $canonicalConceptExecutionService;

    public function __construct(
        VideoProjectRepositoryInterface $videoProjectRepository,
        ArticleService $articleService,
        PlanningStageStore $stageStore,
        InspirationStageRunner $inspirationRunner,
        VideoRenderPlanService $renderPlanService,
        PythonPromptCompiler $promptCompiler,
        CanonicalPromptCompiler $canonicalPromptCompiler,
        DesignImageStore $designImageStore,
        DesignImageQueue $designImageQueue,
        DesignImageRenderer $designImageRenderer,
        DesignImageDirectRenderer $designImageDirectRenderer,
        CanonicalImageRenderer $canonicalImageRenderer,
        VisualIdentityStore $identityStore,
        CanonicalConceptInputBuilder $canonicalConceptInputBuilder,
        CreativeProfileResolver $creativeProfileResolver,
        CanonicalProfileResolver $canonicalProfileResolver,
        CanonicalConceptExecutionService $canonicalConceptExecutionService,
    ) {
        $this->videoProjectRepository = $videoProjectRepository;
        $this->articleService = $articleService;
        $this->stageStore = $stageStore;
        $this->inspirationRunner = $inspirationRunner;
        $this->renderPlanService = $renderPlanService;
        $this->promptCompiler = $promptCompiler;
        $this->canonicalPromptCompiler = $canonicalPromptCompiler;
        $this->designImageStore = $designImageStore;
        $this->designImageQueue = $designImageQueue;
        $this->designImageRenderer = $designImageRenderer;
        $this->designImageDirectRenderer = $designImageDirectRenderer;
        $this->canonicalImageRenderer = $canonicalImageRenderer;
        $this->identityStore = $identityStore;
        $this->canonicalConceptInputBuilder = $canonicalConceptInputBuilder;
        $this->creativeProfileResolver = $creativeProfileResolver;
        $this->canonicalProfileResolver = $canonicalProfileResolver;
        $this->canonicalConceptExecutionService = $canonicalConceptExecutionService;
    }

    public function listAll(?Admin $actor): iterable
    {
        return $this->videoProjectRepository->listAllWithCounts($actor);
    }

    /** @return array{0: ?VideoProject, 1: string} */
    public function getdataByArticleId(string $articleId, ?Admin $actor): array
    {
        $article = $this->articleService->getInfoArticleId($articleId);

        if ($article === null) {
            return [null, 'Khong tim thay bai viet'];
        }

        try {
            return [$this->videoProjectRepository->findOrCreateByArticleId($article, $actor), 'ok'];
        } catch (AuthorizationException $e) {
            throw $e;
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
        try {
            $category = (string) ($project->article->category?->slug ?? '');
            $profile = $this->creativeProfileResolver->resolve($category);

            if ($profile === null) {
                return $this->failInspirationStage(
                    $stage->id,
                    $token,
                    "Category {$category} chua co creative profile",
                );
            }

            $result = $this->canonicalConceptInputBuilder->buildInspiration(
                $this->rawArticleFromModel($project->article),
                $profile,
            );

            $output = $this->renderPlanService->briefForStorage($result->brief, $project->article);
            $empty = $this->emptyInspirationReason($output);

            if ($empty !== null) {
                return $this->failInspirationStage(
                    $stage->id,
                    $token,
                    $empty,
                    $result->rawResponse,
                );
            }

            $this->stageStore->finishSucceeded(
                $stage->id,
                $token,
                $result->rawResponse,
                $output,
                [
                    'model' => 'haiku',
                    'instruction_version' => \App\Video\Inspiration\ClaudeInspirationAnalyst::INSTRUCTION_VERSION,
                ],
            );

            return [$output, 'ok'];
        } catch (\Throwable $e) {
            Log::error('canonical-inspiration: that bai', [
                'stage_id' => $stage->id,
                'article_id' => $project->article->id,
                'exception' => $e,
            ]);

            return $this->failInspirationStage(
                $stage->id,
                $token,
                $e->getMessage(),
                $e instanceof \App\Video\Inspiration\InvalidInspirationBrief ? $e->rawResponse : '',
            );
        }
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

    /** @param  array<string, mixed>  $output */
    private function emptyInspirationReason(array $output): ?string
    {
        $focus = trim((string) ($output['article_focus'] ?? ''));
        $insights = $output['source_insights'] ?? [];
        $patterns = $output['article_patterns'] ?? [];

        if ($focus !== '' && $insights !== []) {
            return null;
        }

        return sprintf(
            'Haiku tra ve brief rong - focus %s, insights %d, patterns %d',
            $focus === '' ? 'trong' : 'co',
            is_array($insights) ? count($insights) : 0,
            is_array($patterns) ? count($patterns) : 0,
        );
    }

    /** @return array{0: null, 1: string} */
    private function failInspirationStage(
        string $stageId,
        string $claimToken,
        string $reason,
        string $rawResponse = '',
    ): array {
        $this->stageStore->finishFailed(
            $stageId,
            $claimToken,
            $reason,
            [
                'model' => 'haiku',
                'instruction_version' => \App\Video\Inspiration\ClaudeInspirationAnalyst::INSTRUCTION_VERSION,
            ],
            $rawResponse,
        );

        return [null, $reason];
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
        // dd($this->runCanonicalConcept($projectId, $force));
        return $this->runCanonicalConcept($projectId, $force);
    }

    /** @return array{0: ?array<string, mixed>, 1: string} */
    private function runCanonicalConcept(string $projectId, bool $force): array
    {
        $project = $this->videoProjectRepository->getById($projectId);

        if ($project?->article === null) {
            return [null, 'Khong tim thay bai viet cua du an'];
        }

        $category = (string) ($project->article->category?->slug ?? '');

        if ($category === '') {
            return [null, 'Bai viet chua co category'];
        }

        $inspirationProfile = $this->creativeProfileResolver->resolve($category);

        if ($inspirationProfile === null) {
            return [null, "Category {$category} chua co creative profile"];
        }

        $dataInput = $this->canonicalConceptStageInput($project, $category);

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

        try {
            $storedInspiration = $this->stageStore->latestOutputForProject(
                $project->id,
                PlanningStageName::INSPIRATION,
            );

            if ($storedInspiration === null) {
                throw new \RuntimeException(
                    'Chua co inspiration trong DB. Hay bam Goi Haiku truoc.'
                );
            }

            $input = $this->canonicalConceptInputBuilder->fromBrief(
                objectType: $category,
                brief: $this->renderPlanService->briefFromStorage($storedInspiration),
                canonicalProfile: $this->canonicalProfileResolver->resolve($category),
            );

            $revision = $this->canonicalConceptExecutionService->create(
                projectId: $project->id,
                sessionId: null,
                input: $input,
            );

            $persisted = $this->canonicalConceptExecutionService->execute(
                revision: $revision,
                input: $input,
            );

            $output = $persisted->frozen->spec->toArray();
            $rawOutput = $persisted->frozen->canonicalJson;

            $recorded = $this->stageStore->finishSucceeded(
                $stage->id,
                $token,
                $rawOutput,
                $output,
                [
                    'model' => $this->conceptProvider(),
                    'provider_model' => $this->conceptModel(),
                    'instruction_version' => (string) config('canonical_concept.prompt_version', 'concept-v1'),
                    'tokens_in' => 0,
                    'tokens_out' => 0,
                    'thinking_tokens' => 0,
                    'cost_usd' => 0,
                ],
            );

            if (! $recorded) {
                Log::warning('canonical-concept: claim lost, paid result not recorded', [
                    'project_id' => $project->id,
                    'stage_id' => $stage->id,
                    'provider_model' => $this->conceptModel(),
                ]);
            }

            if ($this->identityStore->freezeFromConcept($project->id, $output) === null) {
                Log::warning('canonical-concept: visual identity freeze skipped', [
                    'project_id' => $project->id,
                    'stage_id' => $stage->id,
                ]);
            }

            return [$output, 'ok'];
        } catch (\Throwable $e) {
            $this->stageStore->finishFailed(
                $stage->id,
                $token,
                $e->getMessage(),
            );

            Log::error('canonical-concept: concept stage failed', [
                'project_id' => $project->id,
                'exception' => $e,
            ]);

            return [null, $e->getMessage()];
        }
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

        $category = (string) ($project->article->category?->slug ?? '');

        // Duong canonical dung tu BAI VIET chu khong tu brief da luu — nen dieu
        // kien can la category, khong phai inspiration da chay xong.
        if ($category === '') {
            return $this->emptyConcept('Bai viet chua co category');
        }

        $conceptInput = $this->canonicalConceptStageInput($project, $category);

        [$latest, $matchesInput] = $this->stageStore->latestStageForProject(
            $project->id,
            PlanningStageName::CONCEPT,
            $conceptInput,
        );

        if ($latest === null) {
            return $this->emptyConcept();
        }

        $hasCachedSuccess = $this->stageStore->hasSucceededForProject(
            $project->id,
            PlanningStageName::CONCEPT,
            $conceptInput,
        );

        $succeeded = $latest->status === VideoPlanningStageStatus::SUCCEEDED->value;
        $claimed = $latest->status === VideoPlanningStageStatus::RUNNING->value;

        $output = $succeeded ? ($latest->output_json ?? []) : [];
        $canonical = $this->isCanonicalDesignSpec($output);
        $decisions = $canonical
            ? $this->legacyDecisions($output['provenance'] ?? [])
            : ($output['decisions'] ?? []);

        return [
            'analysed' => $succeeded,
            'status' => $latest->status,
            'running' => $claimed && $latest->lease_expires_at?->isFuture() === true,
            'stuck' => $claimed && $latest->lease_expires_at?->isFuture() !== true,
            'error' => $latest->error_message,
            'can_run' => ! $matchesInput || ! $hasCachedSuccess,
            'thesis' => $canonical
                ? ($output['design_thesis']['text'] ?? null)
                : ($output['design_thesis'] ?? null),
            'identity' => $canonical
                ? $this->displayIdentityFromCanonical($output)
                : ($output['design_identity'] ?? []),
            'relationships' => $output['form_relationships'] ?? [],
            'features' => $output['signature_features'] ?? [],
            'decisions' => $decisions,
            'json' => $output,
            // Duoi Phan 1, concept CHINH LA DesignSpec — khong con buoc xuat.
            // Ban ghi cu (truoc canonical) khong dung lai duoc nua: tra rong
            // chu khong doan.
            'design_spec' => $canonical ? $output : [],
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

        $counts = [ProvenanceOrigin::INSPIRED->value => 0, ProvenanceOrigin::INVENTED->value => 0];

        foreach ($decisions as $decision) {
            $value = (string) ($decision['provenance'] ?? '');

            if (array_key_exists($value, $counts)) {
                $counts[$value]++;
            }
        }

        return [
            'total' => count($decisions),
            'inspired' => $counts[ProvenanceOrigin::INSPIRED->value],
            'invented' => $counts[ProvenanceOrigin::INVENTED->value],
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
    private function renderer(): DesignImageRenderer|DesignImageDirectRenderer|CanonicalImageRenderer
    {
        return match ((string) config('video.render_mode')) {
            'canonical' => $this->canonicalImageRenderer,
            'direct' => $this->designImageDirectRenderer,
            default => $this->designImageRenderer,
        };
    }

    /** @return list<array<string, mixed>> */
    public function anchorCells(string $projectId): array
    {
        // Mo trang la doi chieu voi dia: mot luot render da tra tien nhung
        // request chet giua chung se duoc dong so ngay tai day, khong can ai
        // bam gi hay chay lenh gi.
        if ((string) config('video.render_mode') === 'canonical') {
            $this->canonicalImageRenderer->reconcile($projectId);
        }

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
        $image = VideoDesignImage::query()
            ->whereKey($imageId)
            ->where('project_id', $projectId)
            ->first();

        if ($image === null) {
            return [null, 'image_not_found'];
        }

        // Duong nay khong biet gi ve cong revision, snapshot hay xac nhan retry.
        // O keyframe BAT BUOC di qua `renderSceneImage()`/`resumeSceneCandidate()`.
        if ($image->image_type === DesignImageStore::SCENE_KEYFRAME_TYPE) {
            return [null, 'scene_keyframe_needs_scene_flow'];
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
        ImageSize $size,
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

        // Bon enum nay da qua `tryFrom` trong `anchorPromptPreview()`, nen `from()`
        // o day khong the nem.
        [$image, $reason] = $this->createAnchorImage(
            $projectId,
            $creator,
            $preview['prompt'],
            AnchorStage::from($preview['stage']),
            Viewpoint::from($preview['viewpoint']),
            $size,
            $model,
            $quality,
            $variations,
            [
                'identity_id' => $preview['identity_id'] ?? null,
                'identity_hash' => $preview['identity_hash'] ?? null,
                'identity_version' => $preview['identity_version'] ?? null,
                'lineage' => $preview['lineage'],
                'negative_prompt' => $preview['negative_prompt'] ?? null,
                'native_controls' => [
                    'size' => $size->value,
                    'width' => $size->width(),
                    'height' => $size->height(),
                ],
            ],
        );

        return $image === null ? [null, $reason] : $this->renderer()->renderNow($image->id);
    }

    /**
     * Duong dan toi `DesignImageStore::approve()` — kiem quyen so huu va moi
     * quy tac nam trong do.
     *
     * @return array{0: bool, 1: string}
     */
    public function approveAnchor(string $projectId, string $artifactId, ?string $adminId): array
    {
        return $this->designImageStore->approve(
            $projectId, $artifactId, $adminId, null, DesignImageStore::ANCHOR_TYPE,
        );
    }

    /**
     * @return array{0: bool, 1: string}
     *          reason: approved|image_type_mismatch|artifact_not_found|
     *                  image_not_found|not_approvable|project_not_found
     */
    public function approveReference(string $projectId, string $artifactId, ?string $adminId): array
    {
        return $this->designImageStore->approve(
            $projectId, $artifactId, $adminId, null, DesignImageStore::REFERENCE_TYPE,
        );
    }

    /**
     * @return array{0: bool, 1: string}
     *          reason: approved|image_type_mismatch|artifact_not_found|
     *                  image_not_found|not_approvable|project_not_found
     */
    public function approveEnvironmentReference(
        string $projectId,
        string $artifactId,
        ?string $adminId,
    ): array {
        return $this->designImageStore->approve(
            $projectId, $artifactId, $adminId, null, DesignImageStore::ENVIRONMENT_TYPE,
        );
    }

    /**
     * @return array{0: bool, 1: string}
     *          reason: approved|candidate_outside_scene|artifact_not_in_candidate|
     *                  image_type_mismatch|artifact_not_found|image_not_found|
     *                  not_approvable|project_not_found
     */
    public function approveSceneKeyframe(
        string $projectId,
        ?string $actorId,
        string $imageId,
        string $artifactId,
    ): array {
        $candidate = VideoDesignImage::query()
            ->whereKey($imageId)
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
            ->first();

        if ($candidate === null) {
            return [false, 'candidate_outside_scene'];
        }

        if ($this->ownedScene($projectId, $actorId, (string) $candidate->render_scene_id) === null) {
            return [false, 'candidate_outside_scene'];
        }

        return $this->designImageStore->approve(
            $projectId,
            $artifactId,
            $actorId,
            (string) $candidate->id,
            DesignImageStore::SCENE_KEYFRAME_TYPE,
            (string) $candidate->render_scene_id,
        );
    }

    /**
     * @return array<string, array{approved: ?array<string, mixed>, candidate: ?array<string, mixed>}>
     */
    public function sceneKeyframeCells(string $projectId, int $revision): array
    {
        $rows = VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
            ->whereIn('render_scene_id', VideoRenderScene::query()
                ->where('project_id', $projectId)
                ->where('revision', $revision)
                ->select('id'))
            ->with(['artifacts' => fn ($query) => $query->orderBy('created_at')->orderBy('id')])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $cost = $this->designImageStore->costSummaryByImage(
            $projectId, $rows->pluck('id')->map('strval')->all(),
        );

        $cells = [];

        foreach ($rows as $row) {
            $status = (string) $row->status;
            $key = (string) $row->render_scene_id;
            $cells[$key] ??= ['approved' => null, 'candidate' => null];

            if ($status === DesignImageStatus::APPROVED->value) {
                $cells[$key]['approved'] = $this->keyframeCellView($row, $cost);

                continue;
            }

            if (in_array($status, self::CURRENT_CANDIDATE_STATUSES, true)) {
                $cells[$key]['candidate'] = $this->keyframeCellView($row, $cost);
            }
        }

        return $cells;
    }

    /**
     * @param  array<string, array{recorded: float, has_ledger: bool, has_unpriced: bool}>  $cost
     * @return array<string, mixed>
     */
    private function keyframeCellView(VideoDesignImage $row, array $cost): array
    {
        $spend = $cost[(string) $row->id] ?? [
            'recorded' => 0.0,
            'has_ledger' => false,
            'has_unpriced' => false,
            'estimated' => 0.0,
            'has_estimate' => false,
            'unclassified' => 0.0,
            'has_unclassified' => false,
        ];

        return [
            'id' => (string) $row->id,
            'cost_recorded' => $spend['recorded'],
            'cost_recorded_has_ledger' => $spend['has_ledger'],
            'cost_recorded_unpriced' => $spend['has_unpriced'],
            'cost_recorded_estimated' => $spend['estimated'],
            'cost_recorded_has_estimate' => $spend['has_estimate'],
            'cost_recorded_unclassified' => $spend['unclassified'],
            'cost_recorded_has_unclassified' => $spend['has_unclassified'],
            'status' => (string) $row->status,
            'status_label' => DesignImageStatus::tryFrom((string) $row->status)?->label()
                ?? (string) $row->status,
            'render_error' => $row->render_error,
            'prompt_sha256' => (string) $row->prompt_sha256,
            'selected_artifact_id' => (string) $row->selected_artifact_id,
            'resumable' => in_array((string) $row->status, [
                DesignImageStatus::CANDIDATE->value,
                DesignImageStatus::FAILED->value,
            ], true),
            'artifacts' => $row->artifacts->map(fn (VideoArtifact $artifact) => [
                'id' => (string) $artifact->id,
                'url' => route('video-artifacts.show', $artifact->id),
                'sha' => substr((string) $artifact->sha256, 0, 12),
            ])->all(),
        ];
    }


    /**
     * Anh nguon that su se duoc gui, giai bang CHINH `resolveSource()` cua duong
     * render — khong co truy van rieng cho man hinh, nen khong the lech.
     *
     * @return array<string, array<string, mixed>> khoa theo scene id
     */
    public function sceneSourceCells(string $projectId, int $revision): array
    {
        $stage = $this->stageStore->stageForProjectRevision(
            $projectId, PlanningStageName::SCENE_PLAN, $revision,
        );

        $scenes = VideoRenderScene::query()
            ->where('project_id', $projectId)
            ->where('revision', $revision)
            ->orderBy('scene_index')
            ->get();

        $this->sourceMemo = [];
        $cells = [];

        try {
            $views = $this->approvedReferenceViews($projectId);

            foreach ($scenes as $scene) {
                $cells[(string) $scene->id] = $this->sceneSourceCell($projectId, $scene, $stage, $views);
            }
        } finally {
            $this->sourceMemo = null;
        }

        return $cells;
    }


    /**
     * Doc thang tu manifest se gui — man hinh khong the noi khac duong render.
     *
     * @param  list<array<string, mixed>>  $views
     * @return array<string, mixed>
     */
    private function sceneSourceCell(
        string $projectId,
        VideoRenderScene $scene,
        ?VideoPlanningStage $stage,
        array $views,
    ): array {
        [$slots, $why] = $this->sceneManifestSlots($projectId, $scene, $stage, $views);

        if ($slots === null) {
            return ['slots' => [], 'prompt' => null, 'blocked_reason' => $why];
        }

        [$preservation] = $this->preservationForRevision($stage);

        return [
            'slots' => array_map(fn (array $slot): array => [
                'position' => $slot['position'],
                'role' => $slot['role'],
                'title' => $slot['title'],
                'primary' => $slot['position'] === 0,
                'url' => route('video-artifacts.show', $slot['artifact_id']),
                'sha' => substr((string) $slot['sha256'], 0, 12),
                'state' => $this->sourceState($projectId, $this->manifestEntry($slot)),
            ], $slots),
            'prompt' => $preservation === null
                ? null
                : ScenePreservationPrompt::forManifest(
                    (string) $scene->transition_mode,
                    array_column($slots, 'role'),
                    $preservation,
                )."\n\n".(string) $scene->delta_prompt,
            'blocked_reason' => null,
        ];
    }

    /** @return array{0: ?array<string, mixed>} */
    private function anchorForDisplay(
        string $projectId,
        VideoRenderScene $scene,
        ?VideoPlanningStage $stage,
    ): array {
        [$lock, $why] = $this->lockedAnchor($projectId, (int) $scene->revision);

        if ($why === 'anchor_lock_unreadable') {
            return [null];
        }

        [$anchor] = $lock !== null
            ? $this->anchorSourceFromLock($projectId, $lock)
            : $this->proposedAnchorSource($projectId, $stage);

        return [$anchor];
    }

    /**
     * Bon o nguon theo hop dong. Vi tri 0 la anh DUY NHAT duoc sua; 1..3 chi doc.
     * Thieu o nao thi manifest ngan lai, khong don cho bang anh sai vai tro.
     *
     * @param  list<array<string, mixed>>  $views
     * @return array{0: ?list<array<string, mixed>>, 1: string}
     */
    private function sceneManifestSlots(
        string $projectId,
        VideoRenderScene $scene,
        ?VideoPlanningStage $stage,
        array $views,
    ): array {
        [$primary, $why] = $this->resolveSource($scene, $stage, null, false);

        if ($primary === null) {
            return [null, $why];
        }

        $continues = $primary['role'] === 'source_keyframe';
        [$anchor] = $continues ? $this->anchorForDisplay($projectId, $scene, $stage) : [null];

        $used = [$primary['artifact_id'] => true];
        $slots = [array_replace($primary, [
            'title' => $continues ? 'Từ '.$scene->source_scene_code : 'Ảnh neo',
        ])];

        [$plate, $plateWhy] = $this->approvedPlate($projectId, $scene);

        if ($plate === null) {
            return [null, $plateWhy];
        }

        foreach (['identity', 'environment', 'geometry'] as $role) {
            if (count($slots) >= self::SCENE_MAX_SOURCE_IMAGES) {
                break;
            }

            $entry = match ($role) {
                'identity' => $continues ? $anchor : $this->pickView($views, $used),
                'environment' => $plate,
                default => $this->pickView($views, $used),
            };

            if ($entry === null || array_key_exists($entry['artifact_id'], $used)) {
                continue;
            }

            $used[$entry['artifact_id']] = true;
            $slots[] = array_replace($entry, [
                'role' => $role,
                'title' => $entry['title'] ?? 'Ảnh neo',
            ]);
        }

        foreach ($slots as $position => $slot) {
            $slots[$position] = array_replace($slot, ['position' => $position]);

            if (! $this->manifestEntryShaped($this->manifestEntry($slots[$position]))) {
                return [null, 'manifest_entry_malformed'];
            }
        }

        return [$slots, 'ok'];
    }

    /**
     * Manifest ghi vao spec chi mang nam khoa. `title` la thu cua man hinh.
     *
     * @param  array<string, mixed>  $slot
     * @return array<string, mixed>
     */
    private function manifestEntry(array $slot): array
    {
        return array_intersect_key($slot, array_flip([
            'artifact_id', 'candidate_id', 'position', 'role', 'sha256',
        ]));
    }

    /**
     * @param  list<array<string, mixed>>  $views
     * @param  array<string, bool>  $used
     * @return array<string, mixed>|null
     */
    private function pickView(array $views, array $used): ?array
    {
        foreach ($views as $view) {
            if (array_key_exists($view['artifact_id'], $used)) {
                continue;
            }

            return [
                'artifact_id' => $view['artifact_id'],
                'candidate_id' => $view['candidate_id'],
                'sha256' => $view['sha256'],
                'title' => $view['title'],
            ];
        }

        return null;
    }

    /**
     * Slot moi truong LUON lay tam nen sach, khong bao gio lay reference view:
     * reference view mang than tau, nen no se nhet mot vo thu hai vao prompt.
     *
     * @return array{0: ?array<string, mixed>, 1: string} [$entry, $reason]
     */
    private function approvedPlate(string $projectId, VideoRenderScene $scene): array
    {
        $profile = $this->environmentProfileFor($projectId);

        if ($profile === null) {
            return [null, 'environment_no_profile'];
        }

        [$key, $why] = $profile->environmentForMilestones(
            is_array($scene->milestone_keys) ? $scene->milestone_keys : [],
        );

        if ($key === null) {
            return [null, match ($why) {
                'ambiguous_environment' => 'environment_ambiguous',
                'unknown_milestone' => 'environment_unknown_milestone',
                default => 'environment_undeclared',
            }];
        }

        $label = $profile->environmentLabelOf($key) ?? $key;
        $plate = $this->approvedPlates($projectId)[$key] ?? null;

        return $plate === null
            ? [null, 'environment_plate_missing|'.$label]
            : [array_replace($plate, ['title' => $label]), 'ok'];
    }

    /** @return array<string, array<string, mixed>> */
    private function approvedPlates(string $projectId): array
    {
        $memoKey = 'plates:'.$projectId;

        if ($this->sourceMemo !== null && array_key_exists($memoKey, $this->sourceMemo)) {
            return $this->sourceMemo[$memoKey];
        }

        $plates = [];

        foreach (VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::ENVIRONMENT_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->whereNotNull('selected_artifact_id')
            ->whereNotNull('environment_key')
            ->with('artifact')
            ->get() as $row) {
            if ($row->artifact === null) {
                continue;
            }

            $plates[(string) $row->environment_key] = [
                'artifact_id' => (string) $row->selected_artifact_id,
                'candidate_id' => (string) $row->id,
                'sha256' => (string) $row->artifact->sha256,
                'title' => (string) $row->environment_key,
            ];
        }

        if ($this->sourceMemo !== null) {
            $this->sourceMemo[$memoKey] = $plates;
        }

        return $plates;
    }

    private function environmentProfileFor(string $projectId): ?SceneProfile
    {
        $memoKey = 'env_profile:'.$projectId;

        if ($this->sourceMemo !== null && array_key_exists($memoKey, $this->sourceMemo)) {
            return $this->sourceMemo[$memoKey];
        }

        $project = $this->videoProjectRepository->getById($projectId);
        $profile = $project === null ? null : $this->environmentProfile($project);

        if ($this->sourceMemo !== null) {
            $this->sourceMemo[$memoKey] = $profile;
        }

        return $profile;
    }

    /** @return list<array<string, mixed>> */
    private function approvedReferenceViews(string $projectId): array
    {
        $key = 'views:'.$projectId;

        if ($this->sourceMemo !== null && array_key_exists($key, $this->sourceMemo)) {
            return $this->sourceMemo[$key];
        }

        $views = [];

        foreach (VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::REFERENCE_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->whereNotNull('selected_artifact_id')
            ->with('artifact')
            ->get() as $row) {
            $spec = is_array($row->prompt_spec_json) ? $row->prompt_spec_json : [];
            $view = ReferenceView::tryFrom((string) ($spec['view_key'] ?? ''));

            if ($row->artifact === null) {
                continue;
            }

            $views[] = [
                'artifact_id' => (string) $row->selected_artifact_id,
                'candidate_id' => (string) $row->id,
                'sha256' => (string) $row->artifact->sha256,
                'environment' => (string) ($spec['environment'] ?? ''),
                'slot' => $view?->slot() ?? PHP_INT_MAX,
                'title' => $view?->label() ?? (string) ($spec['view_key'] ?? 'Tham chiếu'),
            ];
        }

        usort($views, static fn (array $a, array $b): int => [$a['slot'], $a['artifact_id']]
            <=> [$b['slot'], $b['artifact_id']]);

        if ($this->sourceMemo !== null) {
            $this->sourceMemo[$key] = $views;
        }

        return $views;
    }

    /** @return array<string, mixed>|null */
    public function referencePageData(string $projectId): ?array
    {
        $project = $this->videoProjectRepository->getById($projectId);

        if ($project === null) {
            return null;
        }

        $anchor = $this->designImageStore->approvedAnchorFor($projectId);

        return [
            'id' => $projectId,
            'project' => $project,
            'approvedAnchor' => $anchor,
            'referenceViews' => $this->designImageStore->referenceCellsFor($projectId),
            'referenceViewCases' => ReferenceView::cases(),
            'referenceEnvironmentCases' => ReferenceEnvironment::cases(),
            'preservationBlock' => IdentityPreservationPrompt::text(),
            'mirrorReady' => $anchor?->artifact === null
                ? []
                : $this->mirrorReadyKeys($projectId, (string) $anchor->artifact->sha256),
            'cameraOverrides' => collect(ReferenceView::cases())
                ->mapWithKeys(fn (ReferenceView $view) => [$view->value => $this->cameraBlock($view)])
                ->all(),
            'environmentOverrides' => collect(ReferenceEnvironment::cases())
                ->mapWithKeys(fn (ReferenceEnvironment $env) => [$env->value => $env->override()])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?VideoDesignImage, 1: string} [$image, $reason]
     */
    public function renderReferenceDirect(string $projectId, string $creator, array $data): array
    {
        $anchor = $this->designImageStore->approvedAnchorFor($projectId);

        if ($anchor === null) {
            return [null, 'no_approved_anchor'];
        }

        $artifact = $anchor->artifact;

        if ($artifact === null) {
            return [null, 'artifact_not_found'];
        }

        $disk = Storage::disk((string) $artifact->storage_disk);
        $path = (string) $artifact->storage_path;

        if ($path === '' || ! $disk->exists($path)) {
            return [null, 'artifact_file_not_found'];
        }

        $artifactSha = hash('sha256', (string) $disk->get($path));

        if (! hash_equals((string) $artifact->sha256, $artifactSha)) {
            return [null, 'artifact_checksum_mismatch'];
        }

        $view = ReferenceView::from((string) $data['view']);
        $environment = ReferenceEnvironment::from((string) $data['environment']);

        $mirrorSource = $this->mirrorSourceArtifact($projectId, $view, $environment, $artifactSha);

        $spec = $mirrorSource === null
            ? [
                'operation' => 'edit',
                'derivation' => 'gpt_edit',
                'source_image_id' => $anchor->id,
                'source_artifact_id' => $artifact->id,
                'source_artifact_sha256' => $artifactSha,
                'prompt' => $this->referencePrompt($view, $environment),
                'variations' => (int) $data['variations'],
            ]
            : [
                'operation' => 'mirror',
                // Lat anh bang GD ngay tren may: khong co dong nao chay, nen o nay
                // khong duoc mang uoc tinh nao ca.
                'pricing' => 'free',
                'derivation' => 'horizontal_flip',
                'source_image_id' => $mirrorSource->design_image_id,
                'source_artifact_id' => $mirrorSource->id,
                'source_artifact_sha256' => (string) $mirrorSource->sha256,
                'prompt' => IdentityPreservationPrompt::derivationStatement((string) $mirrorSource->sha256),
                'variations' => 1,
            ];

        [$image, $reason] = $this->designImageStore->createReference($projectId, $creator, $spec + [
            'project_id' => $projectId,
            'image_type' => 'reference_view',
            'view_key' => $view->value,
            'environment' => $environment->value,
            'slot_index' => $view->slot(),
            'identity_lock_id' => null,
            'identity_lock_hash' => null,
            'derivation_version' => IdentityPreservationPrompt::VERSION,
            'model' => (string) $data['model'],
            'quality' => (string) $data['quality'],
            'size' => (string) $data['size'],
        ]);

        if ($image === null) {
            return [null, $reason];
        }

        if ($reason === 'already_exists' && in_array($image->status, [
            DesignImageStatus::RENDERED->value,
            DesignImageStatus::APPROVED->value,
        ], true)) {
            return [$image, 'already_exists'];
        }

        return $this->designImageDirectRenderer->renderNow($image->id);
    }

    /** @return array<string, mixed>|null */
    public function environmentPageData(string $projectId): ?array
    {
        $project = $this->videoProjectRepository->getById($projectId);

        if ($project === null) {
            return null;
        }

        $profile = $this->environmentProfile($project);
        $environments = [];

        foreach ($profile?->environmentKeys() ?? [] as $key) {
            $environments[] = [
                'key' => $key,
                'label' => (string) $profile->environmentLabelOf($key),
                'place' => (string) $profile->environmentPromptOf($key),
                'prompt' => EnvironmentPlatePrompt::text((string) $profile->environmentPromptOf($key)),
            ];
        }

        try {
            $registry = app(MediaModelRegistry::class);
            $mediaModels = $registry->forTask(EnvironmentPlatePrompt::TASK);
            $defaultMediaModel = $registry->defaultFor(EnvironmentPlatePrompt::TASK);
            $mediaModelsError = null;
        } catch (InvalidArgumentException $e) {
            Log::error('environment: registry model hong', ['error' => $e->getMessage()]);

            $mediaModels = [];
            $defaultMediaModel = null;
            $mediaModelsError = __('messages.environment_media_models_broken');
        }

        return [
            'id' => $projectId,
            'project' => $project,
            'profileVersion' => $profile?->version,
            'environments' => $environments,
            'environmentCells' => $this->designImageStore->environmentCellsFor($projectId),
            'plateVersion' => EnvironmentPlatePrompt::VERSION,
            'mediaModels' => $mediaModels,
            'defaultMediaModel' => $defaultMediaModel,
            'mediaModelsError' => $mediaModelsError,
            'renderedModels' => $this->modelsWithAnEnvironmentRender(),
            'qualityCosts' => collect(ImageQuality::cases())
                ->mapWithKeys(fn (ImageQuality $quality) => [
                    $quality->value => $quality->estimatedCostUsd(),
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?VideoDesignImage, 1: string} [$image, $reason]
     */
    public function renderEnvironmentDirect(string $projectId, string $creator, array $data): array
    {
        $project = $this->videoProjectRepository->getById($projectId);

        if ($project === null) {
            return [null, 'project_not_found'];
        }

        $profile = $this->environmentProfile($project);

        if ($profile === null) {
            return [null, 'no_environment_profile'];
        }

        $key = (string) ($data['environment_key'] ?? '');
        $place = $profile->environmentPromptOf($key);

        if ($place === null) {
            return [null, 'unknown_environment_key'];
        }

        [$entry, $reason] = $this->environmentMediaModel((string) ($data['provider_model'] ?? ''));

        if ($entry === null) {
            return [null, $reason];
        }

        [$spec, $reason] = $this->environmentSpec($projectId, $key, $profile, $place, $entry, $data);

        if ($spec === null) {
            return [null, $reason];
        }

        [$image, $reason] = $this->designImageStore->createEnvironment($projectId, $creator, $spec);

        if ($image === null) {
            return [null, $reason];
        }

        if ($reason === 'already_exists' && in_array($image->status, [
            DesignImageStatus::RENDERED->value,
            DesignImageStatus::APPROVED->value,
        ], true)) {
            return [$image, 'already_exists'];
        }

        return $this->designImageDirectRenderer->renderNow($image->id);
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: string} [$entry, $reason]
     */
    private function environmentMediaModel(string $id): array
    {
        try {
            $entry = app(MediaModelRegistry::class)->find(EnvironmentPlatePrompt::TASK, $id);
        } catch (InvalidArgumentException $e) {
            Log::error('environment: registry model hong khi render', ['error' => $e->getMessage()]);

            return [null, 'environment_media_models_broken'];
        }

        return $entry === null
            ? [null, 'environment_unknown_media_model']
            : [$entry, 'ok'];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $data
     * @return array{0: ?array<string, mixed>, 1: string} [$spec, $reason]
     */
    private function environmentSpec(
        string $projectId,
        string $key,
        SceneProfile $profile,
        string $place,
        array $entry,
        array $data,
    ): array {
        $invalid = [null, 'environment_media_setting_invalid'];
        $controls = $entry['controls'];
        $variations = $this->positiveInt($data['variations'] ?? null);

        if ($variations === null || $variations > $entry['max_variations']) {
            return $invalid;
        }

        $common = [
            'project_id' => $projectId,
            'operation' => 'environment_plate',
            'spec_version' => EnvironmentPlatePrompt::VERSION,
            'environment_key' => $key,
            'profile_version' => $profile->version,
            'profile_sha256' => $profile->sha256,
            'prompt' => EnvironmentPlatePrompt::text($place),
            'provider' => $entry['provider'],
            'model' => $entry['model'],
            'pricing' => $entry['pricing'],
            'variations' => $variations,
        ];

        if ($entry['provider'] === 'openai') {
            $size = $this->choice($data, 'size', $controls['sizes']);
            $quality = $this->choice($data, 'quality', $controls['qualities']);

            return $size === null || $quality === null
                ? $invalid
                : [$common + ['size' => $size, 'quality' => $quality], 'ok'];
        }

        $aspect = $this->choice($data, 'aspect_ratio', $controls['aspect_ratios']);
        $imageSize = $this->choice($data, 'image_size', $controls['image_sizes']);

        return $aspect === null || $imageSize === null
            ? $invalid
            : [$common + [
                'api_version' => $entry['api_version'],
                'shape' => $entry['shape'],
                'aspect_ratio' => $aspect,
                'image_size' => $imageSize,
                'size' => $aspect.'@'.$imageSize,
                'quality' => null,
                'unit_cost_usd' => $entry['controls']['prices'][$imageSize] ?? null,
                'pricing_version' => $entry['pricing_version'] ?? null,
            ], 'ok'];
    }

    private function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1 ? $value : null;
        }

        return is_string($value) && preg_match('/^[1-9][0-9]{0,2}$/', $value) === 1 ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     */
    private function choice(array $data, string $field, array $allowed): ?string
    {
        $value = $data[$field] ?? null;

        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /** @return list<string> */
    private function modelsWithAnEnvironmentRender(): array
    {
        return DB::table('video_renders')
            ->where('render_kind', EnvironmentPlatePrompt::TASK)
            ->where('status', 'succeeded')
            ->distinct()
            ->get(['provider', 'model'])
            ->map(fn (object $row): string => $row->provider.':'.$row->model)
            ->values()
            ->all();
    }

    private function environmentProfile(VideoProject $project): ?SceneProfile
    {
        $category = (string) ($project->article->category?->slug ?? '');
        $version = config("video.environment.profiles.{$category}");

        if (! is_string($version) || $version === '') {
            return null;
        }

        $profile = SceneProfile::load((string) config('video.scene_plan.profile_dir'), $version);

        return $profile->environmentKeys() === [] ? null : $profile;
    }

    /**
     * @return list<string> Cac khoa "view|environment" se duoc lat thay vi goi
     *                      provider, de man hinh khoa variations ve 1 dung luc.
     */
    private function mirrorReadyKeys(string $projectId, string $anchorSha): array
    {
        $ready = [];

        foreach (ReferenceView::cases() as $view) {
            foreach (ReferenceEnvironment::cases() as $environment) {
                if ($this->mirrorSourceArtifact($projectId, $view, $environment, $anchorSha) !== null) {
                    $ready[] = $view->value.'|'.$environment->value;
                }
            }
        }

        return $ready;
    }

    private function referencePrompt(ReferenceView $view, ReferenceEnvironment $environment): string
    {
        $blocks = [IdentityPreservationPrompt::text(), $this->cameraBlock($view)];

        if ($environment->override() !== '') {
            $blocks[] = $environment->override();
        }

        return implode("\n\n", $blocks);
    }

    /**
     * @return VideoArtifact|null Anh cua goc doi xung da render tu CUNG mot anchor.
     *                            Tra null thi duong edit chay; khong bao gio lay
     *                            nguon tu mot o mirror khac.
     */
    private function mirrorSourceArtifact(
        string $projectId,
        ReferenceView $view,
        ReferenceEnvironment $environment,
        string $anchorSha,
    ): ?VideoArtifact {
        $partner = $view->mirrorPartner();

        if ($partner === null) {
            return null;
        }

        $cells = VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_type', 'reference_view')
            ->whereIn('status', [
                DesignImageStatus::RENDERED->value,
                DesignImageStatus::APPROVED->value,
            ])
            ->with(['artifacts' => fn ($query) => $query->orderBy('created_at')->orderBy('id')])
            ->get();

        foreach ($cells as $cell) {
            $spec = $cell->prompt_spec_json ?? [];

            if ((string) ($spec['view_key'] ?? '') !== $partner->value
                || (string) ($spec['environment'] ?? '') !== $environment->value
                || (string) ($spec['operation'] ?? '') !== 'edit'
                || (string) ($spec['source_artifact_sha256'] ?? '') !== $anchorSha) {
                continue;
            }

            $artifact = $cell->artifact ?? $cell->artifacts->first();

            if ($artifact !== null && $artifact->render_id !== null) {
                return $artifact;
            }
        }

        return null;
    }

    private function cameraBlock(ReferenceView $view): string
    {
        return 'REFERENCE VIEW OVERRIDE: Replace any previous CAMERA / VIEW instruction with the following. '
            .'Keep all canonical identity, topology and proportions unchanged. '
            .$view->cameraOverride();
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
        array $identity = [],
    ): array {
        return $this->designImageStore->createCandidate($projectId, $creator, $identity + [
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

    /** @return array{0: ?CompiledAnchorPrompt, 1: string, 2: ?array<string, mixed>} */
    public function compiledAnchorPrompt(
        string $projectId,
        AnchorStage $stage,
        Viewpoint $viewpoint,
        ImageSize $size,
        ?ImageModel $model = null,
        \App\Enums\PromptProducer $producer = \App\Enums\PromptProducer::CANONICAL,
    ): array {
        $project = $this->videoProjectRepository->getById($projectId);

        if ($project === null) {
            return [null, 'project_not_found', null];
        }

        if ($producer === \App\Enums\PromptProducer::SKILL) {
            return $this->skillAnchorPrompt($project->id, $stage, $size, $model);
        }

        $revision = CanonicalConceptRevision::query()
            ->where('video_project_id', $project->id)
            ->where('status', CanonicalConceptStatus::FROZEN)
            ->orderByDesc('revision')
            ->first();

        if ($revision === null) {
            return [null, 'no_frozen_canonical_revision', null];
        }

        $concept = json_decode((string) $revision->canonical_json, true);

        if (! is_array($concept)) {
            return [null, 'frozen_canonical_json_unreadable', null];
        }

        [$compiled, $reason] = $this->canonicalPromptCompiler->compile(
            $revision, $viewpoint->value,
            $size->width(), $size->height(), $stage->value, [],
            $model, $project->id, $revision->id,
        );

        return [$compiled, $reason, $concept];
    }

    /**
     * Duong skill khong doc canonical revision: no di thang tu brief Haiku.
     *
     * Di qua `PlanningStageStore` nhu moi chang khac — de dung lai claim/lease
     * (chan tra tien hai lan), cache `already_succeeded` (cung dau vao thi
     * KHONG goi model lai) va cot `cost_usd` da co san.
     *
     * Khong co concept nen `$concept` tra ve rong: `storeAnchorPromptPreview()`
     * se khong dong bang duoc VisualIdentity, va do la dung — prompt nay khong
     * suy ra tu mot ban thiet ke da dong bang nao.
     *
     * @return array{0: ?CompiledAnchorPrompt, 1: string, 2: ?array<string, mixed>}
     */
    private function skillAnchorPrompt(
        string $projectId,
        AnchorStage $stage,
        ImageSize $size,
        ?ImageModel $model,
    ): array {
        if ($model === null) {
            return [null, 'anchor_setting_required', null];
        }

        $brief = $this->stageStore->latestOutputForProject(
            $projectId,
            PlanningStageName::INSPIRATION,
        );

        if (! is_array($brief) || $brief === []) {
            return [null, 'no_inspiration_brief', null];
        }

        $author = app(\App\Video\Prompt\GeometryPromptAuthor::class);

        $input = [
            'brief_hash' => hash('sha256', json_encode($brief, JSON_UNESCAPED_UNICODE)),
            'skill_hash' => $author->skillHash(),
        ];

        [$claimed, $token, $reason] = $this->stageStore->claimProjectStage(
            $projectId,
            PlanningStageName::ANCHOR_PROMPT,
            $input,
        );

        if ($reason === 'already_succeeded') {
            $compiled = $author->rehydrate(
                $claimed->output_json ?? [], $stage, $size, $model,
            );

            return $compiled === null
                ? [null, 'anchor_prompt_missing', null]
                : [$compiled, 'cached', []];
        }

        if ($token === null) {
            return [null, 'Dang co mot luot dung prompt chay cho du an nay', null];
        }

        try {
            $result = $author->author($brief, $stage, $size, $model);
        } catch (\Throwable $e) {
            $this->stageStore->finishFailed($claimed->id, $token, $e->getMessage());

            Log::error('skill-anchor-prompt: author failed', [
                'project_id' => $projectId,
                'exception' => $e,
            ]);

            return [null, $e->getMessage(), null];
        }

        $recorded = $this->stageStore->finishSucceeded(
            $claimed->id,
            $token,
            $result->compiled->prompt,
            $result->toStorage(),
            [
                'model' => (string) config('canonical_concept.provider'),
                'provider_model' => $result->authorModel,
                'instruction_version' => $result->compiled->promptVersion,
                'tokens_in' => $result->inputTokens,
                'tokens_out' => $result->outputTokens,
                'thinking_tokens' => 0,
                'cost_usd' => 0,
            ],
        );

        if (! $recorded) {
            Log::warning('skill-anchor-prompt: claim lost, paid result not recorded', [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
                'provider_model' => $result->authorModel,
            ]);
        }

        return [$result->compiled, 'ok', []];
    }

    /**
     * Python image_prompt still compiles the `creative_concept` branch. Store the
     * canonical concept in Laravel, then project it at this boundary only.
     *
     * @param  array<string, mixed>  $concept
     * @return array<string, mixed>
     */
    private function pythonConceptFromStored(array $concept): array
    {
        if (! $this->isCanonicalDesignSpec($concept)) {
            return $concept;
        }

        $dimensions = is_array($concept['dimensions'] ?? null) ? $concept['dimensions'] : [];
        $geometry = is_array($concept['permanent_geometry'] ?? null) ? $concept['permanent_geometry'] : [];
        $materials = is_array($concept['finished_materials'] ?? null) ? $concept['finished_materials'] : [];

        $identity = [
            'design_length_m' => $dimensions['length_m'] ?? null,
            'design_beam_m' => $dimensions['beam_m'] ?? null,
            'length_to_beam_ratio' => $dimensions['length_to_beam_ratio'] ?? null,
            'design_draft_m' => $dimensions['draft_m'] ?? null,
            'visible_freeboard_at_midships_m' => $dimensions['freeboard_midships_m'] ?? null,
            'typical_deck_to_deck_height_m' => $dimensions['deck_to_deck_height_m'] ?? null,
            'visible_deck_tiers' => $geometry['superstructure']['enclosed_deck_levels']
                ?? ($geometry['superstructure']['primary_tier_count'] ?? null),
            'bow' => $geometry['bow'] ?? null,
            'hull' => $geometry['hull'] ?? null,
            'stern' => $this->legacyStern($geometry['stern'] ?? null),
            'superstructure' => $geometry['superstructure'] ?? null,
            'openings' => $this->legacyOpenings($geometry['openings'] ?? null),
            'hull_material' => $materials['hull_material'] ?? $this->materialText($materials['hull'] ?? null, 'material'),
            'superstructure_material' => $materials['superstructure_material'] ?? $this->materialText($materials['superstructure'] ?? null, 'material'),
            'hull_colour' => $materials['hull_colour'] ?? $this->materialText($materials['hull'] ?? null, 'colour'),
            'boot_stripe_colour' => $materials['boot_stripe_colour'] ?? null,
            'superstructure_colour' => $materials['superstructure_colour'] ?? $this->materialText($materials['superstructure'] ?? null, 'colour'),
            'glazing_type' => $materials['glazing_type'] ?? $this->materialText($materials['glazing'] ?? null, 'type'),
        ];

        $missing = array_keys(array_filter(
            $identity,
            static fn (mixed $value): bool => $value === null || $value === [],
        ));

        if ($missing !== []) {
            throw new \RuntimeException(
                'Canonical concept cannot compile to Python prompt; missing identity slots: '
                .implode(', ', $missing)
            );
        }

        $relationships = $this->legacyFormRelationships($concept['form_relationships'] ?? []);
        $missingRelationships = array_keys(array_filter(
            $relationships,
            static fn (mixed $value): bool => ! is_string($value) || trim($value) === '',
        ));

        if ($missingRelationships !== []) {
            throw new \RuntimeException(
                'Canonical concept cannot compile to Python prompt; missing form_relationships: '
                .implode(', ', $missingRelationships)
            );
        }

        return [
            'design_thesis' => (string) ($concept['design_thesis']['text'] ?? ''),
            'design_identity' => $identity,
            'form_relationships' => $relationships,
            'signature_features' => [],
            'decisions' => $this->legacyDecisions($concept['provenance'] ?? []),
        ];
    }

    private function materialText(mixed $material, string $field): ?string
    {
        if (is_string($material) && trim($material) !== '') {
            return $field === 'material' || $field === 'type' ? $material : null;
        }

        if (is_array($material) && is_string($material[$field] ?? null) && trim($material[$field]) !== '') {
            return $material[$field];
        }

        return null;
    }

    /**
     * @param  mixed  $relationships
     * @return array<string, mixed>
     */
    private function legacyFormRelationships(mixed $relationships): array
    {
        if (! is_array($relationships)) {
            return [];
        }

        return [
            'governing_line' => $relationships['governing_line']
                ?? ($relationships['hull_to_superstructure'] ?? null),
            'massing_rhythm' => $relationships['massing_rhythm']
                ?? ($relationships['tier_progression'] ?? null),
            'feature_integration' => $relationships['feature_integration']
                ?? ($relationships['bow_to_midbody'] ?? ($relationships['midbody_to_stern'] ?? null)),
        ];
    }

    private function legacyStern(mixed $stern): mixed
    {
        if (! is_array($stern)) {
            return $stern;
        }

        if (isset($stern['type']) && ! isset($stern['transom'])) {
            $stern['transom'] = $stern['type'];
        }

        return $stern;
    }

    private function legacyOpenings(mixed $openings): mixed
    {
        if (! is_array($openings)) {
            return $openings;
        }

        if (isset($openings['superstructure_bands']) && ! isset($openings['aperture_bands'])) {
            $openings['aperture_bands'] = $openings['superstructure_bands'];
        }

        if (isset($openings['language']) && ! isset($openings['distribution'])) {
            $openings['distribution'] = $openings['language'];
        }

        if (isset($openings['configuration']) && ! isset($openings['surface_relationship'])) {
            $openings['surface_relationship'] = $openings['configuration'];
        }

        return $openings;
    }

    /**
     * @param  mixed  $provenance
     * @return list<array<string, mixed>>
     */
    private function legacyDecisions(mixed $provenance): array
    {
        if (! is_array($provenance)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static function (mixed $item): ?array {
                if (! is_array($item)) {
                    return null;
                }

                $target = trim((string) ($item['target_path'] ?? ''));

                if ($target === '') {
                    return null;
                }

                return [
                    'aspect' => $target,
                    'area' => $target,
                    'decision' => $target,
                    'provenance' => (string) ($item['origin'] ?? ''),
                ];
            },
            $provenance,
        )));
    }

    /** @return array{prompt:string,prompt_sha256:string,stage:string,viewpoint:string,size:string,compiled_at:string,identity_id:?string,identity_hash:?string,identity_version:?int}|null */
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
            || ImageSize::tryFrom($preview['size']) === null
            || ImageModel::tryFrom((string) ($preview['lineage']['model'] ?? '')) === null) {
            return null;
        }

        return $preview;
    }

    public function storeAnchorPromptPreview(
        string $projectId,
        AnchorStage $stage,
        Viewpoint $viewpoint,
        ImageSize $size,
        CompiledAnchorPrompt $compiled,
        array $concept,
    ): bool {
        $project = $this->videoProjectRepository->getById($projectId);

        if ($project === null) {
            return false;
        }

        $metadata = $project->metadata_json ?? [];
        $identity = $this->identityStore->freezeFromConcept($project->id, $concept);

        $metadata['anchor_prompt_preview'] = [
            'prompt' => $compiled->prompt,
            'prompt_sha256' => $compiled->promptHash,
            'negative_prompt' => $compiled->negativePrompt,
            'native_controls' => $compiled->nativeControls,
            'lineage' => $compiled->lineage(),
            'identity_id' => $identity?->id,
            'identity_hash' => $identity?->identity_hash,
            'identity_version' => $identity?->version,
            'stage' => $stage->value,
            'viewpoint' => $viewpoint->value,
            'size' => $size->value,
            'compiled_at' => now()->toIso8601String(),
        ];

        $project->metadata_json = $metadata;

        return $project->save();
    }

    /**
     * Trang thai cua chang ANCHOR_PROMPT. Bo qua `$matchesInput` vi cau hoi o
     * day la "co luot nao dang chay khong", khong phai "co khop dau vao khong".
     *
     * @return array{running: bool, error: ?string}
     */
    public function latestAnchorPrompt(string $projectId): array
    {
        [$latest] = $this->stageStore->latestStageForProject(
            $projectId,
            PlanningStageName::ANCHOR_PROMPT,
            [],
        );

        return [
            'running' => $latest?->status === VideoPlanningStageStatus::RUNNING->value
                && $latest->lease_expires_at?->isFuture() === true,
            'error' => $latest?->status === VideoPlanningStageStatus::FAILED->value
                ? $latest->error_message
                : null,
        ];
    }

    /** @return array{0: bool, 1: string} */
    public function resetConcept(string $projectId): array
    {
        [$latest] = $this->stageStore->latestStageForProject(
            $projectId,
            PlanningStageName::ANCHOR_PROMPT,
            [],
        );

        if ($latest === null) {
            return [false, 'Chua co luot viet prompt nao'];
        }

        return $this->stageStore->releaseClaim($latest->id, 'Nguoi dung reset thu cong')
            ? [true, 'ok']
            : [false, 'Luot nay khong con giu claim — khong co gi de reset'];
    }

    /** @param  array<string, mixed>  $output */
    private function isCanonicalDesignSpec(array $output): bool
    {
        return is_string($output['schema_version'] ?? null)
            && is_string($output['object_type'] ?? null)
            && is_array($output['identity'] ?? null)
            && is_array($output['dimensions'] ?? null)
            && is_array($output['permanent_geometry'] ?? null)
            && is_array($output['invariants'] ?? null);
    }

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
            'design_spec' => [],
            'meta' => [],
            'provenance_summary' => null,
            'frozen_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $concept
     * @return array<string, mixed>
     */
    private function displayIdentityFromCanonical(array $concept): array
    {
        return array_filter([
            'object_type' => $concept['object_type'] ?? null,
            'subject_class' => $concept['identity']['subject_class'] ?? null,
            'length_m' => $concept['dimensions']['length_m'] ?? null,
            'beam_m' => $concept['dimensions']['beam_m'] ?? null,
            'length_to_beam_ratio' => $concept['dimensions']['length_to_beam_ratio'] ?? null,
            'draft_m' => $concept['dimensions']['draft_m'] ?? null,
            'bow' => $concept['permanent_geometry']['bow'] ?? null,
            'hull' => $concept['permanent_geometry']['hull'] ?? null,
            'stern' => $concept['permanent_geometry']['stern'] ?? null,
            'superstructure' => $concept['permanent_geometry']['superstructure'] ?? null,
            'openings' => $concept['permanent_geometry']['openings'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /** @return array<string, mixed> */
    private function canonicalConceptStageInput(VideoProject $project, string $objectType): array
    {
        return [
            'article_id' => $project->article->id,
            'object_type' => $objectType,
            'content_hash' => (string) ($project->article->content_hash ?? ''),
            'article_sha256' => hash('sha256', json_encode([
                'title' => (string) $project->article->title,
                'content' => (string) $project->article->content,
                'source_url' => (string) ($project->article->source_url ?? ''),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'concept_flow' => 'canonical_parts_1_8',
            'canonical_prompt_version' => (string) config('canonical_concept.prompt_version', 'concept-v1'),
            'canonical_schema_version' => (string) config('canonical_concept.schema.version', '1.0'),
            'canonical_model' => $this->conceptModel(),
        ];
    }

    private function conceptProvider(): string
    {
        return (string) config('canonical_concept.provider', 'anthropic');
    }

    private function conceptModel(): string
    {
        return (string) config(
            'canonical_concept.'.$this->conceptProvider().'.model'
        );
    }

    private function rawArticleFromModel(\App\Models\Article $article): RawArticle
    {
        return new RawArticle(
            id: (string) $article->id,
            title: (string) $article->title,
            html: (string) $article->content,
            metadata: array_filter([
                'source_url' => $article->source_url,
                'source_title' => $article->source_title,
                'source_name' => $article->source_name,
                'published_at' => $article->published_at?->toIso8601String(),
            ], static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== ''),
        );
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
            'category' => (string) ($project->article->category?->slug ?? ''),
            'title' => $project->article->title,
            'content' => (string) $project->article->content,
        ];
    }

    /** @return array{0: ?int, 1: string} [$sceneCount, $reason] */
    public function planScenes(string $projectId, ?string $actorId, bool $force = false): array
    {
        $actor = $actorId === null ? null : Admin::find($actorId);

        if ($actor === null) {
            return [null, 'project_not_found'];
        }

        $project = $this->videoProjectRepository->getById($projectId);

        if ($project === null || Gate::forUser($actor)->denies('update', $project)) {
            return [null, 'project_not_found'];
        }

        $brief = $this->stageStore->latestOutputForProject($projectId, PlanningStageName::INSPIRATION);

        if (! is_array($brief) || $brief === []) {
            return [null, 'no_inspiration_brief'];
        }

        $anchor = $this->designImageStore->approvedAnchorFor($projectId);

        if ($anchor === null) {
            return [null, 'no_approved_anchor'];
        }

        $category = (string) ($project->article->category?->slug ?? '');
        $version = config("video.scene_plan.profiles.{$category}");

        if (! is_string($version) || $version === '') {
            return [null, 'no_scene_profile'];
        }

        $profile = SceneProfile::load((string) config('video.scene_plan.profile_dir'), $version);

        $summary = $this->identitySummary(
            (string) ($anchor->prompt_spec_json['prompt'] ?? ''),
            $profile->subjectClass,
        );

        if ($summary === null) {
            return [null, 'identity_summary_unreadable'];
        }

        $author = app(ScenePlanAuthor::class);
        $provider = (string) config('canonical_concept.provider');
        $max = $author->maxScenes();

        $reviewEnabled = (bool) config('video.scene_plan.review.enabled');
        $maxRounds = (int) config('video.scene_plan.review.max_rounds');
        $reviewer = null;

        if ($reviewEnabled) {
            if ($maxRounds < 1) {
                return [null, 'scene_review_misconfigured'];
            }

            $reviewer = app(ScenePlanReviewer::class);

            if ($reviewer->maxTokens() < 1) {
                return [null, 'scene_review_misconfigured'];
            }
        }

        $article = [
            'title' => (string) ($project->article->title ?? ''),
            'summary' => Str::limit((string) ($project->article->content ?? ''), 4000, ''),
        ];

        $requirements = $this->planningRequirements($profile, $max);

        try {
            $skillHash = $author->skillHash();
        } catch (ScenePlanException $e) {
            $this->quietLog('scene-plan: author skill unreadable', $e, ['project_id' => $projectId]);

            return [null, 'scene_plan_misconfigured'];
        }

        $reviewSkill = null;

        try {
            $reviewSkill = $reviewer?->preflight();
            $reviewSkillHash = $reviewSkill === null ? null : $reviewer->skillHash($reviewSkill);
        } catch (ScenePlanException $e) {
            $this->quietLog('scene-plan: review skill unreadable', $e, ['project_id' => $projectId]);

            return [null, 'scene_review_misconfigured'];
        }

        $claimInput = [
            'article_hash' => $this->digest($article),
            'brief_hash' => $this->digest($brief),
            'identity_summary_hash' => $this->digest($summary),
            'anchor_identity_sha256' => (string) $anchor->prompt_sha256,
            'profile_version' => $profile->version,
            'profile_sha256' => $profile->sha256,
            'requirements' => $requirements,
            'requirements_hash' => $this->digest($requirements),
            'skill_hash' => $skillHash,
            'prompt_version' => $author->promptVersion(),
            'scene_contract_version' => ScenePlanAuthor::SCENE_CONTRACT_VERSION,
            'preservation_version' => ScenePreservationPrompt::VERSION,
            'provider' => $provider,
            'model' => $author->model(),
            'review_enabled' => $reviewEnabled,
            'review_max_rounds' => $reviewEnabled ? $maxRounds : null,
            'review_prompt_version' => $reviewer?->promptVersion(),
            'review_skill_hash' => $reviewSkillHash,
            'review_model' => $reviewer?->model(),
        ];

        $claimMeta = ['pricing' => 'unpriced'];

        [$claimed, $token, $reason] = $this->stageStore->claimProjectStage(
            $projectId, PlanningStageName::SCENE_PLAN, $claimInput, $force, $claimMeta,
        );

        if ($token === null) {
            return [null, $reason === 'already_succeeded' ? 'scene_plan_unchanged' : 'scene_plan_running'];
        }

        $raw = '';
        $totals = [];
        $authorUsage = [];
        $authorModel = null;
        $trail = null;
        $current = [];
        $warnings = [];

        try {
            $authorAttempted = false;

            try {
                $result = $author->plan([
                    'article' => $article,
                    'inspiration_brief' => $brief,
                    'identity_summary' => $summary,
                    'planning_requirements' => $requirements,
                ], $profile, static function () use (&$authorAttempted): void {
                    $authorAttempted = true;
                });

                $raw = $result->raw;
                $authorModel = $result->authorModel;
                $authorUsage = [
                    'provider_model' => $result->authorModel,
                    'tokens_in' => $result->inputTokens,
                    'tokens_out' => $result->outputTokens,
                    'thinking_tokens' => $result->reasoningTokens,
                ];
            } catch (\Throwable $e) {
                if ($e instanceof ScenePlanException) {
                    $raw = $e->raw;
                    $authorUsage = $e->usage;
                    $authorModel = is_string($e->usage['provider_model'] ?? null)
                        ? $e->usage['provider_model']
                        : null;
                }

                $totals = $this->addUsage($totals, $authorUsage, $authorAttempted);

                throw $e;
            }

            $totals = $this->addUsage($totals, $authorUsage, $authorAttempted);

            [$current, $warnings] = $this->validatedScenes($result->scenes, $profile, $requirements, $max);

            $trail = [
                'status' => $reviewEnabled ? 'needs_review' : 'unreviewed',
                'reason' => $reviewEnabled ? 'rounds_exhausted' : 'review_disabled',
                'reviewed_plan_sha256' => null,
                'plan_hashes' => [$this->planHash($current)],
                'rounds' => [],
            ];

            $claimLost = ! $this->stageStore->recordProgress(
                $claimed->id, $token,
                $this->planOutput($current, $warnings, $trail, $authorUsage, $totals, $raw),
            );

            for ($round = 1; ! $claimLost && $reviewEnabled && $round <= $maxRounds; $round++) {
                $startedAt = microtime(true);
                $planIn = $this->planHash($current);
                $record = ['round' => $round, 'plan_sha256_in' => $planIn];
                $review = null;
                $stop = null;
                $attempted = false;
                $usage = [];

                try {
                    $review = $reviewer->review(
                        $this->reviewInput(
                            $article, $brief, $summary, $profile, $requirements, $current, $warnings,
                        ),
                        $profile,
                        $max,
                        (string) $reviewSkill,
                        static function () use (&$attempted): void {
                            $attempted = true;
                        },
                    );

                    $usage = [
                        'provider_model' => $review->reviewerModel,
                        'tokens_in' => $review->inputTokens,
                        'tokens_out' => $review->outputTokens,
                        'thinking_tokens' => $review->reasoningTokens,
                    ];

                    $record += [
                        'completion_attempted' => true,
                        'verdict' => $review->verdict,
                        'findings' => $review->findings,
                        'raw' => $this->storableText($review->raw),
                        'usage' => $usage,
                    ];
                } catch (\Throwable $e) {
                    $usage = $e instanceof ScenePlanException ? $e->usage : [];

                    $record += [
                        'completion_attempted' => $attempted,
                        'error' => [
                            'class' => $e::class,
                            'message' => $this->storableText($e->getMessage()),
                        ],
                        'raw' => $e instanceof ScenePlanException && $e->raw !== ''
                            ? $this->storableText($e->raw)
                            : null,
                        'usage' => $usage === [] ? null : $usage,
                    ];

                    $stop = $attempted ? 'review_call_failed' : 'review_not_attempted';
                }

                $totals = $this->addUsage($totals, $usage, $attempted);

                if ($review !== null) {
                    try {
                        [$stop, $current, $warnings, $record] = $this->applyReview(
                            $review, $current, $warnings, $record,
                            $profile, $requirements, $max, $round, $maxRounds,
                            $trail['plan_hashes'], $article,
                        );
                    } catch (\Throwable $e) {
                        $record['apply_error'] = [
                            'class' => $e::class,
                            'message' => $this->storableText($e->getMessage()),
                        ];
                        $record['duration_ms'] = $this->elapsed($startedAt);
                        $trail['rounds'][] = $record;
                        $trail['status'] = 'needs_review';
                        $trail['reason'] = 'review_apply_failed';
                        $trail['reviewed_plan_sha256'] = null;

                        $this->bestEffortProgress($claimed->id, $token, fn (): array => $this->planOutput(
                            $current, $warnings, $trail, $authorUsage, $totals, $raw,
                        ));

                        throw $e;
                    }
                }

                $record['duration_ms'] = $this->elapsed($startedAt);
                $trail['rounds'][] = $record;

                if (isset($record['plan_sha256_out'])) {
                    $trail['plan_hashes'][] = $record['plan_sha256_out'];
                }

                if ($stop !== null) {
                    $this->settle($trail, $stop, $planIn);
                }

                if (! $this->stageStore->recordProgress(
                    $claimed->id, $token,
                    $this->planOutput($current, $warnings, $trail, $authorUsage, $totals, $raw),
                )) {
                    $claimLost = true;

                    break;
                }

                if ($stop !== null) {
                    break;
                }
            }

            $revision = $claimLost ? null : $this->commitScenePlan(
                $projectId, $current, $warnings, $trail, $authorUsage, $totals,
                $author->promptVersion(), $claimed->id, $token, $raw,
                $this->usageColumn($totals, $provider, $author->promptVersion(), $authorModel),
            );

            if ($revision === null) {
                return $this->orphanScenePlan(
                    $projectId, $claimInput, $claimMeta, $claimed->id, $token,
                    $current, $warnings, $trail, $authorUsage,
                    $this->usageColumn($totals, $provider, $author->promptVersion(), $authorModel),
                    $totals, $raw,
                );
            }
        } catch (\Throwable $e) {
            if ($e instanceof ScenePlanException && $raw === '') {
                $raw = $e->raw;
            }

            if ($trail !== null) {
                $trail['status'] = 'needs_review';
                $trail['reason'] = 'planning_failed';
                $trail['reviewed_plan_sha256'] = null;

                $this->bestEffortProgress($claimed->id, $token, fn (): array => $this->planOutput(
                    $current, $warnings, $trail, $authorUsage, $totals, $raw,
                ));
            }

            $usageColumn = $this->usageColumn($totals, $provider, $author->promptVersion(), $authorModel);
            $wrote = null;
            $orphaned = null;

            try {
                $wrote = $this->stageStore->finishFailed(
                    $claimed->id, $token, $e->getMessage(), $usageColumn, $raw,
                );
            } catch (\Throwable $ledgerError) {
                $this->quietLog('scene-plan: finishFailed threw', $ledgerError);
            }

            if ($wrote !== true) {
                try {
                    $orphaned = $this->stageStore->recordOrphanAttempt(
                        $projectId, PlanningStageName::SCENE_PLAN,
                        $claimInput, $claimMeta,
                        $claimed->id, $token,
                        $e->getMessage(), $usageColumn, $raw,
                        $trail === null ? [] : $this->safeOutput(fn (): array => $this->planOutput(
                            $current, $warnings, $trail, $authorUsage, $totals, $raw,
                        )),
                    );
                } catch (\Throwable $orphanError) {
                    $this->quietLog('scene-plan: recordOrphanAttempt threw', $orphanError);
                }
            }

            $this->quietLog('scene-plan: failed', $e, [
                'project_id' => $projectId,
                'claim_kept' => $wrote,
                'rounds' => $trail === null ? 0 : count($trail['rounds']),
            ]);

            return [null, $wrote === true || $orphaned !== null
                ? 'scene_plan_failed'
                : 'scene_plan_failed_unrecorded'];
        }

        Log::info('scene-plan: written', [
            'project_id' => $projectId,
            'revision' => $revision,
            'scenes' => count($current),
            'warnings' => count($warnings),
            'review_status' => $trail['status'],
            'review_reason' => $trail['reason'],
            'calls' => $totals['calls'] ?? 0,
        ]);

        return [count($current), $trail['status'] === 'passed' ? 'ok' : 'ok_needs_review'];
    }

    /**
     * @return array{revision: int, scenes: list<array<string, mixed>>, warnings: list<string>,
     *               records: int, unpriced: bool}
     */
    public function latestScenePlan(string $projectId): array
    {
        $ledger = $this->stageStore->attemptSummaryForProject($projectId, PlanningStageName::SCENE_PLAN);

        $revision = (int) VideoRenderScene::query()
            ->where('project_id', $projectId)
            ->max('revision');

        if ($revision === 0) {
            return [
                'revision' => 0, 'scenes' => [], 'warnings' => [],
                'profile_notice' => null, 'preservation_notice' => null,
                'review' => $this->reviewForRevision(null),
            ] + $ledger;
        }

        $scenes = VideoRenderScene::query()
            ->where('project_id', $projectId)
            ->where('revision', $revision)
            ->orderBy('scene_index')
            ->get();

        $stage = $this->stageStore->stageForProjectRevision(
            $projectId, PlanningStageName::SCENE_PLAN, $revision,
        );

        [$profile, $notice] = $this->profileForRevision($stage);
        [$preservation, $preservationNotice] = $this->preservationForRevision($stage);

        $warnings = is_array($stage?->output_json)
            ? (array) ($stage->output_json['warnings'] ?? [])
            : [];

        return [
            'revision' => $revision,
            'scenes' => $scenes
                ->map(fn (VideoRenderScene $scene) => $this->sceneView($scene, $profile, $preservation))
                ->all(),
            'warnings' => array_values($warnings),
            'profile_notice' => $notice,
            'preservation_notice' => $preservationNotice,
            'review' => $this->reviewForRevision($stage),
        ] + $ledger;
    }

    /**
     * Tra `null` khi claim mat giua chung: scene da chen bi rollback, va nguoi
     * goi phai di duong orphan. Moi `ScenePlanException` khac van noi len.
     *
     * @param  list<array<string, mixed>>  $scenes
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $trail
     * @param  array<string, mixed>  $authorUsage
     * @param  array<string, mixed>  $totals
     * @param  array<string, mixed>  $usageColumn
     */
    private function commitScenePlan(
        string $projectId,
        array $scenes,
        array $warnings,
        array $trail,
        array $authorUsage,
        array $totals,
        string $promptVersion,
        string $stageId,
        string $claimToken,
        string $raw,
        array $usageColumn,
    ): ?int {
        $lost = false;

        try {
            return DB::transaction(function () use (
                $projectId, $scenes, $warnings, $trail, $authorUsage, $totals,
                $promptVersion, $stageId, $claimToken, $raw, $usageColumn, &$lost
            ) {
                VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();

                $revision = ((int) VideoRenderScene::query()
                    ->where('project_id', $projectId)
                    ->max('revision')) + 1;

                foreach ($scenes as $index => $scene) {
                    VideoRenderScene::create([
                        'project_id' => $projectId,
                        'revision' => $revision,
                        'scene_index' => $index + 1,
                        'scene_code' => $scene['scene_code'],
                        'scene_type' => $scene['phase'],
                        'milestone_keys' => $scene['milestone_keys'],
                        'basis' => $scene['basis'],
                        'title' => $scene['title'],
                        'purpose' => $scene['purpose'],
                        'state_json' => [
                            'state_before' => $scene['state_before'],
                            'scene_state' => $scene['scene_state'],
                        ],
                        'delta_prompt' => $scene['delta'],
                        'prompt_version' => $promptVersion,
                        'transition_mode' => $scene['transition_mode'],
                        'continuity_group' => $scene['continuity_group'],
                        'source_scene_code' => $scene['source_scene_code'] !== ''
                            ? $scene['source_scene_code']
                            : null,
                        'camera_change_reason' => $scene['camera_change_reason'] !== ''
                            ? $scene['camera_change_reason']
                            : null,
                        'video_plan_json' => $scene['video'] + ['camera_mode' => $scene['camera_mode']],
                    ]);
                }

                $recorded = $this->stageStore->finishSucceeded(
                    $stageId,
                    $claimToken,
                    $raw,
                    $this->planOutput($scenes, $warnings, $trail, $authorUsage, $totals, $raw, $revision),
                    $usageColumn,
                );

                if (! $recorded) {
                    $lost = true;

                    throw new ScenePlanException(
                        'Scene plan claim was lost before the ledger could record it.'
                    );
                }

                return $revision;
            });
        } catch (ScenePlanException $e) {
            if (! $lost) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $claimInput
     * @param  array<string, mixed>  $claimMeta
     * @param  list<array<string, mixed>>  $scenes
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $trail
     * @param  array<string, mixed>  $authorUsage
     * @param  array<string, mixed>  $usageColumn
     * @param  array<string, mixed>  $totals
     * @return array{0: null, 1: string}
     */
    private function orphanScenePlan(
        string $projectId,
        array $claimInput,
        array $claimMeta,
        string $stageId,
        string $claimToken,
        array $scenes,
        array $warnings,
        array $trail,
        array $authorUsage,
        array $usageColumn,
        array $totals,
        string $raw,
    ): array {
        $trail['status'] = 'needs_review';
        $trail['reason'] = 'claim_lost';
        $trail['reviewed_plan_sha256'] = null;

        $orphaned = null;

        try {
            $orphaned = $this->stageStore->recordOrphanAttempt(
                $projectId, PlanningStageName::SCENE_PLAN, $claimInput, $claimMeta,
                $stageId, $claimToken, 'Scene plan claim was lost.',
                $usageColumn, $raw,
                $this->safeOutput(fn (): array => $this->planOutput(
                    $scenes, $warnings, $trail, $authorUsage, $totals, $raw,
                )),
            );
        } catch (\Throwable $e) {
            $this->quietLog('scene-plan: orphan write threw', $e, ['stage_id' => $stageId]);
        }

        return [null, $orphaned !== null ? 'scene_plan_claim_lost' : 'scene_plan_failed_unrecorded'];
    }

    /**
     * Muc patch TRUNG MA nhung GIONG HET (sau khi sap khoa object, giu nguyen
     * thu tu array va noi dung chuoi) duoc gop lam mot va dem vao
     * `merged_duplicate_patches`. Trung ma ma KHAC noi dung thi tu choi ca
     * phan hoi — do la mau thuan that, khong duoc chon ban dau hay ban cuoi.
     * Gop xong van chay du validator: gop trung khong lam ban va hop le.
     *
     * `plan_sha256_out` chi duoc dat tren duong NHAN patch, nen no la co duy
     * nhat de noi lich su hash. `patch_invalid`, `no_progress` va `oscillated`
     * khong bao gio cham toi no.
     *
     * @param  list<array<string, mixed>>  $current
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $requirements
     * @param  list<string>  $seen
     * @return array{0: ?string, 1: list<array<string, mixed>>, 2: list<string>, 3: array<string, mixed>}
     */
    private function applyReview(
        ScenePlanReviewResult $review,
        array $current,
        array $warnings,
        array $record,
        SceneProfile $profile,
        array $requirements,
        int $maxScenes,
        int $round,
        int $maxRounds,
        array $seen,
        array $article,
    ): array {
        $codes = array_map(static fn (array $scene) => (string) $scene['scene_code'], $current);

        [$sound, $blocking] = $this->checkedFindings($review->findings, $codes, $current, $article);

        if (! $sound) {
            return ['review_response_incoherent', $current, $warnings, $record];
        }

        $seenPatch = [];
        $patch = [];
        $merged = 0;

        foreach ($review->patch as $item) {
            $code = is_array($item) ? ($item['scene_code'] ?? null) : null;

            if (! is_string($code) || ! in_array($code, $codes, true)) {
                return ['review_response_incoherent', $current, $warnings, $record];
            }

            $digest = $this->digest((array) $this->canonical($item));

            if (array_key_exists($code, $seenPatch)) {
                if ($seenPatch[$code] !== $digest) {
                    return ['review_response_incoherent', $current, $warnings, $record];
                }

                $merged++;

                continue;
            }

            $seenPatch[$code] = $digest;
            $patch[] = $item;
        }

        if ($merged > 0) {
            $record['merged_duplicate_patches'] = $merged;
        }

        $empty = $patch === [];

        $incoherent = match ($review->verdict) {
            'pass' => $blocking > 0 || ! $empty,
            'requires_replan' => $blocking < 1 || ! $empty,
            'revise' => $blocking < 1 || $empty,
            default => true,
        };

        if ($incoherent) {
            return ['review_response_incoherent', $current, $warnings, $record];
        }

        if ($review->verdict !== 'revise') {
            return [
                $review->verdict === 'pass' ? 'passed' : 'requires_replan',
                $current,
                $warnings,
                $record,
            ];
        }

        $next = $this->applyPatch($current, $patch);

        try {
            [$next, $nextWarnings] = $this->validatedScenes($next, $profile, $requirements, $maxScenes);
        } catch (ScenePlanException $e) {
            $record['patch_error'] = $this->storableText($e->getMessage());

            return ['patch_invalid', $current, $warnings, $record];
        }

        $out = $this->planHash($next);

        if ($out === $this->planHash($current)) {
            return ['no_progress', $current, $warnings, $record];
        }

        if (in_array($out, $seen, true)) {
            return ['oscillated', $current, $warnings, $record];
        }

        $record['plan_sha256_out'] = $out;
        $record['patched_codes'] = $this->changedCodes($current, $next);

        return [
            $round >= $maxRounds ? 'patched_but_unreviewed' : null,
            $next,
            $nextWarnings,
            $record,
        ];
    }

    /**
     * Khong tin `additionalProperties: false` cua provider: mot `severity` la
     * chuoi la se khong bang 'blocking' va lam `pass` lot qua.
     *
     * @param  list<mixed>  $findings
     * @param  list<string>  $codes
     * @return array{0: bool, 1: int}
     */
    private function checkedFindings(array $findings, array $codes, array $scenes, array $article): array
    {
        if (count($findings) > ScenePlanReviewer::MAX_FINDINGS) {
            return [false, 0];
        }

        $blocking = 0;

        foreach ($findings as $finding) {
            if (! is_array($finding) || array_is_list($finding)) {
                return [false, 0];
            }

            $keys = array_keys($finding);
            sort($keys);

            if ($keys !== ['evidence', 'fix', 'problem', 'rule', 'scene_code', 'severity']) {
                return [false, 0];
            }

            foreach (['fix', 'problem', 'rule', 'scene_code', 'severity'] as $field) {
                if (! is_string($finding[$field])) {
                    return [false, 0];
                }
            }

            if (! $this->checkedEvidence($finding['evidence'], $scenes, $article)) {
                return [false, 0];
            }

            if (! in_array($finding['scene_code'], $codes, true)
                || ! in_array($finding['rule'], ScenePlanReviewer::RULES, true)
                || ! in_array($finding['severity'], ScenePlanReviewer::SEVERITIES, true)) {
                return [false, 0];
            }

            foreach (['problem', 'fix'] as $field) {
                $length = mb_strlen(trim($finding[$field]));

                if ($length < 3 || $length > 500) {
                    return [false, 0];
                }
            }

            $blocking += $finding['severity'] === 'blocking' ? 1 : 0;
        }

        return [true, $blocking];
    }

    /**
     * Moi quote phai la CHUOI CON THAT cua truong no khai. Phep nay chi nang
     * nguong: reviewer khai dung ma ma mo ta sai noi dung van lot duoc. No chan
     * duoc mot thu cu the — vien dan mot cau khong ton tai.
     *
     * Quote rong bi tu choi: chuoi rong la chuoi con cua moi thu, de lot thi
     * chot nay mat tac dung hoan toan.
     *
     * @param  mixed  $evidence
     * @param  list<array<string, mixed>>  $scenes
     * @param  array<string, mixed>  $article
     */
    private function checkedEvidence($evidence, array $scenes, array $article): bool
    {
        if (! is_array($evidence)
            || ! array_is_list($evidence)
            || $evidence === []
            || count($evidence) > ScenePlanReviewer::MAX_EVIDENCE) {
            return false;
        }

        $byCode = [];

        foreach ($scenes as $scene) {
            $byCode[(string) $scene['scene_code']] = $scene;
        }

        foreach ($evidence as $item) {
            if (! is_array($item) || array_is_list($item)) {
                return false;
            }

            $keys = array_keys($item);
            sort($keys);

            if ($keys !== ['field', 'quote', 'scene_code', 'source']) {
                return false;
            }

            foreach ($keys as $key) {
                if (! is_string($item[$key])) {
                    return false;
                }
            }

            $quote = $this->squashed($item['quote']);

            if (mb_strlen($quote) < ScenePlanReviewer::MIN_QUOTE
                || mb_strlen($item['quote']) > ScenePlanReviewer::MAX_QUOTE) {
                return false;
            }

            if ($item['source'] === 'article') {
                if ($item['scene_code'] !== ''
                    || ! in_array($item['field'], ScenePlanReviewer::ARTICLE_FIELDS, true)
                    || ! is_string($article[$item['field']] ?? null)
                    || ! str_contains($this->squashed($article[$item['field']]), $quote)) {
                    return false;
                }

                continue;
            }

            if ($item['source'] !== 'scene'
                || ! in_array($item['field'], ScenePlanReviewer::SCENE_FIELDS, true)
                || ! isset($byCode[$item['scene_code']])) {
                return false;
            }

            $value = $this->sceneField($byCode[$item['scene_code']], $item['field']);

            if ($value === null || ! str_contains($this->squashed($value), $quote)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $scene */
    private function sceneField(array $scene, string $field): ?string
    {
        $value = str_starts_with($field, 'video.')
            ? ($scene['video'][substr($field, 6)] ?? null)
            : ($scene[$field] ?? null);

        return is_string($value) ? $value : null;
    }

    /**
     * Chi go khac biet ve khoang trang. KHONG ha chu thuong: hop dong doi
     * trich NGUYEN VAN, ha chu thuong la lang le noi long chinh dieu do.
     */
    private function squashed(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Thay TAI CHO theo chi so, nen so scene va thu tu bat bien — khong phai
     * nho mot luat, ma nho vong lap khong co duong chen phan tu.
     *
     * @param  list<array<string, mixed>>  $current
     * @param  list<array<string, mixed>>  $patch
     * @return list<array<string, mixed>>
     */
    private function applyPatch(array $current, array $patch): array
    {
        $byCode = [];

        foreach ($patch as $item) {
            $byCode[(string) $item['scene_code']] = $item;
        }

        foreach ($current as $index => $scene) {
            $code = (string) $scene['scene_code'];

            if (isset($byCode[$code])) {
                $current[$index] = $byCode[$code];
            }
        }

        return $current;
    }

    /**
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     * @return list<string>
     */
    private function changedCodes(array $before, array $after): array
    {
        $changed = [];

        foreach ($before as $index => $scene) {
            if ($this->digest($this->canonical($scene)) !== $this->digest($this->canonical($after[$index]))) {
                $changed[] = (string) $scene['scene_code'];
            }
        }

        return $changed;
    }

    /**
     * Sap khoa cua associative array, GIU thu tu list: `scenes` la trinh tu ke
     * va `milestone_keys` bi `checkMilestones()` bat phai theo thu tu profile.
     */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }

        if (! $isList) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function planShapeOf(VideoRenderScene $scene): array
    {
        $state = is_array($scene->state_json) ? $scene->state_json : [];
        $video = is_array($scene->video_plan_json) ? $scene->video_plan_json : [];

        return [
            'scene_code' => (string) $scene->scene_code,
            'title' => (string) $scene->title,
            'purpose' => (string) $scene->purpose,
            'phase' => (string) $scene->scene_type,
            'milestone_keys' => array_values((array) $scene->milestone_keys),
            'basis' => (string) $scene->basis,
            'state_before' => (string) ($state['state_before'] ?? ''),
            'scene_state' => (string) ($state['scene_state'] ?? ''),
            'transition_mode' => (string) $scene->transition_mode,
            'continuity_group' => (string) $scene->continuity_group,
            'source_scene_code' => (string) ($scene->source_scene_code ?? ''),
            'camera_change_reason' => (string) ($scene->camera_change_reason ?? ''),
            'camera_mode' => (string) ($video['camera_mode'] ?? ''),
            'delta' => (string) $scene->delta_prompt,
            'video' => [
                'action' => (string) ($video['action'] ?? ''),
                'preserve' => (string) ($video['preserve'] ?? ''),
                'end_state' => (string) ($video['end_state'] ?? ''),
            ],
        ];
    }

    /**
     * @return array{0: bool, 1: string, 2: ?VideoPlanningStage, 3: ?VideoRenderScene}
     */
    private function sceneRenderGate(VideoRenderScene $scene): array
    {
        $stage = $this->stageStore->stageForProjectRevision(
            (string) $scene->project_id,
            PlanningStageName::SCENE_PLAN,
            (int) $scene->revision,
        );

        if ($stage === null || ! is_array($stage->input_json)) {
            return [false, 'scene_plan_unverifiable', $stage, null];
        }

        $contract = $stage->input_json['scene_contract_version'] ?? null;

        if ($contract === null) {
            return [false, 'scene_plan_has_no_continuity_contract', $stage, null];
        }

        if ($contract !== ScenePlanAuthor::SCENE_CONTRACT_VERSION) {
            return [false, 'scene_contract_unsupported', $stage, null];
        }

        $review = $this->reviewForRevision($stage);

        if ($review['status'] !== 'passed') {
            return [false, 'scene_plan_not_reviewed', $stage, null];
        }

        $rows = VideoRenderScene::query()
            ->where('project_id', $scene->project_id)
            ->where('revision', $scene->revision)
            ->orderBy('scene_index')
            ->get();

        $verified = $rows->firstWhere('id', $scene->id);

        if ($verified === null) {
            return [false, 'scene_plan_unverifiable', $stage, null];
        }

        $live = $rows->map(fn (VideoRenderScene $row) => $this->planShapeOf($row))->all();

        if (! hash_equals((string) $review['reviewed_plan_sha256'], $this->planHash($live))) {
            return [false, 'scene_plan_changed_since_review', $stage, null];
        }

        [$preservation] = $this->preservationForRevision($stage);

        return $preservation === null
            ? [false, 'preservation_unknown', $stage, null]
            : [true, 'ok', $stage, $verified];
    }

    /** @param list<array<string, mixed>> $scenes */
    private function planHash(array $scenes): string
    {
        return $this->digest(array_map(fn (array $scene) => $this->canonical($scene), $scenes));
    }

    private function ownedScene(string $projectId, ?string $actorId, string $sceneId): ?VideoRenderScene
    {
        $actor = $actorId === null ? null : Admin::find($actorId);

        if ($actor === null) {
            return null;
        }

        $project = $this->videoProjectRepository->getById($projectId);

        if ($project === null || Gate::forUser($actor)->denies('update', $project)) {
            return null;
        }

        return VideoRenderScene::query()
            ->whereKey($sceneId)
            ->where('project_id', $projectId)
            ->first();
    }

    private function sourceRoleForMode(string $mode): string
    {
        return match ($mode) {
            ScenePreservationPrompt::HARD_CUT => 'anchor',
            ScenePreservationPrompt::CONTINUATION => 'source_keyframe',
            default => '',
        };
    }

    private function manifestEntryShaped(mixed $entry): bool
    {
        if (! is_array($entry)
            || array_keys((array) $this->canonical($entry))
                !== ['artifact_id', 'candidate_id', 'position', 'role', 'sha256']) {
            return false;
        }

        return is_int($entry['position'])
            && $entry['position'] >= 0
            && in_array($entry['role'], self::MANIFEST_ROLES, true)
            && is_string($entry['artifact_id']) && $entry['artifact_id'] !== ''
            && is_string($entry['candidate_id']) && $entry['candidate_id'] !== ''
            && is_string($entry['sha256'])
            && preg_match('/^[0-9a-f]{64}$/', $entry['sha256']) === 1;
    }

    /**
     * @param  list<string>  $imageTypes
     * @return array{0: bool, 1: string}
     */
    private function sourcePairIntact(
        string $projectId,
        VideoArtifact $artifact,
        VideoDesignImage $candidate,
        array $imageTypes,
        ?string $expectedSha,
    ): array {
        if ((string) $artifact->project_id !== $projectId
            || (string) $candidate->project_id !== $projectId) {
            return [false, 'source_outside_project'];
        }

        if ((string) $artifact->design_image_id !== (string) $candidate->id) {
            return [false, 'source_artifact_not_in_candidate'];
        }

        if (! in_array($candidate->image_type, $imageTypes, true)) {
            return [false, 'source_wrong_image_type'];
        }

        if ($expectedSha !== null && ! hash_equals($expectedSha, (string) $artifact->sha256)) {
            return [false, 'source_artifact_sha_mismatch'];
        }

        return [true, 'ok'];
    }

    /**
     * @param  list<string>  $imageTypes
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    private function freshApprovedSource(
        string $projectId,
        ?string $artifactId,
        array $imageTypes,
        string $role,
    ): array {
        $artifact = $artifactId === null || trim($artifactId) === ''
            ? null
            : VideoArtifact::query()->whereKey($artifactId)->first();

        if ($artifact === null) {
            return [null, 'source_artifact_missing'];
        }

        $candidate = VideoDesignImage::query()->whereKey($artifact->design_image_id)->first();

        if ($candidate === null) {
            return [null, 'source_artifact_not_in_candidate'];
        }

        [$ok, $why] = $this->sourcePairIntact($projectId, $artifact, $candidate, $imageTypes, null);

        if (! $ok) {
            return [null, $why];
        }

        if ($candidate->status !== DesignImageStatus::APPROVED->value) {
            return [null, 'source_not_approved'];
        }

        if ((string) $candidate->selected_artifact_id !== (string) $artifact->id) {
            return [null, 'source_not_the_selected_artifact'];
        }

        return [[
            'role' => $role,
            'position' => 0,
            'artifact_id' => (string) $artifact->id,
            'candidate_id' => (string) $candidate->id,
            'sha256' => (string) $artifact->sha256,
        ], 'ok'];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    private function snapshotSource(string $projectId, array $entry): array
    {
        if (! $this->manifestEntryShaped($entry)) {
            return [null, 'candidate_snapshot_unreadable'];
        }

        $types = self::ROLE_IMAGE_TYPES[$entry['role']] ?? null;

        if ($types === null) {
            return [null, 'candidate_snapshot_role_mismatch'];
        }

        $artifact = VideoArtifact::query()->whereKey($entry['artifact_id'])->first();
        $candidate = VideoDesignImage::query()->whereKey($entry['candidate_id'])->first();

        if ($artifact === null || $candidate === null) {
            return [null, 'source_artifact_missing'];
        }

        [$ok, $why] = $this->sourcePairIntact(
            $projectId, $artifact, $candidate, $types, $entry['sha256'],
        );

        return $ok ? [$entry, 'ok'] : [null, $why];
    }

    /**
     * @param  list<array<string, mixed>>  $manifest
     * @return array{0: bool, 1: string}
     */
    private function snapshotSourcesIntact(string $projectId, array $manifest): array
    {
        foreach ($manifest as $entry) {
            [$resolved, $why] = $this->snapshotSource($projectId, (array) $entry);

            if ($resolved === null) {
                return [false, $why];
            }
        }

        return [true, 'ok'];
    }

    /**
     * @return array{0: ?array<string, string>, 1: string}
     */
    private function lockedAnchor(string $projectId, int $revision): array
    {
        $key = 'lock:'.$projectId.':'.$revision;

        if ($this->sourceMemo !== null && array_key_exists($key, $this->sourceMemo)) {
            return $this->sourceMemo[$key];
        }

        $found = $this->readLockedAnchor($projectId, $revision);

        if ($this->sourceMemo !== null) {
            $this->sourceMemo[$key] = $found;
        }

        return $found;
    }

    /**
     * @return array{0: ?array<string, string>, 1: string}
     */
    private function readLockedAnchor(string $projectId, int $revision): array
    {
        $carrier = VideoRenderScene::query()
            ->where('project_id', $projectId)
            ->where('revision', $revision)
            ->where('transition_mode', ScenePreservationPrompt::HARD_CUT)
            ->whereIn('id', VideoDesignImage::query()
                ->where('project_id', $projectId)
                ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
                ->whereNotNull('render_scene_id')
                ->select('render_scene_id'))
            ->orderBy('scene_index')
            ->first();

        if ($carrier === null) {
            return [null, 'no_lock'];
        }

        $candidate = VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
            ->where('render_scene_id', $carrier->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        $spec = is_array($candidate?->prompt_spec_json) ? $candidate->prompt_spec_json : [];
        $entry = $spec['sources'][0] ?? null;

        if (! $this->manifestEntryShaped($entry)
            || $entry['role'] !== 'anchor'
            || $entry['position'] !== 0) {
            return [null, 'anchor_lock_unreadable'];
        }

        return [[
            'artifact_id' => $entry['artifact_id'],
            'candidate_id' => $entry['candidate_id'],
            'sha256' => $entry['sha256'],
        ], 'ok'];
    }

    /**
     * @param  array<string, string>  $lock
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    private function anchorSourceFromLock(string $projectId, array $lock): array
    {
        return $this->snapshotSource($projectId, $lock + ['role' => 'anchor', 'position' => 0]);
    }

    /** @return array{0: ?array<string, mixed>, 1: string} */
    private function proposedAnchorSource(string $projectId, ?VideoPlanningStage $stage): array
    {
        $key = 'anchor:'.$projectId;

        if ($this->sourceMemo !== null && array_key_exists($key, $this->sourceMemo)) {
            return $this->sourceMemo[$key];
        }

        $found = $this->readProposedAnchorSource($projectId, $stage);

        if ($this->sourceMemo !== null) {
            $this->sourceMemo[$key] = $found;
        }

        return $found;
    }

    /** @return array{0: ?array<string, mixed>, 1: string} */
    private function readProposedAnchorSource(string $projectId, ?VideoPlanningStage $stage): array
    {
        $planned = is_array($stage?->input_json)
            ? ($stage->input_json['anchor_identity_sha256'] ?? null)
            : null;

        if (! is_string($planned) || $planned === '') {
            return [null, 'planning_anchor_unknown'];
        }

        $anchor = $this->designImageStore->approvedAnchorFor($projectId);

        if ($anchor === null) {
            return [null, 'no_approved_anchor'];
        }

        if (! hash_equals($planned, (string) $anchor->prompt_sha256)) {
            return [null, 'anchor_changed_since_planning'];
        }

        return $this->freshApprovedSource(
            $projectId,
            (string) $anchor->selected_artifact_id,
            [DesignImageStore::ANCHOR_TYPE],
            'anchor',
        );
    }

    /** @return array{0: ?array<string, mixed>, 1: string} */
    private function anchorSourceFromConfirmation(
        string $projectId,
        ?VideoPlanningStage $stage,
        ?string $artifactId,
    ): array {
        if ($artifactId === null || trim($artifactId) === '') {
            return [null, 'anchor_not_confirmed'];
        }

        [$proposed, $why] = $this->proposedAnchorSource($projectId, $stage);

        if ($proposed === null) {
            return [null, $why];
        }

        return hash_equals($proposed['artifact_id'], $artifactId)
            ? [$proposed, 'ok']
            : [null, 'anchor_confirmation_stale'];
    }

    /** @return array{0: ?array<string, mixed>, 1: string} */
    private function resolveSource(
        VideoRenderScene $scene,
        ?VideoPlanningStage $stage,
        ?string $confirmAnchorArtifactId,
        bool $requireConfirmedAnchor,
    ): array {
        $projectId = (string) $scene->project_id;
        $role = $this->sourceRoleForMode((string) $scene->transition_mode);

        if ($role === '') {
            return [null, 'scene_transition_mode_unsupported'];
        }

        if ($role === 'source_keyframe') {
            $key = 'cont:'.$projectId.':'.$scene->revision.':'.$scene->source_scene_code;

            if ($this->sourceMemo !== null && array_key_exists($key, $this->sourceMemo)) {
                return $this->sourceMemo[$key];
            }

            $previous = VideoRenderScene::query()
                ->where('project_id', $projectId)
                ->where('revision', $scene->revision)
                ->where('scene_code', (string) $scene->source_scene_code)
                ->first();

            if ($previous === null) {
                return [null, 'source_scene_not_found'];
            }

            $candidate = VideoDesignImage::query()
                ->where('project_id', $projectId)
                ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
                ->where('render_scene_id', $previous->id)
                ->where('status', DesignImageStatus::APPROVED->value)
                ->whereNotNull('selected_artifact_id')
                ->orderByDesc('approved_at')
                ->first();

            $found = $candidate === null
                ? [null, 'source_keyframe_not_approved']
                : $this->freshApprovedSource(
                    $projectId,
                    (string) $candidate->selected_artifact_id,
                    [DesignImageStore::SCENE_KEYFRAME_TYPE],
                    'source_keyframe',
                );

            if ($this->sourceMemo !== null) {
                $this->sourceMemo[$key] = $found;
            }

            return $found;
        }

        [$lock, $why] = $this->lockedAnchor($projectId, (int) $scene->revision);

        if ($why === 'anchor_lock_unreadable') {
            return [null, $why];
        }

        if ($lock !== null) {
            return $this->anchorSourceFromLock($projectId, $lock);
        }

        return $requireConfirmedAnchor
            ? $this->anchorSourceFromConfirmation($projectId, $stage, $confirmAnchorArtifactId)
            : $this->proposedAnchorSource($projectId, $stage);
    }

    /** @param array<string, mixed> $entry */
    private function snapshotKeyframeBelongsToSourceScene(VideoRenderScene $scene, array $entry): bool
    {
        $previous = VideoRenderScene::query()
            ->where('project_id', $scene->project_id)
            ->where('revision', $scene->revision)
            ->where('scene_code', (string) $scene->source_scene_code)
            ->first();

        if ($previous === null) {
            return false;
        }

        return (string) VideoDesignImage::query()
            ->whereKey($entry['candidate_id'])
            ->value('render_scene_id') === (string) $previous->id;
    }

    /**
     * @param  list<array<string, mixed>>  $manifest
     * @return array<string, mixed>
     */
    private function sceneImageSpec(VideoRenderScene $scene, string $preservation, array $manifest): array
    {
        return [
            'operation' => 'scene_keyframe',
            'spec_version' => self::SCENE_SPEC_VERSION,
            'prompt' => ScenePreservationPrompt::forManifest(
                (string) $scene->transition_mode,
                array_column($manifest, 'role'),
                $preservation,
            )."\n\n".(string) $scene->delta_prompt,
            'model' => self::SCENE_IMAGE_MODEL->value,
            'quality' => self::SCENE_IMAGE_QUALITY->value,
            'size' => self::SCENE_IMAGE_SIZE->value,
            'variations' => 1,
            'pricing' => 'unpriced',
            'render_scene_id' => (string) $scene->id,
            'scene_code' => (string) $scene->scene_code,
            'revision' => (int) $scene->revision,
            'transition_mode' => (string) $scene->transition_mode,
            'preservation_version' => $preservation,
            'sources' => $manifest,
            'reference_manifest_hash' => $this->digest((array) $this->canonical($manifest)),
            'source_artifact_id' => $manifest[0]['artifact_id'],
            'source_artifact_sha256' => $manifest[0]['sha256'],
        ];
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    private function buildSceneImageRequest(
        VideoRenderScene $scene,
        ?VideoPlanningStage $stage,
        ?string $confirmAnchorArtifactId,
        bool $requireConfirmedAnchor,
    ): array {
        [$preservation] = $this->preservationForRevision($stage);

        if ($preservation === null) {
            return [null, 'preservation_unknown'];
        }

        $projectId = (string) $scene->project_id;

        if ($requireConfirmedAnchor) {
            [$confirmed, $confirmWhy] = $this->resolveSource(
                $scene, $stage, $confirmAnchorArtifactId, true,
            );

            if ($confirmed === null) {
                return [null, $confirmWhy];
            }
        }

        [$slots, $why] = $this->sceneManifestSlots(
            $projectId, $scene, $stage, $this->approvedReferenceViews($projectId),
        );

        if ($slots === null) {
            return [null, $why];
        }

        $manifest = array_map(fn (array $slot) => $this->manifestEntry($slot), $slots);
        $spec = $this->sceneImageSpec($scene, $preservation, $manifest);

        return [[
            'spec' => $spec,
            'hash' => $this->designImageStore->identityHash($spec, self::SCENE_IDENTITY_KEYS),
            'manifest_hash' => $spec['reference_manifest_hash'],
        ], 'ok'];
    }

    /** @return array{0: ?array<string, mixed>, 1: string} */
    private function specForExistingCandidate(VideoDesignImage $candidate, VideoRenderScene $scene): array
    {
        if ((string) $candidate->project_id !== (string) $scene->project_id
            || (string) $candidate->render_scene_id !== (string) $scene->id
            || $candidate->image_type !== DesignImageStore::SCENE_KEYFRAME_TYPE) {
            return [null, 'candidate_outside_scene'];
        }

        $spec = is_array($candidate->prompt_spec_json) ? $candidate->prompt_spec_json : [];
        $manifest = $spec['sources'] ?? null;

        if (($spec['operation'] ?? null) !== 'scene_keyframe'
            || ($spec['spec_version'] ?? null) !== self::SCENE_SPEC_VERSION
            || ! is_array($manifest)
            || ! array_is_list($manifest)
            || $manifest === []
            || count($manifest) > self::SCENE_MAX_SOURCE_IMAGES) {
            return [null, 'candidate_snapshot_unreadable'];
        }

        $seen = [];

        foreach ($manifest as $position => $entry) {
            if (! $this->manifestEntryShaped($entry) || $entry['position'] !== $position) {
                return [null, 'candidate_snapshot_unreadable'];
            }

            if (array_key_exists($entry['artifact_id'], $seen)) {
                return [null, 'candidate_snapshot_duplicated_source'];
            }

            $seen[$entry['artifact_id']] = true;

            $allowed = $position === 0
                ? [$this->sourceRoleForMode((string) $scene->transition_mode)]
                : ['identity', 'environment', 'geometry'];

            if (! in_array($entry['role'], $allowed, true)) {
                return [null, 'candidate_snapshot_role_mismatch'];
            }
        }

        $roles = array_column($manifest, 'role');

        if (count($roles) !== count(array_unique($roles))) {
            return [null, 'candidate_snapshot_duplicated_role'];
        }

        if ($manifest[0]['role'] === 'source_keyframe'
            && ! $this->snapshotKeyframeBelongsToSourceScene($scene, $manifest[0])) {
            return [null, 'candidate_snapshot_wrong_source_scene'];
        }

        if (! hash_equals(
            (string) ($spec['reference_manifest_hash'] ?? ''),
            $this->digest((array) $this->canonical($manifest)),
        )) {
            return [null, 'candidate_snapshot_manifest_hash_mismatch'];
        }

        if (! hash_equals(
            (string) $candidate->prompt_sha256,
            $this->designImageStore->identityHash($spec, self::SCENE_IDENTITY_KEYS),
        )) {
            return [null, 'candidate_snapshot_identity_mismatch'];
        }

        return [$spec, 'ok'];
    }

    /**
     * @param  list<array<string, mixed>>  $manifest
     * @return array{0: bool, 1: string}
     */
    private function verifiedManifestBytes(array $manifest): array
    {
        foreach ($manifest as $entry) {
            $artifact = VideoArtifact::query()->whereKey($entry['artifact_id'])->first();

            if ($artifact === null) {
                return [false, 'source_artifact_missing'];
            }

            $path = (string) $artifact->storage_path;

            try {
                $disk = Storage::disk((string) $artifact->storage_disk);

                if ($path === '' || ! $disk->exists($path)) {
                    return [false, 'artifact_file_not_found'];
                }

                $bytes = (string) $disk->get($path);
            } catch (\Throwable $e) {
                $this->quietLog('scene-keyframe: khong doc duoc anh nguon', $e, [
                    'artifact_id' => $entry['artifact_id'],
                    'disk' => (string) $artifact->storage_disk,
                ]);

                return [false, 'artifact_storage_unreadable'];
            }

            if ($bytes === '' || ! hash_equals((string) $entry['sha256'], hash('sha256', $bytes))) {
                return [false, 'artifact_checksum_mismatch'];
            }
        }

        return [true, 'ok'];
    }

    /** @param array<string, mixed> $entry */
    private function sourceState(string $projectId, array $entry): string
    {
        if ($entry['role'] === 'anchor') {
            $anchor = $this->designImageStore->approvedAnchorFor($projectId);

            if ($anchor === null) {
                return 'no_approved_anchor';
            }

            return (string) $anchor->selected_artifact_id === $entry['artifact_id']
                ? 'current'
                : 'changed';
        }

        $candidate = VideoDesignImage::query()->whereKey($entry['candidate_id'])->first();

        return $candidate !== null
            && $candidate->status === DesignImageStatus::APPROVED->value
            && (string) $candidate->selected_artifact_id === $entry['artifact_id']
                ? 'current'
                : 'changed';
    }

    /**
     * @param  array<string, mixed>  $built
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function sceneImageView(
        VideoRenderScene $scene,
        array $built,
        bool $fromSnapshot,
        ?string $blocked = null,
    ): array {
        $spec = $built['spec'];
        $projectId = (string) $scene->project_id;

        return [[
            'scene_id' => (string) $scene->id,
            'scene_code' => (string) $scene->scene_code,
            'transition_mode' => (string) $scene->transition_mode,
            'prompt' => (string) $spec['prompt'],
            'prompt_sha256' => $built['hash'],
            'reference_manifest_hash' => $built['manifest_hash'],
            'from_snapshot' => $fromSnapshot,
            'blocked_reason' => $blocked,
            'model' => $spec['model'],
            'quality' => $spec['quality'],
            'size' => $spec['size'],
            'variations' => $spec['variations'],
            'cost_estimate' => null,
            'cost_note' => 'Chua dinh gia: '.$spec['size'].' + '.$spec['quality'],
            'anchor_confirm_artifact_id' => $spec['sources'][0]['role'] === 'anchor'
                ? $spec['sources'][0]['artifact_id']
                : null,
            'sources' => array_map(fn (array $entry): array => [
                'position' => $entry['position'],
                'role' => $entry['role'],
                'artifact_id' => $entry['artifact_id'],
                'sha' => substr($entry['sha256'], 0, 12),
                'state' => $this->sourceState($projectId, $entry),
            ], $spec['sources']),
        ], $blocked ?? 'ok'];
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    public function sceneImagePreview(
        string $projectId,
        ?string $actorId,
        string $sceneId,
    ): array {
        $scene = $this->ownedScene($projectId, $actorId, $sceneId);

        if ($scene === null) {
            return [null, 'scene_not_found'];
        }

        [$ok, $gate, $stage, $verified] = $this->sceneRenderGate($scene);

        if (! $ok) {
            return [null, $gate];
        }

        [$built, $why] = $this->buildSceneImageRequest($verified, $stage, null, false);

        return $built === null ? [null, $why] : $this->sceneImageView($verified, $built, false);
    }

    /** @return array{0: ?array<string, mixed>, 1: string} */
    public function previewFromCandidate(string $projectId, ?string $actorId, string $imageId): array
    {
        $candidate = VideoDesignImage::query()
            ->whereKey($imageId)
            ->where('project_id', $projectId)
            ->first();

        $scene = $candidate === null
            ? null
            : $this->ownedScene($projectId, $actorId, (string) $candidate->render_scene_id);

        if ($candidate === null || $scene === null) {
            return [null, 'candidate_outside_scene'];
        }

        [$ok, $gate, , $verified] = $this->sceneRenderGate($scene);
        $subject = $verified ?? $scene;

        [$spec, $why] = $this->specForExistingCandidate($candidate, $subject);

        if ($spec === null) {
            return [null, $why];
        }

        [$intact, $intactWhy] = $this->snapshotSourcesIntact($projectId, $spec['sources']);

        return $this->sceneImageView(
            $subject,
            [
                'spec' => $spec,
                'hash' => (string) $candidate->prompt_sha256,
                'manifest_hash' => (string) $spec['reference_manifest_hash'],
            ],
            true,
            $ok ? ($intact ? null : $intactWhy) : $gate,
        );
    }

    /**
     * @return array{0: ?VideoDesignImage, 1: string}
     */
    private function claimSceneRender(
        string $projectId,
        ?string $actorId,
        string $sceneId,
        string $previewHash,
        ?string $confirmAnchorArtifactId,
        string $verifiedManifestHash,
    ): array {
        try {
            return DB::transaction(function () use (
                $projectId, $actorId, $sceneId, $previewHash,
                $confirmAnchorArtifactId, $verifiedManifestHash
            ) {
                VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();

                $scene = $this->ownedScene($projectId, $actorId, $sceneId);

                if ($scene === null) {
                    return [null, 'scene_not_found'];
                }

                [$ok, $gate, $stage, $verified] = $this->sceneRenderGate($scene);

                if (! $ok) {
                    return [null, $gate];
                }

                [$built, $buildWhy] = $this->buildSceneImageRequest(
                    $verified, $stage, $confirmAnchorArtifactId, true,
                );

                if ($built === null) {
                    return [null, $buildWhy];
                }

                if (! hash_equals($verifiedManifestHash, $built['manifest_hash'])) {
                    return [null, 'source_changed_before_write'];
                }

                if (! hash_equals($previewHash, $built['hash'])) {
                    return [null, 'preview_stale'];
                }

                $existing = VideoDesignImage::query()
                    ->where('project_id', $projectId)
                    ->where('prompt_sha256', $built['hash'])
                    ->first();

                if ($existing !== null) {
                    [$spec, $specWhy] = $this->specForExistingCandidate($existing, $verified);

                    return $spec === null ? [null, $specWhy] : [$existing, 'existing'];
                }

                return [
                    $this->designImageStore->createSceneCandidate($verified, $built['spec'], $built['hash']),
                    'created',
                ];
            });
        } catch (ModelNotFoundException) {
            return [null, 'project_not_found'];
        }
    }

    /** @return array{0: ?VideoDesignImage, 1: string} */
    private function dispatchSceneCandidate(VideoDesignImage $candidate, bool $retry): array
    {
        $status = (string) $candidate->status;

        if (in_array($status, [
            DesignImageStatus::RENDERED->value,
            DesignImageStatus::APPROVED->value,
        ], true)) {
            return [$candidate, 'already_exists'];
        }

        if ($status === DesignImageStatus::QUEUED->value) {
            return [$candidate, 'render_in_flight'];
        }

        if (in_array($status, DesignImageStatus::leasedValues(), true)) {
            $lease = $candidate->lease_expires_at;

            if ($lease === null) {
                return [$candidate, 'lease_missing'];
            }

            return [$candidate, $lease->isPast() ? 'lease_expired' : 'render_in_flight'];
        }

        if ($status === DesignImageStatus::FAILED->value && ! $retry) {
            return [$candidate, 'previous_render_failed'];
        }

        if ($status !== DesignImageStatus::CANDIDATE->value
            && $status !== DesignImageStatus::FAILED->value) {
            return [$candidate, 'not_enqueueable'];
        }

        return $this->designImageDirectRenderer->renderNow(
            (string) $candidate->id,
            $retry
                ? [DesignImageStatus::CANDIDATE->value, DesignImageStatus::FAILED->value]
                : [DesignImageStatus::CANDIDATE->value],
        );
    }

    /**
     * @return array{0: ?VideoDesignImage, 1: string}
     */
    public function renderSceneImage(
        string $projectId,
        ?string $actorId,
        string $sceneId,
        string $previewHash,
        ?string $confirmAnchorArtifactId,
    ): array {
        $scene = $this->ownedScene($projectId, $actorId, $sceneId);

        if ($scene === null) {
            return [null, 'scene_not_found'];
        }

        [$ok, $gate, $stage, $verified] = $this->sceneRenderGate($scene);

        if (! $ok) {
            return [null, $gate];
        }

        [$built, $why] = $this->buildSceneImageRequest(
            $verified, $stage, $confirmAnchorArtifactId, true,
        );

        if ($built === null) {
            return [null, $why];
        }

        if (! hash_equals($previewHash, $built['hash'])) {
            return [null, 'preview_stale'];
        }

        [$bytesOk, $bytesWhy] = $this->verifiedManifestBytes($built['spec']['sources']);

        if (! $bytesOk) {
            return [null, $bytesWhy];
        }

        [$candidate, $reason] = $this->claimSceneRender(
            $projectId, $actorId, $sceneId, $previewHash,
            $confirmAnchorArtifactId, $built['manifest_hash'],
        );

        return $candidate === null
            ? [null, $reason]
            : $this->dispatchSceneCandidate($candidate, false);
    }

    /** @return array{0: ?VideoDesignImage, 1: string} */
    public function resumeSceneCandidate(
        string $projectId,
        ?string $actorId,
        string $imageId,
        string $previewHash,
    ): array {
        $candidate = VideoDesignImage::query()
            ->whereKey($imageId)
            ->where('project_id', $projectId)
            ->first();

        $scene = $candidate === null
            ? null
            : $this->ownedScene($projectId, $actorId, (string) $candidate->render_scene_id);

        if ($candidate === null || $scene === null) {
            return [null, 'candidate_outside_scene'];
        }

        [$ok, $gate, , $verified] = $this->sceneRenderGate($scene);

        if (! $ok) {
            return [null, $gate];
        }

        [$spec, $why] = $this->specForExistingCandidate($candidate, $verified);

        if ($spec === null) {
            return [null, $why];
        }

        if (! hash_equals((string) $candidate->prompt_sha256, $previewHash)) {
            return [null, 'preview_stale'];
        }

        [$intact, $intactWhy] = $this->snapshotSourcesIntact($projectId, $spec['sources']);

        if (! $intact) {
            return [null, $intactWhy];
        }

        [$bytesOk, $bytesWhy] = $this->verifiedManifestBytes($spec['sources']);

        if (! $bytesOk) {
            return [null, $bytesWhy];
        }

        return $this->dispatchSceneCandidate($candidate, true);
    }

    /**
     * @param  array<string, mixed>  $totals
     * @param  array<string, mixed>  $usage
     * @return array<string, mixed>
     */
    private function addUsage(array $totals, array $usage, bool $attempted): array
    {
        if (! $attempted) {
            return $totals;
        }

        $totals['calls'] = (int) ($totals['calls'] ?? 0) + 1;

        foreach (['tokens_in', 'tokens_out', 'thinking_tokens'] as $key) {
            $value = $usage[$key] ?? null;

            if (! is_int($value) || $value < 0) {
                $totals['incomplete'] = true;

                continue;
            }

            $totals[$key] = (int) ($totals[$key] ?? 0) + $value;
        }

        return $totals;
    }

    /**
     * @param  array<string, mixed>  $totals
     * @return array<string, mixed>
     */
    private function usageColumn(
        array $totals,
        string $provider,
        string $instructionVersion,
        ?string $providerModel,
    ): array {
        return [
            'model' => $provider,
            'provider_model' => $providerModel,
            'instruction_version' => $instructionVersion,
            'tokens_in' => (int) ($totals['tokens_in'] ?? 0),
            'tokens_out' => (int) ($totals['tokens_out'] ?? 0),
            'thinking_tokens' => (int) ($totals['thinking_tokens'] ?? 0),
            'cost_usd' => 0,
        ];
    }

    /** @param array<string, mixed> $trail */
    private function settle(array &$trail, string $stop, string $planIn): void
    {
        $trail['status'] = $stop === 'passed' ? 'passed' : 'needs_review';
        $trail['reason'] = $stop;
        $trail['reviewed_plan_sha256'] = $stop === 'passed' ? $planIn : null;
    }

    /**
     * @return array{encoding: string, text: string}
     */
    private function storableText(string $value): array
    {
        return mb_check_encoding($value, 'UTF-8')
            ? ['encoding' => 'utf8', 'text' => $value]
            : ['encoding' => 'base64', 'text' => base64_encode($value)];
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * @param  callable(): array<string, mixed>  $output
     */
    private function bestEffortProgress(string $stageId, string $claimToken, callable $output): void
    {
        try {
            $this->stageStore->recordProgress($stageId, $claimToken, $output());
        } catch (\Throwable $e) {
            $this->quietLog('scene-plan: progress checkpoint failed', $e, ['stage_id' => $stageId]);
        }
    }

    /**
     * @param  callable(): array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function safeOutput(callable $output): array
    {
        try {
            $value = $output();

            json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $value;
        } catch (\Throwable) {
            return ['checkpoint_unavailable' => true];
        }
    }

    /** @param array<string, mixed> $context */
    private function quietLog(string $message, \Throwable $e, array $context = []): void
    {
        try {
            Log::error($message, $context + ['exception' => $e]);
        } catch (\Throwable) {
        }
    }

    /**
     * @param  array<string, string>  $article
     * @param  array<string, mixed>  $brief
     * @param  array<string, string>  $summary
     * @param  array<string, mixed>  $requirements
     * @param  list<array<string, mixed>>  $scenes
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function reviewInput(
        array $article,
        array $brief,
        array $summary,
        SceneProfile $profile,
        array $requirements,
        array $scenes,
        array $warnings,
    ): array {
        return [
            'article' => $article,
            'inspiration_brief' => $brief,
            'identity_summary' => $summary,
            'profile' => $profile->toPayload(),
            'planning_requirements' => $requirements,
            'plan' => ['scenes' => $this->positioned($scenes)],
            'heuristic_warnings' => $warnings,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $scenes
     * @return list<array<string, mixed>>
     */
    private function positioned(array $scenes): array
    {
        $out = [];

        foreach ($scenes as $index => $scene) {
            $previous = $index === 0 ? null : $scenes[$index - 1];

            $out[] = array_replace($scene, [
                'index' => $index + 1,
                'previous_scene_code' => $previous['scene_code'] ?? null,
                'source_keyframe_scene_code' => ($scene['source_scene_code'] ?? '') !== ''
                    ? $scene['source_scene_code']
                    : null,
                'previous_clip_action' => $previous['video']['action'] ?? null,
            ]);
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $scenes
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $trail
     * @param  array<string, mixed>  $authorUsage
     * @param  array<string, mixed>  $totals
     * @return array<string, mixed>
     */
    private function planOutput(
        array $scenes,
        array $warnings,
        array $trail,
        array $authorUsage,
        array $totals,
        string $authorRaw,
        ?int $revision = null,
    ): array {
        return [
            'revision' => $revision,
            'scenes' => $scenes,
            'warnings' => $warnings,
            'raw' => ['author' => $this->storableText($authorRaw)],
            'usage' => [
                'author' => $authorUsage,
                'reviews' => array_map(
                    static fn (array $round) => $round['usage'] ?? null,
                    $trail['rounds'],
                ),
                'total' => $totals,
            ],
            'review' => $trail,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $scenes
     * @param  array<string, mixed>  $requirements
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function validatedScenes(
        array $scenes,
        SceneProfile $profile,
        array $requirements,
        int $maxScenes,
    ): array {
        if (count($scenes) < $profile->minScenes) {
            throw new ScenePlanException('Plan returned '.count($scenes)
                .' scenes; the profile requires at least '.$profile->minScenes.'.');
        }

        if (count($scenes) > $maxScenes) {
            throw new ScenePlanException('Plan returned '.count($scenes)
                .' scenes; the ceiling is '.$maxScenes.'.');
        }

        $phases = $profile->phaseKeys();
        $seen = [];
        $covered = [];
        $previousMax = -1;
        $previousSceneState = null;
        $previousGroup = null;
        $previousCode = '';
        $closedGroups = [];
        $warnings = [];

        foreach ($scenes as $index => $scene) {
            $at = 'scene '.($index + 1);

            if (! is_array($scene) || array_is_list($scene)) {
                throw new ScenePlanException($at.': scene must be an object.');
            }

            foreach ([
                'scene_code', 'phase', 'transition_mode', 'title', 'purpose', 'delta',
                'continuity_group', 'source_scene_code', 'camera_change_reason',
            ] as $field) {
                if (! is_string($scene[$field] ?? null)) {
                    throw new ScenePlanException($at.': '.$field.' must be a string.');
                }
            }

            $code = (string) ($scene['scene_code'] ?? '');
            $phase = (string) ($scene['phase'] ?? '');
            $mode = (string) ($scene['transition_mode'] ?? '');
            $title = trim((string) ($scene['title'] ?? ''));
            $purpose = trim((string) ($scene['purpose'] ?? ''));
            $delta = trim((string) ($scene['delta'] ?? ''));

            if (preg_match('/^[a-z][a-z0-9_]{2,59}$/', $code) !== 1) {
                throw new ScenePlanException($at.': scene_code "'.$code.'" is malformed.');
            }

            if (isset($seen[$code])) {
                throw new ScenePlanException($at.': scene_code "'.$code.'" repeats.');
            }

            $seen[$code] = true;

            if (! in_array($phase, $phases, true)) {
                throw new ScenePlanException($at.': phase "'.$phase.'" is outside the profile.');
            }

            $previousMax = $this->checkMilestones($at, $scene, $phase, $profile, $previousMax, $covered);

            if (! in_array($scene['basis'] ?? null, ['source_supported', 'inferred_process'], true)) {
                throw new ScenePlanException($at.': basis is unknown.');
            }

            $words = count(preg_split('/\s+/', $title, -1, PREG_SPLIT_NO_EMPTY) ?: []);

            if ($words < 2 || $words > 4) {
                throw new ScenePlanException($at.': title must be two to four words.');
            }

            if (mb_strlen($title) > 120) {
                throw new ScenePlanException($at.': title must be at most 120 characters.');
            }

            if (mb_strlen($purpose) < 3 || mb_strlen($purpose) > 500) {
                throw new ScenePlanException($at.': purpose must be 3 to 500 characters.');
            }

            foreach (['state_before', 'scene_state'] as $field) {
                if (! $this->isStateToken($scene[$field] ?? null)) {
                    throw new ScenePlanException($at.': '.$field.' must be a lowercase token.');
                }
            }

            if (! in_array($mode, ScenePreservationPrompt::modes(), true)) {
                throw new ScenePlanException($at.': transition_mode "'.$mode.'" is unknown.');
            }

            if ($index === 0 && $mode !== ScenePreservationPrompt::HARD_CUT) {
                throw new ScenePlanException('scene 1 must be '.ScenePreservationPrompt::HARD_CUT.'.');
            }

            $group = (string) ($scene['continuity_group'] ?? '');
            $source = (string) ($scene['source_scene_code'] ?? '');
            $reason = trim((string) ($scene['camera_change_reason'] ?? ''));

            if (preg_match('/^[a-z][a-z0-9_]{2,59}$/', $group) !== 1) {
                throw new ScenePlanException($at.': continuity_group "'.$group.'" is malformed.');
            }

            $opensGroup = $index === 0 || $group !== $previousGroup;

            if ($opensGroup && isset($closedGroups[$group])) {
                throw new ScenePlanException(
                    $at.': continuity_group "'.$group.'" reopens after it closed; a group must be one run.'
                );
            }

            if ($opensGroup && $previousGroup !== null) {
                $closedGroups[$previousGroup] = true;
            }

            if ($opensGroup) {
                if ($mode !== ScenePreservationPrompt::HARD_CUT) {
                    throw new ScenePlanException($at.': a scene that opens a group must be a hard cut.');
                }

                if ($source !== '') {
                    throw new ScenePlanException(
                        $at.': a scene that opens a group takes no source_scene_code.'
                    );
                }

                if (mb_strlen($reason) < 3 || mb_strlen($reason) > 300) {
                    throw new ScenePlanException(
                        $at.': a scene that opens a group must say why this viewpoint.'
                    );
                }
            } else {
                if ($mode !== ScenePreservationPrompt::CONTINUATION) {
                    throw new ScenePlanException(
                        $at.': a scene that continues a group must be a continuation.'
                    );
                }

                if ($source !== $previousCode) {
                    throw new ScenePlanException(
                        $at.': source_scene_code must be the scene before it, "'.$previousCode.'".'
                    );
                }

                if ($reason !== '') {
                    throw new ScenePlanException(
                        $at.': a scene that continues a group does not change the viewpoint.'
                    );
                }
            }

            $previousGroup = $group;
            $previousCode = $code;

            if (($scene['camera_mode'] ?? null) !== 'locked') {
                throw new ScenePlanException($at.': camera_mode must be locked.');
            }

            if ($delta === '' || mb_strlen($delta) > 1000) {
                throw new ScenePlanException($at.': delta must be 1 to 1000 characters.');
            }

            if (preg_match('/\bimage\s+\d+\s*:/i', $delta) === 1) {
                throw new ScenePlanException($at.': delta carries a numbered image label.');
            }

            $this->checkVideoPlan($at, $scene['video'] ?? null);

            if ($mode === ScenePreservationPrompt::CONTINUATION
                && $previousSceneState !== null
                && $scene['state_before'] !== $previousSceneState) {
                $warnings[] = $at.': state_before does not continue the previous keyframe state.';
            }

            $previousSceneState = (string) $scene['scene_state'];
            $warnings = array_merge($warnings, $this->sceneWarnings($at, $delta));
        }

        $missing = array_values(array_diff($profile->requiredMilestoneKeys(), array_keys($covered)));

        if ($missing !== []) {
            throw new ScenePlanException(
                'Plan does not cover required milestones: '.implode(', ', $missing)
            );
        }

        return [$scenes, array_merge($warnings, $this->sceneCountWarnings(count($scenes), $requirements))];
    }

    /**
     * @param  array<string, mixed>  $scene
     * @param  array<string, bool>  $covered
     */
    private function checkMilestones(
        string $at,
        array $scene,
        string $phase,
        SceneProfile $profile,
        int $previousMax,
        array &$covered,
    ): int {
        $keys = $scene['milestone_keys'] ?? null;

        if (! is_array($keys) || ! array_is_list($keys) || $keys === []
            || count($keys) > $profile->maxMilestonesPerScene) {
            throw new ScenePlanException($at.': milestone_keys must be a list of 1 to '
                .$profile->maxMilestonesPerScene.' entries.');
        }

        $indices = [];

        foreach ($keys as $key) {
            if (! is_string($key)) {
                throw new ScenePlanException($at.': every milestone key must be a string.');
            }

            $index = $profile->indexOf($key);

            if ($index === null) {
                throw new ScenePlanException($at.': milestone "'.$key.'" is outside the profile.');
            }

            if ($profile->phaseOf($key) !== $phase) {
                throw new ScenePlanException($at.': milestone "'.$key.'" is not in phase "'.$phase.'".');
            }

            if (in_array($index, $indices, true)) {
                throw new ScenePlanException($at.': milestone "'.$key.'" repeats within the scene.');
            }

            $indices[] = $index;
            $covered[$key] = true;
        }

        $sorted = $indices;
        sort($sorted);

        if ($sorted !== $indices) {
            throw new ScenePlanException($at.': milestones inside one scene must be listed in profile order.');
        }

        if (min($indices) < $previousMax) {
            throw new ScenePlanException($at.': milestones move backwards.');
        }

        return max($indices);
    }

    private function checkVideoPlan(string $at, mixed $video): void
    {
        if (! is_array($video) || array_is_list($video)) {
            throw new ScenePlanException($at.': video must be an object.');
        }

        if (array_diff(array_keys($video), ['action', 'preserve', 'end_state']) !== []) {
            throw new ScenePlanException($at.': video carries unexpected keys.');
        }

        foreach (['action' => 1000, 'preserve' => 500] as $field => $max) {
            $value = $video[$field] ?? null;

            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $max) {
                throw new ScenePlanException($at.': video.'.$field.' must be 1 to '.$max.' characters.');
            }
        }

        if (! $this->isStateToken($video['end_state'] ?? null)) {
            throw new ScenePlanException($at.': video.end_state must be a lowercase token.');
        }
    }

    private function isStateToken(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z][a-z0-9_]{1,119}$/', $value) === 1;
    }

    /**
     * @param  array<string, mixed>  $requirements
     * @return list<string>
     */
    private function sceneCountWarnings(int $count, array $requirements): array
    {
        $target = $requirements['target_duration_seconds'] ?? null;
        $perClip = $requirements['seconds_per_clip'] ?? null;

        if (! is_int($target) || $target < 1 || ! is_int($perClip) || $perClip < 1) {
            return [];
        }

        $expected = intdiv($target, $perClip);

        if ($expected < 1) {
            return [];
        }

        $tolerance = max(2, (int) round($expected * 0.25));

        return abs($count - $expected) > $tolerance
            ? ['plan returned '.$count.' scenes for a target of about '.$expected.'.']
            : [];
    }

    /** @return list<string> */
    private function sceneWarnings(string $at, string $delta): array
    {
        $warnings = [];

        if ($this->mentionsAny($delta, self::CANVAS_LEXICON)) {
            $warnings[] = $at.': delta may be naming the canvas.';
        }

        if (preg_match('/\b(no|not|never|without|avoid)\b/i', $delta) === 1) {
            $warnings[] = $at.': delta may be stating a prohibition.';
        }

        if (preg_match('/\d[\d.,]*\s*-?\s*(met(er|re)s?|m\b|ft\b|beam|tier|deck)/i', $delta) === 1) {
            $warnings[] = $at.': delta may be restating an identity dimension.';
        }

        if (preg_match(self::MOTION_PATTERN, $delta) === 1) {
            $warnings[] = $at.': delta may be describing motion instead of one still state.';
        }

        if (preg_match(self::COUNT_PATTERN, $delta) === 1) {
            $warnings[] = $at.': delta names a count that may restate an identity fact — review needed.';
        }

        return $warnings;
    }

    /** @param list<string> $lexicon */
    private function mentionsAny(string $text, array $lexicon): bool
    {
        foreach ($lexicon as $term) {
            if (preg_match('/(?<![a-z])'.preg_quote($term, '/').'(?![a-z])/i', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function planningRequirements(SceneProfile $profile, int $maxScenes): array
    {
        $requirements = [
            'scope' => $profile->scope,
            'detail_level' => $profile->detailLevel,
            'min_scenes' => $profile->minScenes,
            'max_scenes' => $maxScenes,
            'max_milestones_per_scene' => $profile->maxMilestonesPerScene,
            'allow_inferred_process' => true,
            'output_medium' => 'image_keyframe_plus_clip_draft',
        ];

        $target = config('video.scene_plan.target_duration_seconds');
        $perClip = config('video.scene_plan.seconds_per_clip');

        if (is_int($target) && $target > 0) {
            $requirements['target_duration_seconds'] = $target;
        }

        if (is_int($perClip) && $perClip > 0) {
            $requirements['seconds_per_clip'] = $perClip;
        }

        return $requirements;
    }

    /**
     * @return array<string, mixed>
     */
    private function sceneView(
        VideoRenderScene $scene,
        ?SceneProfile $profile,
        ?string $preservation,
    ): array {
        $video = is_array($scene->video_plan_json) ? $scene->video_plan_json : null;
        $keys = is_array($scene->milestone_keys) ? $scene->milestone_keys : [];
        $state = is_array($scene->state_json) ? $scene->state_json : [];
        $mode = (string) $scene->transition_mode;

        return [
            'id' => (string) $scene->scene_code,
            'scene_id' => (string) $scene->id,
            'title' => (string) $scene->title,
            'phase' => (string) $scene->scene_type,
            'purpose' => (string) $scene->purpose,
            'transition_mode' => $mode,
            'delta' => (string) $scene->delta_prompt,
            'image_prompt' => $preservation === null
                ? null
                : ScenePreservationPrompt::forMode($mode, $preservation)."\n\n".$scene->delta_prompt,
            'milestones' => array_map(
                static fn (string $key) => $profile?->labelOf($key) ?? $key,
                array_values(array_filter($keys, 'is_string')),
            ),
            'basis' => is_string($scene->basis) ? $scene->basis : null,
            'continuity_group' => is_string($scene->continuity_group) ? $scene->continuity_group : null,
            'source_scene_code' => is_string($scene->source_scene_code) ? $scene->source_scene_code : null,
            'camera_change_reason' => is_string($scene->camera_change_reason)
                ? $scene->camera_change_reason
                : null,
            'state_before' => $state['state_before'] ?? null,
            'scene_state' => $state['scene_state'] ?? null,
            'end_state' => $video['end_state'] ?? null,
            'video_prompt' => $video === null ? null : $this->videoPrompt($video),
        ];
    }

    /**
     * `passed` la trang thai duy nhat mo cong render, nen no phai co BANG CHUNG:
     * it nhat mot vong da chay va mot hash cua ban da duoc ra. Mot ban ghi hong
     * KHONG bao gio duoc doc thanh passed.
     *
     * Kiem ca hinh dang long nhau, vi trang hien `count($round['findings'])` —
     * mot chuoi o do se lam vo trang chu khong phai hien sai.
     *
     * @param  mixed  $review
     */
    private function readableReview($review): bool
    {
        if (! is_array($review)
            || ! in_array($review['status'] ?? null, ['passed', 'needs_review', 'unreviewed'], true)
            || ! is_string($review['reason'] ?? null)
            || trim($review['reason']) === ''
            || ! is_array($review['rounds'] ?? null)
            || ! array_is_list($review['rounds'])) {
            return false;
        }

        $hash = $review['reviewed_plan_sha256'] ?? null;

        if ($hash !== null && (! is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1)) {
            return false;
        }

        foreach ($review['rounds'] as $round) {
            if (! $this->readableRound($round)) {
                return false;
            }
        }

        if ($review['status'] !== 'passed') {
            return true;
        }

        if ($hash === null || $review['rounds'] === []) {
            return false;
        }

        return $this->roundProvesPass($review['rounds'][count($review['rounds']) - 1], $hash);
    }

    /**
     * Vong cuoi phai THUC SU la mot lan pass: verdict `pass`, khong loi, khong
     * va (mot `pass` khong bao gio sinh `plan_sha256_out`), khong finding chan
     * render, va doc dung ban da duoc xac nhan.
     *
     * @param  array<string, mixed>  $round
     */
    private function roundProvesPass(array $round, string $hash): bool
    {
        if (($round['verdict'] ?? null) !== 'pass'
            || array_key_exists('error', $round)
            || array_key_exists('apply_error', $round)
            || array_key_exists('plan_sha256_out', $round)
            || ($round['plan_sha256_in'] ?? null) !== $hash) {
            return false;
        }

        foreach ((array) ($round['findings'] ?? []) as $finding) {
            if (($finding['severity'] ?? null) === 'blocking') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  mixed  $round
     */
    private function readableRound($round): bool
    {
        if (! is_array($round) || array_is_list($round) || ! is_int($round['round'] ?? null)) {
            return false;
        }

        if (array_key_exists('duration_ms', $round) && ! is_int($round['duration_ms'])) {
            return false;
        }

        if (array_key_exists('verdict', $round)
            && ! in_array($round['verdict'], ScenePlanReviewer::VERDICTS, true)) {
            return false;
        }

        foreach (['plan_sha256_in', 'plan_sha256_out'] as $key) {
            if (array_key_exists($key, $round)
                && (! is_string($round[$key]) || preg_match('/^[0-9a-f]{64}$/', $round[$key]) !== 1)) {
                return false;
            }
        }

        if (array_key_exists('error', $round) && ! $this->readableRoundError($round['error'])) {
            return false;
        }

        foreach (['findings', 'patched_codes'] as $key) {
            if (array_key_exists($key, $round)
                && (! is_array($round[$key]) || ! array_is_list($round[$key]))) {
                return false;
            }
        }

        foreach ((array) ($round['patched_codes'] ?? []) as $code) {
            if (! is_string($code)) {
                return false;
            }
        }

        foreach ((array) ($round['findings'] ?? []) as $finding) {
            if (! is_array($finding) || array_is_list($finding)) {
                return false;
            }

            foreach (['scene_code', 'rule', 'severity', 'problem', 'fix'] as $field) {
                if (! is_string($finding[$field] ?? null)) {
                    return false;
                }
            }

            if (! in_array($finding['rule'], ScenePlanReviewer::RULES, true)
                || ! in_array($finding['severity'], ScenePlanReviewer::SEVERITIES, true)) {
                return false;
            }

            if (array_key_exists('evidence', $finding) && ! $this->readableEvidence($finding['evidence'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  mixed  $usage
     * @return array<string, mixed>|null
     */
    private function readableUsage($usage): ?array
    {
        if (! is_array($usage) || ! is_array($usage['total'] ?? null)) {
            return null;
        }

        foreach (['calls', 'tokens_in', 'tokens_out', 'thinking_tokens'] as $key) {
            if (array_key_exists($key, $usage['total']) && ! is_int($usage['total'][$key])) {
                return null;
            }
        }

        if (array_key_exists('incomplete', $usage['total']) && ! is_bool($usage['total']['incomplete'])) {
            return null;
        }

        return $usage;
    }

    /**
     * @param  mixed  $evidence
     */
    private function readableEvidence($evidence): bool
    {
        if (! is_array($evidence) || ! array_is_list($evidence)) {
            return false;
        }

        foreach ($evidence as $item) {
            if (! is_array($item) || array_is_list($item)) {
                return false;
            }

            foreach (['source', 'scene_code', 'field', 'quote'] as $key) {
                if (! is_string($item[$key] ?? null)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param mixed $error */
    private function readableRoundError($error): bool
    {
        return is_array($error)
            && is_string($error['class'] ?? null)
            && is_array($error['message'] ?? null)
            && is_string($error['message']['encoding'] ?? null)
            && is_string($error['message']['text'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewForRevision(?VideoPlanningStage $stage): array
    {
        $unreviewed = static fn (string $reason): array => [
            'status' => 'unreviewed',
            'reason' => $reason,
            'reviewed_plan_sha256' => null,
            'open_findings' => [],
            'patched_but_unverified' => false,
            'rounds' => [],
            'usage' => null,
        ];

        if ($stage === null || ! is_array($stage->output_json)) {
            return $unreviewed('unverifiable');
        }

        if (! array_key_exists('review', $stage->output_json)) {
            return $unreviewed('not_recorded');
        }

        $review = $stage->output_json['review'];

        if (! $this->readableReview($review)) {
            return $unreviewed('unreadable');
        }

        $rounds = $review['rounds'];
        $last = $rounds === [] ? null : $rounds[count($rounds) - 1];
        $patched = $last !== null && array_key_exists('plan_sha256_out', $last);

        return [
            'status' => $review['status'],
            'reason' => $review['reason'],
            'reviewed_plan_sha256' => $review['reviewed_plan_sha256'] ?? null,
            'open_findings' => $patched || $last === null
                ? []
                : array_values(array_filter((array) ($last['findings'] ?? []), 'is_array')),
            'patched_but_unverified' => $patched && $review['status'] !== 'passed',
            'rounds' => $rounds,
            'usage' => $this->readableUsage($stage->output_json['usage'] ?? null),
        ];
    }

    /**
     * @return array{0: ?SceneProfile, 1: ?string}
     */
    private function profileForRevision(?VideoPlanningStage $stage): array
    {
        if ($stage === null || ! is_array($stage->input_json)) {
            return [null, 'profile_unverifiable'];
        }

        $input = $stage->input_json;

        if (! array_key_exists('profile_version', $input)) {
            return [null, null];
        }

        $version = $input['profile_version'];

        if (! is_string($version) || $version === '') {
            return [null, 'profile_unverifiable'];
        }

        try {
            $profile = SceneProfile::load((string) config('video.scene_plan.profile_dir'), $version);
        } catch (ScenePlanException) {
            return [null, 'profile_unverifiable'];
        }

        return $profile->sha256 === ($input['profile_sha256'] ?? null)
            ? [$profile, null]
            : [null, 'profile_unverifiable'];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function preservationForRevision(?VideoPlanningStage $stage): array
    {
        if ($stage === null || ! is_array($stage->input_json)) {
            return [null, 'preservation_unknown'];
        }

        $input = $stage->input_json;

        if (! array_key_exists('preservation_version', $input)) {
            return [ScenePreservationPrompt::LEGACY_VERSION, null];
        }

        $version = $input['preservation_version'];

        return is_string($version) && in_array($version, ScenePreservationPrompt::versions(), true)
            ? [$version, null]
            : [null, 'preservation_unknown'];
    }

    /** @param array<string, mixed> $video */
    public function videoPrompt(array $video): string
    {
        return implode("\n\n", [
            'The supplied image is the first frame of this shot and is already correct; the shot begins from exactly that state.',
            'ACTION: '.$video['action'],
            'CAMERA: '.self::LOCKED_CAMERA,
            'PRESERVE: '.$video['preserve'],
            'END STATE: '.$video['end_state'],
        ]);
    }

    /** @return array<string, string>|null */
    private function identitySummary(string $anchorPrompt, string $fallbackSubject): ?array
    {
        if (trim($anchorPrompt) === '') {
            return null;
        }

        $subject = $this->headingBody($anchorPrompt, 'PRIMARY SUBJECT')
            ?? $this->headingBody($anchorPrompt, 'SUBJECT');
        $source = 'anchor_prompt';

        if ($subject === null) {
            $subject = trim($fallbackSubject);
            $source = 'category_subject_class';
        }

        $identity = $this->priorityBlock($anchorPrompt, 0);

        if ($subject === '' || $identity === null) {
            return null;
        }

        return array_filter([
            'subject_class' => $subject,
            'subject_class_source' => $source,
            'identity' => $identity,
            'proportion' => $this->priorityBlock($anchorPrompt, 3),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function headingBody(string $prompt, string $heading): ?string
    {
        $pattern = '/^[ \t]*'.preg_quote($heading, '/').'[ \t]*(?::[ \t]*(.*)|)$/mi';

        if (preg_match($pattern, $prompt, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $inline = trim($matches[1][0] ?? '');

        if ($inline !== '') {
            return $inline;
        }

        $rest = substr($prompt, $matches[0][1] + strlen($matches[0][0]));

        foreach (preg_split('/\R/', $rest) ?: [] as $line) {
            if (trim($line) !== '') {
                return trim($line);
            }
        }

        return null;
    }

    private function priorityBlock(string $prompt, int $level): ?string
    {
        if (preg_match('/^P'.$level.'\b[^\n]*$/m', $prompt, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $body = substr($prompt, $matches[0][1] + strlen($matches[0][0]));

        $end = preg_match('/^P\d\b/m', $body, $next, PREG_OFFSET_CAPTURE) === 1
            ? substr($body, 0, $next[0][1])
            : $body;

        return trim($matches[0][0]."\n".$end);
    }

    /** @param array<mixed> $value */
    private function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
