<?php

namespace App\Services;

use App\Enums\AnchorStage;
use App\Enums\DesignImageStatus;
use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\ImageVariations;
use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Models\Admin;
use App\Models\AiPhase;
use App\Models\VideoArtifact;
use App\Models\VideoDesignImage;
use App\Models\VideoFinal;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Models\VideoRender;
use App\Models\VideoRenderScene;
use App\Models\VideoSession;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Services\Admin\ArticleService;
use App\Services\Video\CharacterAnchorPromptService;
use App\Services\Video\DesignImageDirectRenderer;
use App\Services\Video\DesignImageStore;
use App\Services\Video\FinalCompositionReconciler;
use App\Services\Video\PlanningStageStore;
use App\Services\Video\ProductionSelectionService;
use App\Services\Video\ReferencePromptWriter;
use App\Services\Video\ScreenplayApprovalService;
use App\Services\Video\ScreenplayExpansionService;
use App\Services\Video\ScreenplaySubjectService;
use App\Services\Video\VesselDesignService;
use App\Services\Video\VisualIdentityStore;
use App\Video\Screenplay\VesselDesign;
use App\Video\Article\RawArticle;
use App\Video\Concept\Handoff\CompiledAnchorPrompt;
use App\Video\Concept\Orchestration\CanonicalConceptInputBuilder;
use \App\Video\Prompt\GeometryPromptAuthor;
use App\Video\Concept\Viewpoint;
use App\Video\Environment\EnvironmentPlatePrompt;
use App\Video\FinalComposition\CompositionExecutor;
use App\Video\FinalComposition\CompositionReceipt;
use App\Video\FinalComposition\CompositionResult;
use App\Video\FinalComposition\CompositionRun;
use App\Video\FinalComposition\Deadline;
use App\Video\Media\MediaModelRegistry;
use App\Video\Inspiration\ClaudeInspirationAnalyst;
use App\Video\Inspiration\InspirationResult;
use App\Video\Inspiration\SourceCheck;
use App\Video\Reference\ReferenceEnvironment;
use App\Video\Reference\ReferenceView;
use App\Video\Render\Video\FilmSegmentPrompt;
use App\Video\Render\Video\SceneClipDispatchService;
use App\Video\Scene\FilmCutAuthor;
use App\Video\Scene\FilmCutPlan;
use App\Video\Scene\ScenePlanAuthor;
use App\Video\Scene\ScenePlanException;
use App\Video\Scene\ScenePlanResult;
use App\Video\Scene\ScenePreservationPrompt;
use App\Video\Scene\SceneProfile;
use App\Video\Scene\Services\ShotSelectionReconciler;
use App\Video\Scene\StoryboardPlanCheck;
use App\Video\Screenplay\DesignLocationBuilder;
use App\Video\Screenplay\FilmBrief;
use App\Video\Screenplay\LocationProfile;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class VideoProjectService
{
    private const LOCKED_CAMERA = 'The camera stays exactly where the supplied frame was taken from: '
        .'same position, same lens, same framing, for the whole shot.';

    private const SCENE_SPEC_VERSION = 'scene-keyframe-v2';

    private const SETTING_LEAD = 'SCENE SETTING (from the screenplay; this light and weather override the light of any reference image):';

    private const SPACE_LEAD = 'THIS SPACE IS PART OF THE SUBJECT. Its structure comes from the subject image confirmed as its geometry source; '
        .'this text only says which part of the subject the frame shows and never adds structure that image contradicts:';

    private const ROOM_SPACE_LEAD = 'THIS ROOM IS PART OF THE SUBJECT. IMAGE 1, the approved image of this room, is the source of its structure, layout and materials; '
        .'this text only says which part of the room the frame shows and never adds structure that image contradicts:';

    private const NO_PLATE = 'subject_part_without_plate';

    public const STORYBOARD_MODE = 'storyboard';

    public const FILM_CUT_MODE = 'film_cut';

    private const SOURCE_CHANGED = 'storyboard_source_changed:';

    private const FILM_SEGMENT_CODE = 'film_seg_';

    public const ENVIRONMENT_FROM_SCENE_PLAN = 'scene_plan';

    public const ENVIRONMENT_FROM_SCREENPLAY = 'screenplay';

    public const ENVIRONMENT_FROM_PROFILE = 'profile';

    public const ENVIRONMENT_EXTERNAL = 'external';

    public const ENVIRONMENT_ROOM = 'room';

    private const ROOM_ENVIRONMENT_CONTRACT = 'screenplay-environment-room-v3';

    private const LOCATION_ENVIRONMENT_CONTRACT = 'screenplay-environment-v3';

    private const LEGACY_ENVIRONMENT_CONTRACT = 'screenplay-environment-legacy-v2';

    private const UNRESOLVED_MARK = 'UNRESOLVED:';

    /** @var list<string> */
    private const STORED_REVIEW_VERDICTS = ['pass', 'revise', 'requires_replan'];

    /** @var list<string> */
    private const STORED_REVIEW_RULES = [
        'progress', 'source_image', 'end_state', 'preserve',
        'camera', 'coverage_basis', 'content', 'duration', 'continuity', 'milestone_basis',
    ];

    /** @var list<string> */
    private const STORED_REVIEW_SEVERITIES = ['blocking', 'advisory'];

    private const STORYBOARD_PRESERVE = 'The camera, framing, lighting, setting and everything the action does not name '
        .'stay as they are in the supplied frame.';

    private const CAMERA_LEAD = 'CAMERA FOR THIS FRAME:';

    /** @var list<string> */
    private const SHOT_SHAPE_STATE_KEYS = [
        'beat_coverage', 'objects_start', 'objects_end', 'objects_visible', 'objects_first_frame', 'objects_last_frame',
        'reference_requirements', \App\Video\Screenplay\SceneBeats::ROOM_STATE_KEY, 'timeline', 'after_jump',
    ];

    private const OBJECTS_LEAD ='TRACKED OBJECTS IN THIS FRAME (each shows exactly this state, no later one):';

    private const FRAME_LEAD = 'STATE OF THE SUBJECT IN THIS FRAME (what is built and how each moving part stands at this instant; the subject\'s design comes from the images):';

    private const SCENE_IDENTITY_KEYS = [
        'operation', 'spec_version', 'render_scene_id', 'reference_manifest_hash',
    ];

    private const MANIFEST_ROLES = [
        'anchor', 'source_keyframe', 'identity', 'environment', 'geometry', 'space_geometry',
        'continuity', 'design_reference',
    ];

    private const ROLE_IMAGE_TYPES = [
        'anchor' => [DesignImageStore::ANCHOR_TYPE],
        'source_keyframe' => [DesignImageStore::SCENE_KEYFRAME_TYPE],
        'identity' => [DesignImageStore::ANCHOR_TYPE, DesignImageStore::REFERENCE_TYPE],
        'environment' => [DesignImageStore::ENVIRONMENT_TYPE],
        'geometry' => [DesignImageStore::REFERENCE_TYPE, DesignImageStore::SCENE_KEYFRAME_TYPE],
        'space_geometry' => [DesignImageStore::ANCHOR_TYPE, DesignImageStore::REFERENCE_TYPE],
        'continuity' => [DesignImageStore::SCENE_KEYFRAME_TYPE],
        'design_reference' => [DesignImageStore::ANCHOR_TYPE, DesignImageStore::REFERENCE_TYPE],
    ];

    /** @var list<string> */
    private const SINGLE_ROLES = ['anchor', 'source_keyframe', 'environment', 'space_geometry', 'continuity'];

    private const REFERENCE_CHOICE_KEY = 'reference_choice';

    private const REFERENCE_CONFIRMATIONS_KEY = 'reference_confirmations';

    private const AFTER_JUMP_KEY = 'after_jump';

    private const FILM_KEYFRAME_USE_KEY = 'film_keyframe_use';

    private const FILM_VIEW_CHOICE_KEY = 'film_reference_views';

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

    private DesignImageStore $designImageStore;

    private DesignImageDirectRenderer $designImageDirectRenderer;

    private VisualIdentityStore $identityStore;

    private CanonicalConceptInputBuilder $canonicalConceptInputBuilder;

    private GeometryPromptAuthor $geometryPromptAuthor;

    private ScreenplayApprovalService $screenplayApprovalService;

    private ProductionSelectionService $productionSelectionService;

    private ScreenplayExpansionService $screenplayExpansion;

    public function __construct(
        VideoProjectRepositoryInterface $videoProjectRepository,
        ArticleService $articleService,
        PlanningStageStore $stageStore,
        DesignImageStore $designImageStore,
        DesignImageDirectRenderer $designImageDirectRenderer,
        VisualIdentityStore $identityStore,
        CanonicalConceptInputBuilder $canonicalConceptInputBuilder,
        GeometryPromptAuthor $geometryPromptAuthor,
        ScreenplayApprovalService $screenplayApprovalService,
        ProductionSelectionService $productionSelectionService,
        ScreenplayExpansionService $screenplayExpansion,
    ) {
        $this->videoProjectRepository = $videoProjectRepository;
        $this->articleService = $articleService;
        $this->stageStore = $stageStore;
        $this->designImageStore = $designImageStore;
        $this->designImageDirectRenderer = $designImageDirectRenderer;
        $this->identityStore = $identityStore;
        $this->canonicalConceptInputBuilder = $canonicalConceptInputBuilder;
        $this->geometryPromptAuthor = $geometryPromptAuthor;
        $this->screenplayApprovalService = $screenplayApprovalService;
        $this->productionSelectionService = $productionSelectionService;
        $this->screenplayExpansion = $screenplayExpansion;
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

        try {
            [$prompt, $articleBlock, $input] = $this->inspirationRun($project);
        } catch (\Throwable $e) {
            Log::error('inspiration phase-1: prompt or article unavailable, no model call made', [
                'project_id' => $project->id,
                'exception' => $e,
            ]);

            return [null, 'Không lấy được prompt Phase 1 hoặc bài viết — chưa gọi Haiku: '.$e->getMessage()];
        }

        try {
            [$stage, $token, $reason] = $this->stageStore->claimProjectStage(
                $project->id,
                PlanningStageName::INSPIRATION,
                $input,
            );
        } catch (\Throwable $e) {
            Log::error('inspiration phase-1: could not open the run', [
                'project_id' => $project->id,
                'exception' => $e,
            ]);

            return [null, 'Khong mo duoc luot phan tich — xem log de biet nguyen nhan.'];
        }

        if ($token === null) {
            return match ($reason) {
                'already_succeeded' => [$stage->output_json, 'cached'],
                'claimed_by_other' => [null, 'Đang có một lượt phân tích chạy cho dự án này — đợi xong rồi thử lại'],
                default => [null, 'Khong tim thay du an'],
            };
        }

        $result = null;

        try {
            $result = $this->canonicalConceptInputBuilder->buildInspiration($prompt, $articleBlock);

            if (trim($result->rawResponse) === '') {
                return $this->failInspirationStage(
                    $stage->id,
                    $token,
                    'Haiku trả về phản hồi rỗng',
                    $result->rawResponse,
                    $result->usage,
                );
            }

            $output = $this->inspirationOutput($input, $result->rawResponse, $articleBlock);

            if (! $this->stageStore->finishSucceeded(
                $stage->id,
                $token,
                $result->rawResponse,
                $output,
                $result->usage,
            )) {
                return $this->inspirationClaimLost($project->id, $input, $stage->id, $token, $result, $output);
            }

            return [$output, 'ok'];
        } catch (\Throwable $e) {
            Log::error('inspiration phase-1: the run failed', [
                'stage_id' => $stage->id,
                'article_id' => $project->article->id,
                'exception' => $e,
            ]);

            $billed = $e instanceof \App\Video\Llm\LlmUnavailable ? $e->billed : null;

            return $this->failInspirationStage(
                $stage->id,
                $token,
                $e->getMessage(),
                $result?->rawResponse ?? $billed?->text ?? '',
                $result?->usage ?? ($billed === null ? [] : ClaudeInspirationAnalyst::usageOf($billed, ClaudeInspirationAnalyst::instructionVersion($prompt))),
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

        $prompt = null;
        $articleBlock = null;
        $input = [];
        $runError = null;

        try {
            [$prompt, $articleBlock, $input] = $this->inspirationRun($project);
        } catch (\Throwable $e) {
            $this->quietLog('inspiration phase-1: prompt or article unavailable', $e, ['project_id' => $project->id]);
            $runError = 'Không lấy được prompt Phase 1 cho chủ đề này — chưa thể chạy lượt mới: '.$e->getMessage();
        }

        [$latest, $matchesInput] = $this->stageStore->latestStageForProject(
            $project->id,
            PlanningStageName::INSPIRATION,
            $input,
            skipOrphans: true,
        );
        $label = static fn (int $revision, string $workflow): string => 'Prompt '.ClaudeInspirationAnalyst::PHASE_CODE.' revision '.$revision.' ('.$workflow.')';
        $next = $prompt === null ? null : $label((int) $prompt->revision, (string) $input['workflow']);

        if ($latest === null) {
            return ['can_run' => $runError === null, 'prompt' => $next, 'run_error' => $runError] + $this->emptyInspiration();
        }

        $succeeded = $latest->status === VideoPlanningStageStatus::SUCCEEDED->value;
        $claimed = $latest->status === VideoPlanningStageStatus::RUNNING->value;
        $stored = $succeeded && is_array($latest->output_json) ? $latest->output_json : [];
        $phaseOne = ClaudeInspirationAnalyst::isPhaseOneRun($latest);
        $sourceCurrent = $phaseOne && $succeeded && $matchesInput && $runError === null && is_string($articleBlock);
        $sourceCheck = match (true) {
            $sourceCurrent => self::sourceCheckOf((string) ($stored['raw_text'] ?? ''), (string) $articleBlock),
            $phaseOne && is_array($stored['source'] ?? null) => $stored['source'],
            default => null,
        };
        $sourcePassed = $sourceCurrent && ($sourceCheck['verdict'] ?? null) === SourceCheck::RUN;
        $runInput = is_array($latest->input_json) ? $latest->input_json : [];
        [$parsed, $parseError] = $phaseOne && $succeeded ? self::parseForDisplay((string) ($stored['raw_text'] ?? '')) : [null, null];
        $pretty = static fn (mixed $value): string => (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'analysed' => $succeeded,
            'status' => $latest->status,
            'running' => $claimed && $latest->lease_expires_at?->isFuture() === true,
            'stuck' => $claimed && $latest->lease_expires_at?->isFuture() !== true,
            'error' => $latest->error_message,
            'can_run' => $runError === null && (! $matchesInput || $latest->status === VideoPlanningStageStatus::FAILED->value),
            'prompt' => $next,
            'run_error' => $runError,
            'source' => $phaseOne ? $label((int) ($runInput['phase_revision'] ?? 0), (string) ($runInput['workflow'] ?? '')) : null,
            'format' => $succeeded ? ($phaseOne ? 'phase1' : 'legacy') : null,
            'json' => ! $succeeded ? null : ($phaseOne ? ($parseError === null ? $pretty($parsed) : null) : $pretty($stored)),
            'json_error' => $parseError,
            'source_check' => $sourceCheck,
            'source_current' => $sourceCurrent,
            'source_passed' => $sourcePassed,
            'can_design' => $phaseOne ? $sourcePassed && VesselDesignService::READS_PHASE_ONE : $succeeded,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $output
     * @return array{0: null, 1: string}
     */
    private function inspirationClaimLost(
        string $projectId,
        array $input,
        string $stageId,
        string $lostToken,
        InspirationResult $result,
        array $output,
    ): array {
        $orphaned = false;

        try {
            $orphaned = $this->stageStore->recordOrphanAttempt(
                $projectId,
                PlanningStageName::INSPIRATION,
                $input,
                [],
                $stageId,
                $lostToken,
                'claim_lost',
                $result->usage,
                $result->rawResponse,
                $output,
            );
        } catch (\Throwable $e) {
            $this->quietLog('canonical-inspiration: recordOrphanAttempt threw', $e, ['stage_id' => $stageId]);
        }

        Log::warning('canonical-inspiration: claim lost after a paid call', [
            'project_id' => $projectId,
            'stage_id' => $stageId,
            'orphan_recorded' => $orphaned,
        ]);

        return [null, 'Lượt phân tích bị giành mất khi lưu — kết quả đã trả tiền không được dùng. Tải lại trang rồi bấm lại nếu cần.'];
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return array{0: null, 1: string}
     */
    private function failInspirationStage(
        string $stageId,
        string $claimToken,
        string $reason,
        string $rawResponse = '',
        array $usage = [],
    ): array {
        $this->stageStore->finishFailed(
            $stageId,
            $claimToken,
            $reason,
            $usage !== [] ? $usage : [
                'model' => ClaudeInspirationAnalyst::MODEL,
                'instruction_version' => ClaudeInspirationAnalyst::EXECUTION,
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
            [],
            skipOrphans: true,
        );

        if ($latest === null) {
            return [false, 'Chua co luot phan tich nao'];
        }

        return $this->stageStore->releaseClaim($latest->id, 'Nguoi dung reset thu cong')
            ? [true, 'ok']
            : [false, 'Luot nay khong con giu claim — khong co gi de reset'];
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

        if ($image->image_type === DesignImageStore::REFERENCE_TYPE) {
            [$retryable, $why] = app(ReferencePromptWriter::class)->retryable($image, $this->referenceAnchor($projectId));

            if (! $retryable) {
                return [null, $why];
            }
        }

        $spec = is_array($image->prompt_spec_json) ? $image->prompt_spec_json : [];

        if ($image->image_type === DesignImageStore::ANCHOR_TYPE && is_string($spec['design_stage_id'] ?? null)) {
            $size = ImageSize::tryFrom((string) ($spec['size'] ?? ''));

            if ($size === null) {
                return [null, 'anchor_prompt_unstamped'];
            }

            [$design, $why] = app(CharacterAnchorPromptService::class)->designPromptCheck($projectId, $spec, $size);

            if ($design === null) {
                return [null, $why];
            }
        }

        return $this->designImageDirectRenderer->renderNow($imageId);
    }

    /**
     * Nut Generate KHONG bien dich lai. Prompt da nam trong preview tu luot
     * Compile; form chi gui hash de chung minh nguoi dung dang nhin dung ban do.
     * Hash lech nghia la preview da bi dung lai o cho khac -> tu choi, khong tieu tien.
     *
     * @return array{0: ?VideoDesignImage, 1: string} [$image, $reason]
     *                                                reason: rendered|failed|timed_out|already_exists|
     *                                                anchor_prompt_missing|anchor_prompt_stale|anchor_prompt_size_unsupported|project_not_found
     */
    public function renderAnchorFromPreview(
        string $projectId,
        string $creator,
        string $promptHash,
        ImageSize $size,
        ImageModel $model,
        ImageQuality $quality,
        ImageVariations $variations,
        ?string $characterId = null,
    ): array {
        $preview = $this->anchorPromptPreview($projectId, $characterId);

        if ($preview === null) {
            return [null, 'anchor_prompt_missing'];
        }

        $designFirst = VesselDesign::isDesignFirst(VideoProject::query()->find($projectId));
        $designed = $characterId !== null && $designFirst;
        $prompt = $designed
            ? (app(CharacterAnchorPromptService::class)->previewPrompts($projectId, $preview)[$size->value] ?? null)
            : $preview['prompt'];

        if ($prompt === null) {
            return [null, 'anchor_prompt_size_unsupported'];
        }

        if (! hash_equals($designed ? hash('sha256', $prompt) : $preview['prompt_sha256'], $promptHash)) {
            return [null, 'anchor_prompt_stale'];
        }

        $subjectKey = null;
        $screenplayStageId = null;
        $source = [];

        if ($designed) {
            [$designStage, $designReason] = app(CharacterAnchorPromptService::class)->designPromptCheck(
                $projectId, ['prompt' => $prompt, 'prompt_sha256' => $promptHash, 'prompt_size' => $preview['size']] + $preview, $size,
            );

            if ($designStage === null) {
                return [null, $designReason];
            }

            $subjectKey = VesselDesign::subjectKey((string) $designStage->id);
            app(VesselDesignService::class)->ensureIdentity($designStage);
            $source = [
                'design_stage_id' => (string) $designStage->id,
                'design_content_hash' => VesselDesign::contentHash((array) $designStage->output_json),
                'anchor_prompt_stage_id' => (string) $preview['anchor_prompt_stage_id'],
                'prompt_sha256' => $promptHash,
                'prompt_size' => $preview['size'],
            ];
        } elseif ($characterId !== null) {
            $screenplayStage = app(CharacterAnchorPromptService::class)->screenplayStage($projectId);
            $screenplayStageId = $screenplayStage?->id;
            $previewStage = $preview['screenplay_stage_id'] ?? null;

            if ($screenplayStage === null
                || (is_string($previewStage) && $previewStage !== (string) $screenplayStageId)) {
                return [null, 'anchor_prompt_other_screenplay'];
            }

            $subjects = app(ScreenplaySubjectService::class);
            $subjectKey = $subjects->subjectKeyFor($screenplayStage, $characterId);

            if ($subjectKey === null) {
                return [null, 'character_not_main'];
            }

            $subjects->ensureIdentity($screenplayStage, $characterId);
            $source = ['screenplay_stage_id' => (string) $screenplayStageId];
        }

        // Bon enum nay da qua `tryFrom` trong `anchorPromptPreview()`, nen `from()`
        // o day khong the nem.
        [$image, $reason] = $this->createAnchorImage(
            $projectId,
            $creator,
            $prompt,
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
                'lineage' => $designed ? ['prompt_hash' => $promptHash] + $preview['lineage'] : $preview['lineage'],
                'negative_prompt' => $preview['negative_prompt'] ?? null,
                'native_controls' => [
                    'size' => $size->value,
                    'width' => $size->width(),
                    'height' => $size->height(),
                ],
            ] + ($characterId === null ? [] : [
                'character_id' => $preview['character_id'] ?? $characterId,
                'character_name' => $preview['character_name'] ?? $characterId,
                'character_kind' => $preview['character_kind'] ?? null,
                'subject_key' => $subjectKey,
            ] + $source),
        );

        return $image === null ? [null, $reason] : $this->designImageDirectRenderer->renderNow($image->id);
    }

    /**
     * Duong dan toi `DesignImageStore::approve()` — kiem quyen so huu va moi
     * quy tac nam trong do.
     *
     * @return array{0: bool, 1: string}
     */
    public function approveAnchor(string $projectId, string $artifactId, ?string $adminId): array
    {
        $image = VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::ANCHOR_TYPE)
            ->whereKey(VideoArtifact::query()->whereKey($artifactId)->value('design_image_id'))
            ->first();
        $spec = is_array($image?->prompt_spec_json) ? $image->prompt_spec_json : [];

        if ($image !== null && is_string($spec['design_stage_id'] ?? null)) {
            return app(VesselDesignService::class)->approveAnchor(
                $projectId,
                $image,
                $artifactId,
                $adminId,
                fn (): array => $this->designImageStore->approve(
                    $projectId, $artifactId, $adminId, null, DesignImageStore::ANCHOR_TYPE,
                ),
            );
        }

        if (is_string($spec['character_id'] ?? null)) {
            $project = VideoProject::query()->find($projectId);
            $screenplayStage = $project === null ? null : $this->selectedProductionScreenplay($project);
            $specStage = $spec['screenplay_stage_id'] ?? null;

            if ($screenplayStage === null
                || (is_string($specStage) && $specStage !== '' && $specStage !== (string) $screenplayStage->id)) {
                return [false, 'anchor_prompt_other_screenplay'];
            }

            $subjects = app(ScreenplaySubjectService::class);
            $subjectKey = $subjects->subjectKeyFor($screenplayStage, $spec['character_id']);

            if ($subjectKey === null) {
                return [false, 'character_not_main'];
            }

            if (! is_string($spec['subject_key'] ?? null)) {
                $subjects->ensureIdentity($screenplayStage, $spec['character_id']);
                $image->forceFill(['prompt_spec_json' => $spec + [
                    'subject_key' => $subjectKey,
                    'screenplay_stage_id' => (string) $screenplayStage->id,
                ]])->save();
            }
        }

        return $this->designImageStore->approve(
            $projectId, $artifactId, $adminId, null, DesignImageStore::ANCHOR_TYPE,
        );
    }

    /**
     * @return array{0: bool, 1: string}
     *                                   reason: approved|image_type_mismatch|artifact_not_found|
     *                                   image_not_found|not_approvable|project_not_found
     */
    public function approveReference(string $projectId, string $artifactId, ?string $adminId): array
    {
        return $this->designImageStore->approve(
            $projectId, $artifactId, $adminId, null, DesignImageStore::REFERENCE_TYPE,
        );
    }

    /**
     * @return array{0: bool, 1: string}
     */
    public function deleteReference(string $projectId, string $artifactId): array
    {
        return $this->designImageStore->deleteCandidate($projectId, $artifactId, DesignImageStore::REFERENCE_TYPE);
    }

    /**
     * @return array{0: bool, 1: string}
     *                                   reason: approved|image_type_mismatch|artifact_not_found|
     *                                   image_not_found|not_approvable|project_not_found
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
     *                                   reason: approved|candidate_outside_scene|artifact_not_in_candidate|
     *                                   image_type_mismatch|artifact_not_found|image_not_found|
     *                                   not_approvable|project_not_found
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

        if ($this->keyframeNeedsReview($candidate) !== null) {
            return [false, 'sources_changed'];
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
        $memo = [];

        foreach ($rows as $row) {
            $status = (string) $row->status;
            $key = (string) $row->render_scene_id;
            $cells[$key] ??= ['approved' => null, 'candidate' => null];

            if ($status === DesignImageStatus::APPROVED->value) {
                $cells[$key]['approved'] = $this->keyframeCellView($row, $cost)
                    + ['needs_review' => $this->keyframeReviewReason($row, $memo)];

                continue;
            }

            if (in_array($status, self::CURRENT_CANDIDATE_STATUSES, true)) {
                $cells[$key]['candidate'] = $this->keyframeCellView($row, $cost);
            }
        }

        return $cells;
    }

    /**
     * Trang thai clip cua tung scene, khoa theo render_scene_id giong keyframe.
     *
     * Clip thuoc ve SHOT chu khong thuoc scene, nen day la cho noi hai the gioi do
     * lai voi nhau cho man hinh doc duoc.
     *
     * @return array<string, array<string, mixed>>
     */
    public function sceneClipCells(string $projectId, int $revision): array
    {
        $shots = \App\Models\VideoShot::query()
            ->whereNotNull('scene_id')
            ->whereIn('scene_id', VideoRenderScene::query()
                ->where('project_id', $projectId)
                ->where('revision', $revision)
                ->select('id'))
            ->get();

        // Current answers "what is running"; selected answers "what will be cut".
        // They intentionally remain independent when a newer attempt fails.
        $productionRenders = \App\Models\VideoRender::query()
            ->whereIn('shot_id', $shots->pluck('id'))
            ->where('render_kind', 'video')
            ->where('execution_purpose', SceneClipDispatchService::PURPOSE_PRODUCTION)
            ->orderBy('attempt_no')
            ->get();
        $renders = $productionRenders
            ->keyBy(fn (\App\Models\VideoRender $render): string => (string) $render->id);
        $reconciler = app(ShotSelectionReconciler::class);

        $cells = [];

        foreach ($shots as $shot) {
            $current = $shot->current_render_id === null
                ? null
                : $renders->get((string) $shot->current_render_id);
            $selected = $shot->video_render_id === null
                ? null
                : $renders->get((string) $shot->video_render_id);
            $verdict = $selected === null ? null : $reconciler->verdict($shot, $selected);
            $options = $productionRenders
                ->where('shot_id', $shot->id)
                ->filter(static fn (\App\Models\VideoRender $render): bool => $render->execution_status === \App\Video\Render\Enums\RenderStatus::SUCCEEDED)
                ->map(function (\App\Models\VideoRender $render) use ($shot, $reconciler): array {
                    $candidate = $reconciler->verdict($shot, $render);

                    return [
                        'render_id' => (string) $render->id,
                        'attempt_no' => (int) $render->attempt_no,
                        'validity' => $candidate['status'],
                        'reasons' => $candidate['reasons'],
                    ];
                })
                ->values()
                ->all();

            $cells[(string) $shot->scene_id] = [
                'shot_id' => (string) $shot->id,
                'scene_status' => $shot->scene_status,
                'intent_version' => (int) $shot->intent_version,
                'render_id' => $current?->id,
                'status' => $current?->execution_status?->value,
                'poll_count' => (int) ($current?->provider_poll_count ?? 0),
                'error' => $current?->failure_message,
                'duration_ms' => $current?->duration_ms,
                'width' => $current?->width,
                'height' => $current?->height,
                'artifact_path' => $current?->artifact_path,
                'selected_render_id' => $selected?->id,
                'selected_status' => $selected?->execution_status?->value,
                'selected_duration_ms' => $selected?->duration_ms,
                'selected_width' => $selected?->width,
                'selected_height' => $selected?->height,
                'selected_artifact_path' => $selected?->artifact_path,
                'selected_validity' => $verdict['status'] ?? null,
                'selected_validity_reasons' => $verdict['reasons'] ?? [],
                'selected_fingerprint' => $verdict['fingerprint'] ?? null,
                'successful_options' => $options,
            ];
        }

        return $cells;
    }

    /**
     * Moi thu man Final Composition can, ghep tu CHINH cac o ma man Clips dang dung.
     *
     * Khong co truy van rieng cho man nay: lech giua hai man la thu chi lo ra khi
     * co nguoi so hai ben, va luc do thi da muon.
     *
     * @return array<string, mixed>
     */
    public function finalCompositionCells(string $projectId): array
    {
        $project = VideoProject::query()->whereKey($projectId)->first();
        $stage = $project?->selectedScenePlanStage()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::SCENE_PLAN->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->first();
        $review = $this->reviewForRevision($stage);
        $revision = $review['status'] === 'passed' && is_array($stage?->output_json)
            ? (int) ($stage->output_json['revision'] ?? 0)
            : 0;
        $rows = $revision === 0
            ? collect()
            : VideoRenderScene::query()
                ->where('project_id', $projectId)
                ->where('revision', $revision)
                ->where('screenplay_stage_id', $project?->selected_screenplay_stage_id)
                ->orderBy('scene_index')
                ->get();
        [$profile] = $this->profileForRevision($stage);
        [$preservation] = $this->preservationForRevision($stage);
        $scenes = $rows
            ->map(fn (VideoRenderScene $scene): array => $this->sceneView($scene, $profile, $preservation))
            ->all();

        $filmCut = FilmCutAuthor::isFilmCut($stage?->input_json['scene_contract_version'] ?? null);
        $clipCells = $revision === 0 ? [] : $this->sceneClipCells($projectId, $revision);
        $keyframeCells = $revision === 0 ? [] : $this->sceneKeyframeCells($projectId, $revision);
        $segments = $filmCut ? collect($this->filmSegments($projectId, $revision))->keyBy(static fn (array $item): string => (string) $item['owner']->id) : collect();

        $clips = [];

        foreach ($scenes as $scene) {
            $key = (string) ($scene['scene_id'] ?? '');
            $cell = $clipCells[$key] ?? null;

            // CHI clip da dung xong moi vao duoc final. Luot dang chay hay that bai
            // khong co file de ghep, nen no khong phai "da duyet" theo nghia nao ca.
            if (($cell['selected_status'] ?? null) !== 'succeeded'
                || ($cell['selected_render_id'] ?? null) === null
                || ($cell['selected_validity'] ?? null) !== 'valid') {
                continue;
            }

            if ($filmCut && ! $segments->has($key)) {
                continue;
            }

            $artifact = $keyframeCells[$key]['approved']['artifacts'][0] ?? null;

            $clips[] = [
                'ordinal' => count($clips) + 1,
                'scene_code' => (string) ($scene['id'] ?? ''),
                'title' => $filmCut ? (string) $segments[$key]['title'] : (string) ($scene['title'] ?? ''),
                'render_id' => (string) $cell['selected_render_id'],
                'duration_ms' => (int) ($cell['selected_duration_ms'] ?? 0),
                'used_ms' => $filmCut || ! is_int($scene['used_ms'] ?? null) ? null : $scene['used_ms'],
                'used_start_ms' => $filmCut || ! is_int($scene['used_start_ms'] ?? null) ? 0 : $scene['used_start_ms'],
                'width' => (int) ($cell['selected_width'] ?? 0),
                'height' => (int) ($cell['selected_height'] ?? 0),
                'thumbnail_url' => $artifact['url'] ?? null,
                'file_url' => route('video-projects.scene-clip-file', [$projectId, $cell['selected_render_id']]),
            ];
        }

        $finals = VideoFinal::query()
            ->whereIn('session_id', VideoSession::query()
                ->where('project_id', $projectId)
                ->select('id'))
            ->with(['session', 'cuts'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (VideoFinal $row) => $this->finalRowView($row))
            ->all();

        // Cac clip KHONG bat buoc cung mot co: cung mot du an van co clip 720x1280
        // lan 1080x1920. Lay co cua clip dau roi goi do la "co nguon" la noi sai —
        // man hinh phai noi ra rang chung lech nhau.
        $sizes = array_values(array_unique(array_map(
            static fn (array $clip) => $clip['width'].' × '.$clip['height'],
            array_filter($clips, static fn (array $clip) => $clip['width'] > 0 && $clip['height'] > 0),
        )));

        $blocked = match (true) {
            $filmCut && $segments->isEmpty() => 'Bản '.$revision.' là phim 30 giây theo định dạng cũ (AI chia shot) — lập lại kế hoạch phim 30 giây từ phân cảnh.',
            $filmCut && count($clips) !== $segments->count() => 'Phim 30 giây cần đủ '.$segments->count().' đoạn có clip hợp lệ của cùng bản '.$revision.'; đang có '.count($clips).'. Chưa xuất bản final.',
            default => null,
        };

        return [
            'revision' => $revision,
            'blocked' => $blocked,
            'clips' => $clips,
            'total_duration_ms' => array_sum(array_column($clips, 'duration_ms')),
            'sizes' => $sizes,
            'uniform_size' => count($sizes) === 1 ? $sizes[0] : null,
            'finals' => $finals,
            'latest_final' => $finals[0] ?? null,
        ];
    }

    /**
     * Ghep ban final cho mot du an, bang duong FFmpeg cua Laravel.
     *
     * Chay DONG BO trong request. Ngan sach thoi gian bi CHAN TREN boi
     * `max_execution_time` cua PHP (xem `AppServiceProvider`): dat 900 giay trong khi
     * PHP giet request o 120 khong cho ta them thoi gian nao, no chi khien ta khong
     * biet minh da bi giet.
     *
     * @param  array<string, mixed>  $options  width/height/fps/crf/crossfade_frames
     * @return array{ok: bool, error?: string, final?: VideoFinal, reasons?: list<string>}
     */
    public function renderFinalComposition(string $projectId, array $options): array
    {
        // Ngan sach bat dau TU DAY chu khong tu luc vao executor: truy van, doc dia va
        // bam sha256 tung clip nguon deu an vao cung mot han cua PHP. Dat dong ho o
        // giua luot thi phan da tieu truoc do khong ai tru di.
        $executor = app(CompositionExecutor::class);
        $deadline = Deadline::in($executor->budgetSeconds());
        $cells = $this->finalCompositionCells($projectId);

        if ($cells['blocked'] !== null) {
            return ['ok' => false, 'error' => $cells['blocked']];
        }

        if ($cells['clips'] === []) {
            return ['ok' => false, 'error' => 'chua co clip nao dung xong de ghep'];
        }

        // Nap mot lo: `find()` trong vong lap la mot truy van moi clip, va
        // `finalCompositionCells()` vua doc dung nhung hang nay xong.
        $renders = VideoRender::query()
            ->whereIn('id', array_column($cells['clips'], 'render_id'))
            ->with('shot')
            ->get()
            ->keyBy(fn (VideoRender $render) => (string) $render->id);

        // Session lay QUA SHOT chu khong qua du an: `SceneClipDispatchService` tao
        // render voi `sessionId: null`, nen render khong tu noi no thuoc session nao.
        $sessions = $renders
            ->map(fn (VideoRender $render) => (string) optional($render->shot)->session_id)
            ->unique()
            ->filter()
            ->values();

        if ($sessions->count() !== 1) {
            // KHONG tu chon mot session hay tu tao mot cai moi: `video_finals` khoa
            // theo session, va doan sai o day la gan ban final vao nham cho.
            return ['ok' => false, 'error' => sprintf(
                'cac clip thuoc %d session khac nhau — muc nay chi ghep duoc trong mot session',
                $sessions->count(),
            )];
        }

        $sessionId = (string) $sessions->first();

        $selectedPlan = VideoProject::query()->find($projectId)?->selectedScenePlanStage()->first();
        $cutProblem = $this->usedCutProblem($cells['clips'], $options + [
            'film_total_ms' => (int) ($selectedPlan?->input_json['film']['total_ms'] ?? 0),
        ]);

        if ($cutProblem !== null) {
            return ['ok' => false, 'error' => $cutProblem];
        }

        $disk = app(\Illuminate\Contracts\Filesystem\Factory::class)->disk((string) config('video.veo.disk'));
        $sources = [];
        $clipRows = [];

        foreach ($cells['clips'] as $clip) {
            $render = $renders->get((string) $clip['render_id']);
            $relative = (string) optional($render)->artifact_path;
            $path = $disk->path($relative);

            if ($relative === '' || ! is_file($path)) {
                return ['ok' => false, 'error' => 'thieu file clip tren dia: '.$clip['scene_code']];
            }

            // Hash KY VONG lay tu `primary_artifact_hash` — thu `RenderCheckpointService`
            // da ghi lai ngay luc render xong. Bam lai file o day la lay chinh file
            // dang nghi ngo lam chuan: file bi thay giua luc render va luc bam se di
            // qua em, vi ca hai ve cua phep so deu doc cung mot noi dung.
            $expected = (string) optional($render)->primary_artifact_hash;

            if (preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
                return ['ok' => false, 'error' => sprintf(
                    'clip %s chua co hash artifact da luu — khong doi chieu duoc noi dung se ghep',
                    $clip['scene_code'],
                )];
            }

            $sources[] = $path;
            $clipRows[] = $clip + [
                'artifact_path' => $relative,
                'sha256' => $expected,
            ];
        }

        // Bam xong ma het gio thi DUNG O DAY: tao hang `composing` roi de executor tu
        // choi ngay sau do chi de lai mot hang `failed` khong noi len dieu gi.
        if ($deadline->expired()) {
            return ['ok' => false, 'error' => 'het ngan sach thoi gian ngay khi chuan bi nguon'];
        }

        $manifest = $this->finalCompositionManifest($clipRows, $options);

        // Kiem luot dang chay VA cap so ban trong CUNG mot transaction, tren mot hang
        // session da khoa. Kiem truoc roi tao sau thi hai request dong thoi deu vuot
        // qua duoc phep kiem, cung chay ffmpeg, va cung tinh ra `max(revision) + 1`.
        //
        // Khoa chi giu trong vai mili giay — ffmpeg chay NGOAI transaction.
        try {
            $final = DB::transaction(function () use ($sessionId, $manifest): VideoFinal {
                if (VideoSession::query()->whereKey($sessionId)->lockForUpdate()->first() === null) {
                    throw new RuntimeException('khong tim thay session cua cac clip nay');
                }

                $blocked = $this->blockingComposition($sessionId);

                if ($blocked !== null) {
                    throw new RuntimeException($blocked);
                }

                return VideoFinal::query()->create([
                    'session_id' => $sessionId,
                    'revision' => ((int) VideoFinal::query()->where('session_id', $sessionId)->max('revision')) + 1,
                    'status' => 'composing',
                    'plan_json' => $manifest,
                    'manifest_hash' => hash('sha256', $this->canonicalJson($manifest)),
                    'frozen_at' => now(),
                ]);
            });
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        $result = $executor->execute($manifest, $sources, $deadline, new CompositionRun(
            finalId: (string) $final->id,
            manifestHash: (string) $final->manifest_hash,
        ));

        // Luot dung giua chung nhung DA co output di qua verifier — receipt thi co the
        // chua ghi duoc. Day khong phai that bai: ha `failed` la ket luan hong cho mot
        // file da do dat, va doi phuc hoi chi quet hang con `composing` nen se khong
        // bao gio nhin toi no nua. Thieu receipt chi lam doi phuc hoi bao
        // `needs_attention`, khong bien output thanh rac. Giu nguyen trang, chi bao ra.
        if (! $result->successful && $result->unresolved) {
            return [
                'ok' => false,
                'error' => 'ghep xong nhung luot chay khong ket thuc duoc — chay `php artisan video:final-recover` de doi soat',
                'reasons' => $result->reasons,
                'final' => $this->refreshed($final),
            ];
        }

        if (! $result->successful) {
            // Chua co output hop le. Ha `failed` CO DIEU KIEN, va mot loi DB o day
            // khong duoc phep thay the ly do that su cua ffmpeg trong cau tra loi.
            $this->failComposition($final, implode(' | ', $result->reasons));

            return [
                'ok' => false,
                'error' => 'ghep that bai',
                'reasons' => $result->reasons,
                'final' => $this->refreshed($final),
            ];
        }

        try {
            $saved = $this->recordComposedFinal($final, $clipRows, $result);
        } catch (Throwable $e) {
            // KHONG xoa output, KHONG ha trang thai. Den day file da qua verify, va
            // mot loi DB khong chung minh duoc file hong. Hang bi ket o `composing`
            // thi con sua duoc; file da xoa thi khong lay lai duoc.
            return [
                'ok' => false,
                'error' => 'ghep xong nhung khong luu duoc ket qua',
                'reasons' => [$e->getMessage()],
                'final' => $this->refreshed($final),
            ];
        }

        // Don lich su chay SAU khi da commit va NGOAI nhanh xu ly that bai: loi o buoc
        // don khong duoc phep bi doc thanh "luu ket qua that bai", vi nhanh do se doi
        // xu voi mot ban final da `ready` nhu voi mot ban chua bao gio luu duoc.
        $this->pruneQuietly($saved);

        return ['ok' => true, 'final' => $saved];
    }

    /**
     * Ha mot luot ve `failed` ma khong nem tiep.
     *
     * Co dieu kien `composing`: luot da bi cho khac ha xuong thi khong ghi de len ly
     * do cua ho.
     */
    private function failComposition(VideoFinal $final, string $reason): void
    {
        try {
            VideoFinal::query()
                ->whereKey($final->id)
                ->where('status', 'composing')
                ->update(['status' => 'failed', 'error_message' => Str::limit($reason, 1000)]);
        } catch (Throwable $e) {
            $this->logQuietly('final composition: khong ha duoc trang thai that bai', [
                'final_id' => (string) $final->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * `refresh()` cung la mot truy van: goi no trong nhanh dang xu ly loi DB la de
     * chinh cai loi do nem de len, va nguoi dung mat luon thong bao that.
     */
    private function refreshed(VideoFinal $final): VideoFinal
    {
        try {
            return $final->refresh();
        } catch (Throwable) {
            return $final;
        }
    }

    private function pruneQuietly(VideoFinal $final): void
    {
        try {
            $this->pruneOldFinals($final);
        } catch (Throwable $e) {
            $this->logQuietly('final composition: don lich su that bai', [
                'final_id' => (string) $final->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ghi log cung co the nem (dia day, handler hong). Trong cac nhanh cuu loi thi
     * mot log that bai khong duoc phep tro thanh loi duy nhat nguoi dung nhin thay.
     *
     * @param  array<string, mixed>  $context
     */
    private function logQuietly(string $message, array $context): void
    {
        try {
            Log::warning($message, $context);
        } catch (Throwable) {
            // khong con cho nao de bao nua
        }
    }

    /**
     * Ly do khong duoc bat dau luot moi, hoac `null`.
     *
     * Mot hang `composing` chi chan khi no CON CO THE dang chay. Tien trinh bi giet
     * giua chung — het `max_execution_time`, may chu khoi dong lai — de lai mot hang
     * `composing` vinh vien, va mot phep kiem "co hang composing thi tu choi" se
     * khoa CHET man hinh ma khong co loi thoat nao tu giao dien.
     *
     * Nguong la ngan sach cong bien: mot luot bat dau lau hon ngan sach toi da thi
     * khong the con dang chay.
     */
    private function blockingComposition(string $sessionId): ?string
    {
        $budget = (int) config('video.veo.compose_budget_seconds');
        $stale = now()->subSeconds($budget + 60);
        $running = VideoFinal::query()
            ->where('session_id', $sessionId)
            ->where('status', 'composing')
            ->get();

        foreach ($running as $row) {
            if ($row->updated_at === null || $row->updated_at->greaterThanOrEqualTo($stale)) {
                return 'dang co mot luot ghep chay do dang cho session nay';
            }

            // Qua han KHONG dong nghia voi hong. Luot co the da ghep xong, da do dat,
            // va chi chet o doan ghi DB — luc do tren dia con ca output lan receipt.
            // Ha thang `failed` o day la chon mat dung cai bang chung do.
            //
            // Phep kiem nay phai RE: no chay duoi khoa hang session. Doc mot file JSON
            // vai tram byte va mot `is_file()`, khong bam, khong probe. Viec xac minh
            // that su la cua `video:final-recover`, chay ngoai transaction.
            if ($this->leftEvidence($row)) {
                return sprintf(
                    'luot ghep %s da dung nhung de lai dau vet chua doi soat — chay `php artisan video:final-recover` truoc',
                    $row->id,
                );
            }

            $row->update([
                'status' => 'failed',
                'error_message' => 'luot ghep bi bo do: khong ket thuc trong ngan sach thoi gian',
            ]);
        }

        return null;
    }

    /**
     * Luot nay co de lai dau vet nao tren dia khong?
     *
     * BAT KY dau vet nao cung du: file ket qua, receipt, hay ca receipt dang viet do
     * dang. Doc noi dung receipt de quyet dinh o day la sai huong — receipt thieu
     * hoac hong KHONG phai bang chung rang luot da that bai, ma chi la ly do phai
     * nhin ky hon. Ha `failed` vi thieu bang chung la dong luon duong doi soat: doi
     * phuc hoi chi quet hang con `composing`.
     *
     * Phep kiem nay chay duoi khoa hang session nen chi duoc phep re: ba lan
     * `is_file()`, khong doc, khong bam, khong probe.
     */
    private function leftEvidence(VideoFinal $final): bool
    {
        $directory = $this->runDirectory($final);

        if ($directory === null) {
            return false;
        }

        foreach ([CompositionReceipt::OUTPUT_NAME, CompositionReceipt::FILE, CompositionReceipt::FILE.'.tmp'] as $name) {
            if (is_file($directory.DIRECTORY_SEPARATOR.$name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Thu muc luot chay, suy tu final ID.
     *
     * Khong luu thanh cot: mot duong dan suy ra duoc ma van luu lai la mot cho nua co
     * the lech. `realpath` ca hai ve roi kiem tien to — `storage_path()` tra ve dau
     * phan cach lan lon trong khi `realpath()` chuan hoa het, nen so chuoi thang se
     * bao sai cho mot duong dan hoan toan hop le.
     */
    private function runDirectory(VideoFinal $final): ?string
    {
        $root = realpath((string) config('video.veo.compose_final_dir'));

        if ($root === false) {
            return null;
        }

        $root = rtrim($root, '/\\').DIRECTORY_SEPARATOR;
        $directory = realpath($root.(string) $final->id);

        return $directory !== false && str_starts_with($directory, $root) ? $directory : null;
    }

    /**
     * JSON CHUAN HOA: khoa sap xep de quy, thu tu danh sach giu nguyen.
     *
     * `json_encode` giu thu tu khoa theo thu tu ta viet mang, nen doi cho hai dong
     * trong mot ham se doi hash cua mot ke hoach khong he doi.
     */
    private function canonicalJson(mixed $value): string
    {
        if (is_array($value) && ! array_is_list($value)) {
            ksort($value);
        }

        if (is_array($value)) {
            $value = array_map(fn (mixed $item) => is_array($item) ? json_decode($this->canonicalJson($item), true) : $item, $value);

            if (! array_is_list($value)) {
                ksort($value);
            }
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, mixed>  $clip
     * @return array{0: int, 1: int} [$trimStartMs, $durationMs] the part of the clip the storyboard uses; the whole clip only for a shot planned without a cut
     */
    private function usedCut(array $clip): array
    {
        $used = $clip['used_ms'] ?? null;

        if ($used === null) {
            return [0, (int) $clip['duration_ms']];
        }

        $start = is_int($clip['used_start_ms'] ?? null) ? $clip['used_start_ms'] : 0;

        if (! is_int($used) || $used <= 0 || $start < 0 || $start + $used > (int) $clip['duration_ms']) {
            throw new RuntimeException('Clip '.($clip['scene_code'] ?? '?').' has no valid cut; usedCutProblem() must refuse it first.');
        }

        return [$start, $used];
    }

    /**
     * @param  list<array<string, mixed>>  $clips
     * @param  array<string, mixed>  $options  the edit settings: fps and crossfade_frames
     * @return string|null why the clips cannot be cut into the film; null when every planned cut fits its clip and the cut film keeps its length
     */
    private function usedCutProblem(array $clips, array $options = []): ?string
    {
        $planned = array_values(array_filter($clips, static fn (array $clip): bool => ($clip['used_ms'] ?? null) !== null));

        foreach ($planned as $clip) {
            $start = $clip['used_start_ms'] ?? null;
            $used = $clip['used_ms'];
            $length = (int) $clip['duration_ms'];

            if (! is_int($start) || ! is_int($used) || $start < 0 || $used <= 0 || $start + $used > $length) {
                return sprintf('shot %s: đoạn dùng %s–%s ms nằm ngoài clip dài %d ms — render lại shot này hoặc lập lại storyboard; chưa ghép gì',
                    (string) ($clip['scene_code'] ?? '?'), var_export($start, true), is_int($start) && is_int($used) ? (string) ($start + $used) : '?', $length);
            }
        }

        if ($planned === []) {
            return null;
        }

        if (count($planned) !== count($clips)) {
            return 'bản dựng trộn shot có điểm cắt và shot không có điểm cắt — không giữ được timeline; chưa ghép gì';
        }

        if ((int) ($options['crossfade_frames'] ?? 0) > 0) {
            return 'phim theo storyboard chỉ cắt thẳng: chuyển cảnh mờ chồng hai clip lên nhau và làm phim ngắn hơn timeline đã duyệt — bỏ chọn chuyển cảnh; chưa ghép gì';
        }

        $total = (int) ($options['film_total_ms'] ?? 0);
        $cut = array_sum(array_column($planned, 'used_ms'));

        if ($total > 0 && $cut !== $total) {
            return sprintf('các shot đã có clip cộng lại %d ms, phim dài đúng %d ms — còn shot chưa render xong hoặc lệch timeline; chưa ghép gì', $cut, $total);
        }

        $fps = (int) ($options['fps'] ?? 24);
        $frames = array_sum(array_map(static fn (array $clip): int => (int) round($clip['used_ms'] * $fps / 1000), $planned));
        $wanted = (int) round($total * $fps / 1000);

        return $total > 0 && $fps > 0 && $frames !== $wanted
            ? sprintf('ở %d khung/giây các đoạn cắt làm tròn thành %d khung, phim cần đúng %d khung — chỉnh điểm cắt cho tròn khung; chưa ghép gì', $fps, $frames, $wanted)
            : null;
    }

    /**
     * @param  list<array<string, mixed>>  $clips
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function finalCompositionManifest(array $clips, array $options): array
    {
        $fps = (int) ($options['fps'] ?? 24);
        $crossfade = (int) ($options['crossfade_frames'] ?? 0);
        $last = count($clips) - 1;
        $entries = [];

        foreach ($clips as $i => $clip) {
            $cut = $this->usedCut($clip);
            $entry = [
                // Danh tinh clip nam TRONG manifest, tuc la nam trong `manifest_hash`.
                // De no o mot cho khac — receipt chang han — thi doi phuc hoi chi con
                // mot danh sach `render_id` khong doi chieu lai duoc voi ban da chot.
                'render_id' => (string) $clip['render_id'],
                'sequence_no' => $i + 1,
                'path' => $clip['artifact_path'],
                'sha256' => $clip['sha256'],
                'trim_start_ms' => $cut[0],
                'duration_ms' => $cut[1],
            ];

            // Clip cuoi khong co moi noi nao phia sau — builder tu choi neu co.
            if ($i < $last && $crossfade > 0) {
                $entry['transition_after'] = ['type' => 'crossfade', 'frames' => $crossfade];
            }

            $entries[] = $entry;
        }

        return [
            'engine' => VideoFinal::ENGINE_LARAVEL,
            'output' => [
                'width' => (int) ($options['width'] ?? 1080),
                'height' => (int) ($options['height'] ?? 1920),
                'fps' => $fps,
                'crf' => (int) ($options['crf'] ?? 18),
            ],
            'clips' => $entries,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $clips
     */
    private function recordComposedFinal(VideoFinal $final, array $clips, CompositionResult $result): VideoFinal
    {
        $cuts = [];

        foreach ($clips as $i => $clip) {
            // `start_ms` la VI TRI TREN DONG THOI GIAN, lay tu `CompositionPlan`.
            // Cong don do dai tung clip o day se sai ngay khi co mot chuyen canh mo:
            // clip ke leo len phan chong lan cua clip truoc.
            $row = $result->timeline[$i] ?? ['start_ms' => 0, 'duration_ms' => 0];

            $cuts[] = [
                'render_id' => (string) $clip['render_id'],
                'sequence_no' => $i + 1,
                'start_ms' => (int) $row['start_ms'],
                'duration_ms' => (int) $row['duration_ms'],
            ];
        }

        // Phep hoan tat song o `FinalCompositionReconciler`: duong chay that va doi
        // phuc hoi phai dung DUNG mot transaction, khong phai hai ban giong nhau.
        return app(FinalCompositionReconciler::class)->complete(
            $final, $cuts, (string) $result->path, (int) $result->frames,
        );
    }

    /**
     * Xoa FILE cua cac ban final cu, giu lai N ban gan nhat cua session.
     *
     * Moi luot ghep de lai mot file hang chuc MB va khong ai don: sau hai muoi lan
     * render o 1080x1920 la vai tram MB nam yen tren dia.
     *
     * Chi xoa file, KHONG xoa hang: lich su render la mot ban ghi, va man hinh da
     * biet hien "khong co file" cho hang khong con file.
     */
    private function pruneOldFinals(VideoFinal $final): void
    {
        $keep = max(1, (int) config('video.veo.compose_keep_finals'));
        $root = realpath((string) config('video.veo.compose_final_dir'));

        if ($root === false) {
            return;
        }

        $root = rtrim($root, '/\\').DIRECTORY_SEPARATOR;
        $stale = VideoFinal::query()
            ->where('session_id', $final->session_id)
            ->whereNotNull('video_path')
            ->orderByDesc('created_at')
            ->skip($keep)
            ->take(100)
            ->get();

        foreach ($stale as $row) {
            $path = realpath($root.(string) $row->video_path);

            if ($path === false || ! str_starts_with($path, $root)) {
                continue;
            }

            if (! @unlink($path)) {
                continue;
            }

            // Receipt di theo output. Bo lai thi `rmdir` luon that bai va moi thu muc
            // da don van nam do voi mot file bang chung cho mot final khong con file.
            @unlink(dirname($path).DIRECTORY_SEPARATOR.CompositionReceipt::FILE);
            @rmdir(dirname($path));
        }
    }

    /**
     * `video_path` la duong dan TUONG DOI trong `compose_final_dir`, khong phai duong
     * public. File ra nam ngoai `public/` co chu dich: no chi den duoc qua mot route
     * co kiem quyen du an, giong het duong cua clip.
     *
     * File co the chua ton tai (ban final that bai, hoac da bi don), nen duong dan
     * chi thanh URL khi doc duoc.
     *
     * @return array<string, mixed>
     */
    private function finalRowView(VideoFinal $row): array
    {
        $path = trim((string) $row->video_path);
        $projectId = (string) optional($row->session)->project_id;
        $root = realpath((string) config('video.veo.compose_final_dir'));
        $playable = $path !== '' && $projectId !== '' && $root !== false
            && is_file(rtrim($root, '/\\').DIRECTORY_SEPARATOR.$path);

        return [
            'id' => (string) $row->id,
            'status' => (string) $row->status,
            'file_name' => $path === '' ? null : basename($path),
            'video_url' => $playable ? route('video-projects.final-file', [$projectId, $row->id]) : null,
            'duration_seconds' => (int) $row->duration_seconds,
            'width' => (int) data_get($row->plan_json, 'output.width', 0),
            'height' => (int) data_get($row->plan_json, 'output.height', 0),
            'created_at' => $row->created_at?->format('Y-m-d H:i'),
            // Moc bat dau cua tung clip TRONG ban final nay, khoa theo `render_id`.
            //
            // Timeline tren man hinh ve theo do dai cac clip cong lai, con ban final
            // co chuyen canh mo nen ngan hon. Muon bam mot khoi ma nhay dung cho
            // trong video thi phai lay moc tu chinh ban final, khong suy ra tu do
            // dai clip.
            'cuts' => $row->cuts->mapWithKeys(
                fn ($cut) => [(string) $cut->render_id => (int) $cut->start_ms],
            )->all(),
        ];
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
     * @param  list<array<string, mixed>>  $scenes  shot views of one revision
     * @param  array<string, array<string, mixed>>  $cells  the source cells the render path resolved, by scene id
     * @return array{shots: array<string, array{ready: bool, needs: list<array<string, mixed>>}>, ready: int, planned: int}
     */
    public function referenceReadiness(string $projectId, int $revision, array $scenes, array $cells): array
    {
        $roles = [
            'environment' => ['environment'],
            'identity' => ['anchor', 'identity', 'space_geometry'],
            'design' => ['design_reference', 'geometry'],
            'continuity' => ['continuity', 'source_keyframe'],
        ];
        $stage = $revision < 1 ? null : $this->stageStore->stageForProjectRevision($projectId, PlanningStageName::SCENE_PLAN, $revision);
        $designStageId = is_string($stage?->input_json['design_stage_id'] ?? null) ? $stage->input_json['design_stage_id'] : null;
        $subjectKey = $designStageId === null ? null : VesselDesign::subjectKey($designStageId);
        $artifactIds = [];

        foreach ($cells as $cell) {
            foreach ([...(array) ($cell['slots'] ?? []), ...(array) ($cell['references']['options'] ?? [])] as $item) {
                if (is_string($item['artifact_id'] ?? null)) {
                    $artifactIds[$item['artifact_id']] = true;
                }
            }
        }

        foreach ($cells as $cell) {
            if (is_string($cell['room']['artifact_id'] ?? null)) {
                $artifactIds[$cell['room']['artifact_id']] = true;
            }
        }

        $facts = $this->referenceImageFacts($projectId, $revision, array_keys($artifactIds));
        $planLinks = $this->referencePlanLinks($projectId, $stage);
        $shas = VideoArtifact::query()->whereIn('id', array_keys($artifactIds))->pluck('sha256', 'id')->map(static fn (mixed $sha): string => (string) $sha)->all();
        $out = [];
        $ready = 0;
        $previous = null;

        foreach ($scenes as $scene) {
            $code = (string) ($scene['id'] ?? '');

            if (! ($scene['shot_board'] ?? false)) {
                $previous = $code;

                continue;
            }

            $cell = $cells[(string) $scene['scene_id']] ?? ['slots' => [], 'blocked_reason' => 'scene_plan_unverifiable', 'references' => null, 'room' => null];
            $blocked = is_string($cell['blocked_reason'] ?? null) ? explode('|', $cell['blocked_reason'], 2)[0] : null;
            $needs = [];

            foreach (array_values((array) ($scene['reference_requirements'] ?? [])) as $index => $need) {
                $kind = (string) ($need['kind'] ?? '');
                $wanted = $roles[$kind] ?? [];
                $expectedShot = $kind === 'continuity' ? ($need['source_shot'] ?? $previous) : null;
                $misfit = static function (string $artifactId) use ($facts, $kind, $need, $expectedShot, $subjectKey): ?string {
                    $fact = $facts[$artifactId] ?? [];

                    if ($kind === 'continuity') {
                        return $expectedShot !== null && ($fact['shot'] ?? null) === $expectedShot ? null : 'source_shot';
                    }

                    if (in_array($kind, ScenePlanAuthor::VIEWED_REFERENCE_KINDS, true)) {
                        if ($subjectKey !== null && ($fact['subject_key'] ?? null) !== $subjectKey) {
                            return 'design_revision';
                        }

                        if (($need['view'] ?? null) !== null && ($fact['view'] ?? null) !== $need['view']) {
                            return 'view';
                        }
                    }

                    return null;
                };
                $slots = array_values(array_filter((array) ($cell['slots'] ?? []), static fn (array $slot): bool => in_array($slot['role'] ?? null, $wanted, true)));
                $options = array_values(array_filter(
                    (array) ($cell['references']['options'] ?? []),
                    static fn (array $option): bool => array_intersect((array) ($option['roles'] ?? []), $wanted) !== [],
                ));
                $slotMisfits = array_map(static fn (array $slot): ?string => $misfit((string) $slot['artifact_id']), $slots);
                $optionFits = array_filter($options, static fn (array $option): bool => $misfit((string) $option['artifact_id']) === null);
                $status = match (true) {
                    in_array(null, $slotMisfits, true) => 'available',
                    $kind === 'environment' && is_array($cell['room'] ?? null) => is_string($cell['room']['url'] ?? null) ? 'available' : 'missing',
                    $kind === 'environment' && $blocked === null && $slots === [] => 'not_applicable',
                    $kind === 'continuity' && $blocked !== null && str_starts_with($blocked, 'previous_keyframe') => 'waiting_source',
                    $blocked === null && $optionFits !== [] => 'needs_selection',
                    $slotMisfits !== [] => 'mismatch',
                    default => 'missing',
                };
                $fitting = [];

                foreach ($slots as $position => $slot) {
                    if ($slotMisfits[$position] === null) {
                        $fitting[] = (string) $slot['artifact_id'];
                    }
                }

                if ($kind === 'environment' && is_string($cell['room']['artifact_id'] ?? null)) {
                    $fitting[] = $cell['room']['artifact_id'];
                }

                $unchecked = array_values(array_filter([
                    ($need['state'] ?? null) !== null ? 'state' : null,
                    ($need['design_refs'] ?? []) !== [] ? 'design_refs' : null,
                ]));
                $confirmation = is_array($scene['reference_confirmations'][$index] ?? null) ? $scene['reference_confirmations'][$index] : null;
                $confirmed = $unchecked !== [] && $confirmation !== null
                    && hash_equals($this->referenceNeedHash($need), (string) ($confirmation['need_sha256'] ?? ''))
                    && in_array($confirmation['artifact_id'] ?? null, $fitting, true)
                    && hash_equals((string) ($shas[$confirmation['artifact_id']] ?? ''), (string) ($confirmation['sha256'] ?? ''));
                $planned = $planLinks[$code.'#'.$index] ?? null;

                if (! $confirmed && $unchecked !== [] && $planned !== null
                    && hash_equals($planned['need_sha256'], $this->referenceNeedHash($need))
                    && array_intersect($planned['artifact_ids'], $fitting) !== []) {
                    $confirmed = true;
                    $confirmation = ['by' => ReferencePromptWriter::PLAN_MODE];
                }

                if ($status === 'available' && $unchecked !== [] && ! $confirmed) {
                    $status = 'needs_confirmation';
                }

                $needs[] = $need + [
                    'index' => $index,
                    'status' => $status,
                    'misfits' => array_values(array_unique(array_filter($slotMisfits, 'is_string'))),
                    'unchecked' => $unchecked,
                    'fitting_artifact_ids' => array_values(array_unique($fitting)),
                    'confirmed_by' => $confirmed ? ($confirmation['by'] ?? null) : null,
                ];
            }

            $shotReady = $blocked === null
                && array_filter($needs, static fn (array $need): bool => ! in_array($need['status'], ['available', 'not_applicable'], true)) === [];
            $ready += $shotReady ? 1 : 0;
            $out[(string) $scene['scene_id']] = ['ready' => $shotReady, 'needs' => $needs];
            $previous = $code;
        }

        return ['shots' => $out, 'ready' => $ready, 'planned' => count($out)];
    }

    /** @param  array<string, mixed>  $need */
    private function referenceNeedHash(array $need): string
    {
        return $this->digest(array_intersect_key($need, array_flip(ScenePlanAuthor::REFERENCE_FIELDS)));
    }

    /**
     * @return array{0: bool, 1: string}
     */
    public function confirmShotReference(string $projectId, ?string $actorId, string $sceneId, int $index, string $artifactId): array
    {
        $actor = $actorId === null ? null : Admin::find($actorId);
        $project = $actor === null ? null : $this->videoProjectRepository->getById($projectId);

        if ($project === null || Gate::forUser($actor)->denies('update', $project)) {
            return [false, 'project_not_found'];
        }

        $row = VideoRenderScene::query()->where('project_id', $projectId)->whereKey($sceneId)->first();
        $revision = (int) VideoRenderScene::query()->where('project_id', $projectId)->max('revision');

        if ($row === null) {
            return [false, 'scene_not_found'];
        }

        if ((int) $row->revision !== $revision) {
            return [false, 'storyboard_not_latest'];
        }

        $readiness = $this->referenceReadiness($projectId, $revision, $this->latestScenePlan($projectId)['scenes'], $this->sceneSourceCells($projectId, $revision));
        $need = $readiness['shots'][$sceneId]['needs'][$index] ?? null;

        if ($need === null) {
            return [false, 'reference_need_not_found'];
        }

        if ($need['unchecked'] === []) {
            return [false, 'reference_need_without_confirmation'];
        }

        if (! in_array($need['status'], ['needs_confirmation', 'available'], true) || ! in_array($artifactId, $need['fitting_artifact_ids'], true)) {
            return [false, 'reference_confirmation_not_matching'];
        }

        $sha = (string) VideoArtifact::query()->whereKey($artifactId)->value('sha256');

        return DB::transaction(function () use ($sceneId, $index, $artifactId, $sha, $need, $actorId): array {
            $locked = VideoRenderScene::query()->whereKey($sceneId)->lockForUpdate()->firstOrFail();
            $state = is_array($locked->state_json) ? $locked->state_json : [];
            $confirmations = is_array($state[self::REFERENCE_CONFIRMATIONS_KEY] ?? null) ? $state[self::REFERENCE_CONFIRMATIONS_KEY] : [];
            $confirmations[$index] = [
                'need_sha256' => $this->referenceNeedHash($need),
                'artifact_id' => $artifactId,
                'sha256' => $sha,
                'by' => $actorId,
                'at' => now()->toIso8601String(),
            ];
            $state[self::REFERENCE_CONFIRMATIONS_KEY] = $confirmations;
            $locked->forceFill(['state_json' => $state])->save();

            return [true, 'ok'];
        });
    }

    /**
     * @param  list<string>  $artifactIds
     * @return array<string, array{view: ?string, subject_key: ?string, shot: ?string}> what each approved image shows, by artifact id
     */
    private function referenceImageFacts(string $projectId, int $revision, array $artifactIds): array
    {
        if ($artifactIds === []) {
            return [];
        }

        $images = VideoDesignImage::query()->where('project_id', $projectId)->whereIn('selected_artifact_id', $artifactIds)->get();
        $anchorOf = static function (VideoDesignImage $image): ?string {
            $spec = is_array($image->prompt_spec_json) ? $image->prompt_spec_json : [];

            return is_string($spec['identity_anchor_artifact_id'] ?? null) ? $spec['identity_anchor_artifact_id']
                : (is_string($spec['source_artifact_id'] ?? null) ? $spec['source_artifact_id'] : null);
        };
        $anchorKeys = VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::ANCHOR_TYPE)
            ->whereIn('selected_artifact_id', array_values(array_filter($images->map($anchorOf)->all())))
            ->get()
            ->mapWithKeys(static fn (VideoDesignImage $anchor): array => [(string) $anchor->selected_artifact_id => $anchor->prompt_spec_json['subject_key'] ?? null])
            ->all();
        $shots = VideoRenderScene::query()
            ->where('project_id', $projectId)
            ->where('revision', $revision)
            ->whereIn('id', array_values(array_filter($images->pluck('render_scene_id')->all())))
            ->pluck('scene_code', 'id')
            ->all();
        $facts = [];

        foreach ($images as $image) {
            $spec = is_array($image->prompt_spec_json) ? $image->prompt_spec_json : [];
            $facts[(string) $image->selected_artifact_id] = match ((string) $image->image_type) {
                DesignImageStore::ANCHOR_TYPE => ['view' => ReferenceView::DESIGN_ANCHOR_VIEW, 'subject_key' => $spec['subject_key'] ?? null, 'shot' => null],
                DesignImageStore::REFERENCE_TYPE => ['view' => $spec['view_key'] ?? null, 'subject_key' => $anchorKeys[(string) $anchorOf($image)] ?? null, 'shot' => null],
                DesignImageStore::SCENE_KEYFRAME_TYPE => ['view' => null, 'subject_key' => null, 'shot' => $shots[(string) $image->render_scene_id] ?? null],
                default => ['view' => null, 'subject_key' => null, 'shot' => null],
            };
        }

        return $facts;
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
        $room = $this->filmRoomPlate($projectId, $scene);

        if ($room['room']) {
            $use = $this->filmRoomKeyframe($scene);

            return [
                'slots' => [],
                'prompt' => null,
                'blocked_reason' => $room['plate'] === null ? $room['why'] : null,
                'references' => null,
                'reference_reset' => null,
                'room' => [
                    'label' => $room['label'],
                    'artifact_id' => $room['plate'] === null ? null : (string) $room['plate']['artifact_id'],
                    'url' => $room['plate'] === null ? null : route('video-artifacts.show', (string) $room['plate']['artifact_id']),
                    'environment_url' => route('video-projects.environment', $projectId).'#approved_'.$room['key'],
                    'segment' => (int) ($scene->state_json['film']['segment'] ?? 0),
                    'keyframe_chosen' => $use['chosen'],
                    'keyframe_stale' => $use['stale'],
                    'use_url' => route('video-projects.scene-keyframe-use', [$projectId, (string) $scene->id]),
                ],
            ];
        }

        [$slots, $why] = $this->sceneManifestSlots($projectId, $scene, $stage, $views);

        if ($slots === null) {
            return [
                'slots' => [],
                'prompt' => null,
                'blocked_reason' => $why,
                'room' => null,
                'references' => null,
                'reference_reset' => str_starts_with($why, 'reference') ? [
                    'version' => $this->referenceChoice($scene)['version'],
                    'url' => route('video-projects.scene-references', [$projectId, (string) $scene->id]),
                ] : null,
            ];
        }

        [$preservation] = $this->preservationForRevision($stage);

        return [
            'references' => $this->referencePanel($scene, $stage) + [
                'room' => max(0, self::SCENE_MAX_SOURCE_IMAGES - count($slots)),
                'extras' => array_values(array_map(
                    static fn (array $slot): array => ['artifact_id' => (string) $slot['artifact_id'], 'role' => (string) $slot['role']],
                    array_filter($slots, static fn (array $slot): bool => ($slot['group'] ?? null) === 'extra'),
                )),
            ],
            'slots' => array_map(fn (array $slot): array => [
                'position' => $slot['position'],
                'role' => $slot['role'],
                'title' => $slot['title'],
                'primary' => $slot['position'] === 0,
                'group' => $slot['group'] ?? 'extra',
                'artifact_id' => (string) $slot['artifact_id'],
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
                )."\n\n".$this->keyframeDelta($scene),
            'blocked_reason' => null,
            'room' => null,
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
        $basis = $this->manifestBasis($projectId, $scene, $stage, $views);

        if ($basis['primary'] === null) {
            return [null, $basis['why']];
        }

        ['primary' => $primary, 'continues' => $continues, 'place' => $place, 'views' => $views] = $basis;

        $used = [$primary['artifact_id'] => true];
        $slots = [array_replace($primary, [
            'title' => $continues ? 'Từ '.$scene->source_scene_code : ($place ? $primary['title'] : 'Ảnh neo'),
            'group' => 'primary',
        ])];
        $required = [];

        if ($basis['room']) {
            return [[array_replace($slots[0], ['position' => 0])], 'ok'];
        }

        if (! $continues && ! is_array($scene->state_json[self::AFTER_JUMP_KEY] ?? null)) {
            [$previous, $previousWhy] = $this->previousShotKeyframe($scene);

            if ($previousWhy !== null) {
                return [null, $previousWhy];
            }

            if ($previous !== null && $previous['location_id'] === (string) ($scene->state_json['location_id'] ?? '')) {
                $required[] = array_replace($previous, ['role' => 'continuity', 'title' => 'Liền mạch: '.$previous['title']]);
            }
        }

        [$plate, $plateWhy] = $this->approvedPlate($projectId, $scene);

        if ($plate === null && $plateWhy !== self::NO_PLATE) {
            return [null, $plateWhy];
        }

        if ($plate !== null) {
            $required[] = array_replace($plate, ['role' => 'environment']);
        }

        foreach ($required as $entry) {
            if (array_key_exists($entry['artifact_id'], $used)) {
                continue;
            }

            $used[$entry['artifact_id']] = true;
            $slots[] = array_replace($entry, ['group' => 'required']);
        }

        if (count($slots) > self::SCENE_MAX_SOURCE_IMAGES) {
            return [null, 'references_over_limit'];
        }

        [$extras, $extrasWhy] = $this->referenceExtras(
            $projectId, $scene, $place, $continues, $basis['identity'], $views, $slots,
        );

        if ($extras === null) {
            return [null, $extrasWhy];
        }

        foreach ($extras as $entry) {
            $slots[] = array_replace($entry, ['group' => 'extra']);
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
     * @param  list<array<string, mixed>>  $views
     * @return array{primary: ?array<string, mixed>, why: string, continues: bool, room: bool, place: bool, anchor: ?array<string, mixed>,
     *               identity: ?array<string, mixed>, views: list<array<string, mixed>>}
     */
    private function manifestBasis(string $projectId, VideoRenderScene $scene, ?VideoPlanningStage $stage, array $views): array
    {
        [$primary, $why] = $this->resolveSource($scene, $stage, null, false);
        $continues = ($primary['role'] ?? null) === 'source_keyframe';
        $room = $this->filmRoomPlate($projectId, $scene)['room'];
        $place = $room || $this->isPlaceShot($scene);
        [$anchor] = $primary !== null && $continues && ! $place ? $this->anchorForDisplay($projectId, $scene, $stage) : [null];
        $identity = $place ? null : ($continues ? $anchor : $primary);

        return [
            'primary' => $primary,
            'why' => $why,
            'continues' => $continues,
            'room' => $room,
            'place' => $place,
            'anchor' => $anchor,
            'identity' => $identity,
            'views' => array_values(array_filter($views, static fn (array $view): bool => $identity !== null
                && ($view['anchor_artifact_id'] ?? null) === (string) $identity['artifact_id']
                && ($view['anchor_sha256'] ?? null) === (string) $identity['sha256'])),
        ];
    }

    /** @return array{version: int, customized: bool, options: list<array<string, mixed>>} */
    private function referencePanel(VideoRenderScene $scene, ?VideoPlanningStage $stage): array
    {
        $projectId = (string) $scene->project_id;
        $basis = $this->manifestBasis($projectId, $scene, $stage, $this->approvedReferenceViews($projectId));
        $choice = $this->referenceChoice($scene);

        return [
            'version' => $choice['version'],
            'customized' => $choice['items'] !== null,
            'options' => $basis['primary'] === null || $basis['room'] ? [] : array_map(static fn (array $option): array => [
                'artifact_id' => $option['artifact_id'],
                'title' => $option['title'],
                'kind' => $option['kind'],
                'roles' => $option['roles'],
                'url' => route('video-artifacts.show', $option['artifact_id']),
            ], $this->referenceOptions($projectId, $scene, $basis['place'], $basis['identity'], $basis['views'])),
        ];
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: ?string} [$entry, $blockedReason]; [null, null] for the first shot
     */
    private function previousShotKeyframe(VideoRenderScene $scene): array
    {
        if (($scene->state_json['film_cut_shape'] ?? null) === FilmCutAuthor::SHAPE) {
            return [null, null];
        }

        $previous = VideoRenderScene::query()
            ->where('project_id', $scene->project_id)
            ->where('revision', $scene->revision)
            ->where('scene_index', (int) $scene->scene_index - 1)
            ->first(['id', 'title', 'state_json']);

        if ($previous === null) {
            return [null, null];
        }

        $keyframe = VideoDesignImage::query()
            ->where('project_id', $scene->project_id)
            ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
            ->where('render_scene_id', $previous->id)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->whereNotNull('selected_artifact_id')
            ->orderByDesc('approved_at')
            ->first();

        if ($keyframe === null) {
            return [null, 'previous_keyframe_missing|'.$previous->title];
        }

        if ($this->keyframeNeedsReview($keyframe) !== null) {
            return [null, 'previous_keyframe_needs_review|'.$previous->title];
        }

        [$entry, $why] = $this->freshApprovedSource(
            (string) $scene->project_id, (string) $keyframe->selected_artifact_id, [DesignImageStore::SCENE_KEYFRAME_TYPE], 'continuity',
        );

        return $entry === null
            ? [null, $why]
            : [$entry + ['title' => (string) $previous->title, 'location_id' => (string) ($previous->state_json['location_id'] ?? '')], null];
    }

    /** @return array{version: int, items: ?list<array{artifact_id: string, role: string}>} */
    private function referenceChoice(VideoRenderScene $scene): array
    {
        $choice = is_array($scene->state_json) ? ($scene->state_json[self::REFERENCE_CHOICE_KEY] ?? null) : null;

        return [
            'version' => is_array($choice) ? (int) ($choice['version'] ?? 0) : 0,
            'items' => is_array($choice) && is_array($choice['items'] ?? null) ? array_values($choice['items']) : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $views
     * @return list<array{artifact_id: string, candidate_id: string, sha256: string, title: string, kind: string, roles: list<string>}>
     */
    private function referenceOptions(string $projectId, VideoRenderScene $scene, bool $place, ?array $identity, array $views): array
    {
        if ($place) {
            $options = array_map(
                static fn (array $option): array => $option + ['roles' => ['design_reference']],
                $this->designReferenceOptions($projectId),
            );
        } else {
            $options = $identity === null ? [] : [[
                'artifact_id' => (string) $identity['artifact_id'],
                'candidate_id' => (string) ($identity['candidate_id'] ?? ''),
                'sha256' => (string) $identity['sha256'],
                'title' => 'Ảnh neo',
                'kind' => 'anchor',
                'roles' => ['identity'],
            ]];

            foreach ($views as $view) {
                $options[] = [
                    'artifact_id' => (string) $view['artifact_id'],
                    'candidate_id' => (string) $view['candidate_id'],
                    'sha256' => (string) $view['sha256'],
                    'title' => (string) $view['title'],
                    'kind' => 'reference',
                    'roles' => ['identity', 'geometry'],
                ];
            }
        }

        if ((string) $scene->transition_mode !== ScenePreservationPrompt::HARD_CUT) {
            return $options;
        }

        $earlier = VideoRenderScene::query()
            ->where('project_id', $scene->project_id)
            ->where('revision', $scene->revision)
            ->where('scene_index', '<', (int) $scene->scene_index)
            ->orderBy('scene_index')
            ->get(['id', 'title', 'state_json'])
            ->filter(static fn (VideoRenderScene $row): bool => ($row->state_json['location_id'] ?? null) === ($scene->state_json['location_id'] ?? null));
        $keyframes = VideoDesignImage::query()
            ->whereIn('render_scene_id', $earlier->pluck('id'))
            ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->whereNotNull('selected_artifact_id')
            ->with('artifact')
            ->get()
            ->keyBy('render_scene_id');
        $memo = [];

        foreach ($earlier as $row) {
            $keyframe = $keyframes->get((string) $row->id);

            if ($keyframe === null || $keyframe->artifact === null || $this->keyframeReviewReason($keyframe, $memo) !== null) {
                continue;
            }

            $options[] = [
                'artifact_id' => (string) $keyframe->selected_artifact_id,
                'candidate_id' => (string) $keyframe->id,
                'sha256' => (string) $keyframe->artifact->sha256,
                'title' => (string) $row->title,
                'kind' => 'keyframe',
                'roles' => ['continuity'],
            ];
        }

        return $options;
    }

    /**
     * @param  list<array<string, mixed>>  $views
     * @param  list<array<string, mixed>>  $slots
     * @return array{0: ?list<array<string, mixed>>, 1: string}
     */
    private function referenceExtras(
        string $projectId,
        VideoRenderScene $scene,
        bool $place,
        bool $continues,
        ?array $identity,
        array $views,
        array $slots,
    ): array {
        $room = self::SCENE_MAX_SOURCE_IMAGES - count($slots);
        $used = array_flip(array_column($slots, 'artifact_id'));
        $items = $this->referenceChoice($scene)['items'];

        if ($items === null) {
            $auto = [];
            $needs = $this->referenceNeedKinds($scene);
            $planned = array_flip($this->plannedReferenceArtifacts($scene));
            $plannedViews = array_values(array_filter($views, static fn (array $view): bool => isset($planned[$view['artifact_id']])));
            $roles = match (true) {
                $needs === null => $place ? [] : ['identity', 'geometry'],
                $place => in_array('design', $needs, true) ? ['design_reference'] : [],
                default => array_intersect($needs, ['identity', 'design']) === [] ? [] : ['identity', 'geometry'],
            };

            foreach ($roles as $role) {
                $entry = match (true) {
                    $role === 'design_reference' => collect($this->designReferenceOptions($projectId))
                        ->first(static fn (array $option): bool => ! array_key_exists($option['artifact_id'], $used)),
                    $role === 'identity' && $continues => $identity,
                    default => $this->pickView($planned === [] ? $views : $plannedViews, $used),
                };

                if ($entry === null || array_key_exists($entry['artifact_id'], $used) || count($auto) >= $room) {
                    continue;
                }

                $used[$entry['artifact_id']] = true;
                $auto[] = array_replace($entry, ['role' => $role, 'title' => $entry['title'] ?? 'Ảnh neo']);
            }

            if ($roles !== []) {
                $linked = $place
                    ? array_values(array_filter($this->designReferenceOptions($projectId), static fn (array $option): bool => isset($planned[$option['artifact_id']])))
                    : $plannedViews;

                foreach ($linked as $entry) {
                    if (array_key_exists($entry['artifact_id'], $used) || count($auto) >= $room) {
                        continue;
                    }

                    $used[$entry['artifact_id']] = true;
                    $auto[] = ['artifact_id' => $entry['artifact_id'], 'candidate_id' => $entry['candidate_id'], 'sha256' => $entry['sha256'],
                        'title' => $entry['title'], 'role' => $place ? 'design_reference' : 'geometry'];
                }
            }

            $designRequired = $needs !== null
                && ($place ? in_array('design', $needs, true) : array_intersect($needs, ['identity', 'design']) !== []);

            if ($designRequired) {
                $design = array_column($this->designReferenceOptions($projectId), 'artifact_id');

                if ($design === []) {
                    return [null, 'design_reference_missing'];
                }

                if (array_intersect($design, array_map('strval', array_keys($used))) === []) {
                    return [null, 'design_reference_no_room'];
                }
            }

            return [$auto, 'ok'];
        }

        if (count($items) > $room) {
            return [null, 'references_over_limit'];
        }

        $options = [];

        foreach ($this->referenceOptions($projectId, $scene, $place, $identity, $views) as $option) {
            $options[$option['artifact_id']] = $option;
        }

        $singles = array_flip(array_intersect(array_column($slots, 'role'), self::SINGLE_ROLES));
        $extras = [];

        foreach ($items as $item) {
            $option = is_array($item) ? ($options[(string) ($item['artifact_id'] ?? '')] ?? null) : null;
            $role = is_array($item) ? (string) ($item['role'] ?? '') : '';

            if ($option === null || ! in_array($role, $option['roles'], true)) {
                return [null, 'reference_choice_invalid'];
            }

            if (array_key_exists($option['artifact_id'], $used)
                || (in_array($role, self::SINGLE_ROLES, true) && array_key_exists($role, $singles))) {
                return [null, 'reference_choice_duplicated|'.$option['title']];
            }

            $used[$option['artifact_id']] = true;
            $singles[$role] = true;
            $extras[] = [
                'artifact_id' => $option['artifact_id'],
                'candidate_id' => $option['candidate_id'],
                'sha256' => $option['sha256'],
                'role' => $role,
                'title' => $option['title'],
            ];
        }

        return [$extras, 'ok'];
    }

    /** @return list<string>|null */
    /**
     * @return list<string> the approved images an approved reference plan item gives this shot's unchanged needs
     */
    private function plannedReferenceArtifacts(VideoRenderScene $scene): array
    {
        $state = is_array($scene->state_json) ? $scene->state_json : [];

        if (($state['storyboard_shape'] ?? null) !== ScenePlanAuthor::SHOT_SHAPE) {
            return [];
        }

        $links = $this->referencePlanLinks((string) $scene->project_id, $this->stageStore->stageForProjectRevision(
            (string) $scene->project_id, PlanningStageName::SCENE_PLAN, (int) $scene->revision,
        ));
        $artifacts = [];

        foreach (array_values((array) ($state['reference_requirements'] ?? [])) as $index => $need) {
            $link = $links[$scene->scene_code.'#'.$index] ?? null;

            if (is_array($need) && $link !== null && hash_equals($link['need_sha256'], $this->referenceNeedHash($need))) {
                array_push($artifacts, ...$link['artifact_ids']);
            }
        }

        return array_values(array_unique($artifacts));
    }

    private function referenceNeedKinds(VideoRenderScene $scene): ?array
    {
        $state = is_array($scene->state_json) ? $scene->state_json : [];

        if (($state['storyboard_shape'] ?? null) !== ScenePlanAuthor::SHOT_SHAPE) {
            return null;
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $need): ?string => is_array($need) && is_string($need['kind'] ?? null) ? $need['kind'] : null,
            (array) ($state['reference_requirements'] ?? []),
        ))));
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return list<string>
     */
    private function sceneIdentityKeys(array $spec): array
    {
        return array_key_exists('space_source', $spec)
            ? [...self::SCENE_IDENTITY_KEYS, 'space_source']
            : self::SCENE_IDENTITY_KEYS;
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
        $requirement = $this->screenplayEnvironmentRequirement($scene);

        if ($requirement !== null && ($requirement['plate'] ?? true) === false) {
            return [null, self::NO_PLATE];
        }

        if ($requirement !== null) {
            $plate = $this->approvedPlates($projectId)[$requirement['key']] ?? null;

            return $plate === null
                ? [null, 'environment_plate_missing|'.$requirement['label']]
                : [array_replace($plate, ['title' => $requirement['label']]), 'ok'];
        }

        if ($scene->screenplay_stage_id !== null) {
            return [null, 'environment_requirement_unreadable'];
        }

        // Legacy plans predate screenplay linkage. Keep their milestone bridge
        // readable, but never use it as a silent fallback for a linked plan.
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

    /**
     * @return array{room: bool, plate: ?array<string, mixed>, why: string, label: string, key: ?string}
     */
    private function filmRoomPlate(string $projectId, VideoRenderScene $scene): array
    {
        $state = is_array($scene->state_json) ? $scene->state_json : [];
        $none = ['room' => false, 'plate' => null, 'why' => 'ok', 'label' => '', 'key' => null];

        if (($state['film_cut_shape'] ?? null) !== FilmCutAuthor::SHAPE) {
            return $none;
        }

        $requirement = $this->screenplayEnvironmentRequirement($scene);

        if ($requirement === null || ($requirement['kind'] ?? null) !== self::ENVIRONMENT_ROOM) {
            return $none;
        }

        $label = (string) $requirement['label'];
        $roomState = (array) ($state[\App\Video\Screenplay\SceneBeats::ROOM_STATE_KEY] ?? []);
        $found = ['room' => true, 'plate' => null, 'label' => $label, 'key' => (string) $requirement['key']];

        if (($roomState['start'] ?? null) !== \App\Video\Screenplay\SceneBeats::FITTED
            || ($roomState['end'] ?? null) !== \App\Video\Screenplay\SceneBeats::FITTED
            || ($requirement['phase'] ?? null) !== EnvironmentPlatePrompt::ROOM_FITTED) {
            return $found + ['why' => 'room_state_not_fitted|'.$label];
        }

        [$plate] = $this->approvedPlate($projectId, $scene);

        if ($plate === null) {
            return $found + ['why' => $this->missingRoomPlateReason($projectId, $requirement).'|'.$label];
        }

        if (! $this->artifactFileExists((string) $plate['artifact_id'])) {
            return $found + ['why' => 'room_plate_file_missing|'.$label];
        }

        return array_replace($found, ['plate' => $plate, 'why' => 'ok']);
    }

    /** @param array<string, mixed> $requirement */
    private function missingRoomPlateReason(string $projectId, array $requirement): string
    {
        $approved = VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::ENVIRONMENT_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->whereNotNull('selected_artifact_id')
            ->whereNotNull('environment_key')
            ->get(['environment_key', 'prompt_spec_json'])
            ->filter(static fn (VideoDesignImage $row): bool => ($row->prompt_spec_json['location_id'] ?? null) === $requirement['location_id'])
            ->pluck('environment_key')
            ->map(static fn (mixed $key): string => (string) $key)
            ->all();

        if (in_array((string) $requirement['key'], $approved, true)) {
            return 'room_plate_file_missing';
        }

        if ($approved === []) {
            return 'room_plate_unapproved';
        }

        $project = VideoProject::query()->find($projectId);
        $current = $project === null ? [] : collect($this->selectedEnvironmentRequirements($project)[1])
            ->where('location_id', $requirement['location_id'])
            ->pluck('key')
            ->all();

        return array_intersect($approved, $current) === [] ? 'room_plate_source_changed' : 'room_plate_wrong_state';
    }

    /**
     * @return array{keyframe: ?VideoDesignImage, chosen: bool, stale: bool}
     */
    private function filmRoomKeyframe(VideoRenderScene $scene): array
    {
        $keyframe = VideoDesignImage::query()
            ->where('render_scene_id', $scene->id)
            ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->whereNotNull('selected_artifact_id')
            ->with('artifact')
            ->first();
        $choice = is_array($scene->state_json) ? ($scene->state_json[self::FILM_KEYFRAME_USE_KEY] ?? null) : null;
        $chosen = is_array($choice)
            && $keyframe?->artifact !== null
            && ($choice['candidate_id'] ?? null) === (string) $keyframe->id
            && ($choice['artifact_id'] ?? null) === (string) $keyframe->selected_artifact_id;

        return ['keyframe' => $keyframe, 'chosen' => $chosen, 'stale' => is_array($choice) && ! $chosen];
    }

    /**
     * @return array{0: bool, 1: string} [$done, $reason]
     */
    public function chooseFilmKeyframe(string $projectId, ?string $actorId, string $sceneId, bool $use): array
    {
        $scene = $this->ownedScene($projectId, $actorId, $sceneId);

        if ($scene === null) {
            return [false, 'scene_not_found'];
        }

        if (! $this->filmRoomPlate($projectId, $scene)['room']) {
            return [false, 'film_keyframe_not_room'];
        }

        $value = null;

        if ($use) {
            $keyframe = $this->filmRoomKeyframe($scene)['keyframe'];

            if ($keyframe?->artifact === null) {
                return [false, 'film_keyframe_unapproved'];
            }

            if ($this->keyframeNeedsReview($keyframe) !== null) {
                return [false, 'film_keyframe_needs_review'];
            }

            $value = ['candidate_id' => (string) $keyframe->id, 'artifact_id' => (string) $keyframe->selected_artifact_id];
        }

        DB::update(
            $value === null
                ? "UPDATE video_render_scenes SET state_json = JSON_REMOVE(state_json, '$.".self::FILM_KEYFRAME_USE_KEY."'), updated_at = ? WHERE id = ?"
                : "UPDATE video_render_scenes SET state_json = JSON_SET(state_json, '$.".self::FILM_KEYFRAME_USE_KEY."', JSON_EXTRACT(?, '$')), updated_at = ? WHERE id = ?",
            $value === null
                ? [now(), $scene->id]
                : [json_encode($value, JSON_THROW_ON_ERROR), now(), $scene->id],
        );

        return [true, $use ? 'film_keyframe_used' : 'film_keyframe_unused'];
    }

    /**
     * Resolve the clean environment plate required by one screenplay-backed
     * planned shot. Build state participates in the identity of the plate, but
     * the prompt still forbids rendering the main subject into that plate.
     *
     * @return array{key:string,label:string,place:string,version:string,sha256:string}|null
     */
    private function screenplayEnvironmentRequirement(VideoRenderScene $scene): ?array
    {
        if ($scene->screenplay_stage_id === null || $scene->screenplay_scene_code === null) {
            return null;
        }

        $shot = is_array($scene->state_json) ? $scene->state_json : [];
        $memoKey = 'screenplay_environment:'.$scene->screenplay_stage_id.':'.$scene->screenplay_scene_code
            .(is_array($shot[\App\Video\Screenplay\SceneBeats::ROOM_STATE_KEY] ?? null)
                ? ':'.hash('sha256', (string) json_encode([$shot[\App\Video\Screenplay\SceneBeats::ROOM_STATE_KEY], $shot['keyframe_state'] ?? null]))
                : '');

        if ($this->sourceMemo !== null && array_key_exists($memoKey, $this->sourceMemo)) {
            return $this->sourceMemo[$memoKey];
        }

        $stage = VideoPlanningStage::query()
            ->whereKey($scene->screenplay_stage_id)
            ->where('project_id', $scene->project_id)
            ->where('stage', PlanningStageName::SCREENPLAY->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->first();
        $screenplay = is_array($stage?->output_json)
            ? $this->productionScreenplayContent($stage->output_json)
            : [];

        if ($screenplay === []
            || ! hash_equals(
                (string) $scene->screenplay_hash,
                \App\Video\Screenplay\ScreenplayContentHash::of($screenplay),
            )) {
            return $this->rememberSource($memoKey, null);
        }

        $screenplayScene = collect((array) ($screenplay['scenes'] ?? []))
            ->firstWhere('id', $scene->screenplay_scene_code);

        if (! is_array($screenplayScene)) {
            return $this->rememberSource($memoKey, null);
        }

        $context = $this->environmentContext((string) $scene->project_id, (array) $stage->output_json);

        return $this->rememberSource($memoKey, $context === null ? null : $this->sceneEnvironmentRequirement($screenplay, $screenplayScene, $context, $shot));
    }

    /**
     * @param  array<string, mixed>  $screenplayOutput  the stored screenplay output, source blocks included
     * @return array{stages: list<string>, fitted_from: ?string, spaces: array<string, array<string, mixed>>, furnished: bool, design_hash: ?string}|null
     */
    private function environmentContext(string $projectId, array $screenplayOutput): ?array
    {
        $source = $screenplayOutput[VesselDesign::SOURCE_DESIGN_KEY] ?? null;
        $memoKey = 'environment_context:'.$projectId.':'.(is_array($source) ? (string) ($source['stage_id'] ?? '').':'.(string) ($source['content_hash'] ?? '') : 'none');

        if ($this->sourceMemo !== null && array_key_exists($memoKey, $this->sourceMemo)) {
            return $this->sourceMemo[$memoKey];
        }

        $design = null;

        if ($source !== null) {
            $design = app(VesselDesignService::class)->verifiedSource($projectId, $source);

            if ($design === null) {
                Log::warning('environment: the design the screenplay was written from is missing or changed, no plate requirement built', [
                    'project_id' => $projectId,
                    'design_stage_id' => is_array($source) ? ($source['stage_id'] ?? null) : null,
                ]);

                return $this->rememberSource($memoKey, null);
            }
        }

        $project = VideoProject::query()->with('article.category')->find($projectId);
        $category = (string) ($project?->article?->category?->slug ?? '');
        $profile = $category === '' ? null : $this->screenplayExpansion->screenplayProfile($category);
        $stages = array_values(array_filter((array) ($profile['arc_stages'] ?? []), 'is_string'));
        $fittedFrom = config("video.environment.fitted_from_stage.{$category}");
        return $this->rememberSource($memoKey, [
            'stages' => $stages,
            'fitted_from' => is_string($fittedFrom) && in_array($fittedFrom, $stages, true) ? $fittedFrom : null,
            'spaces' => self::designSpaces(is_array($design?->output_json) ? $design->output_json : []),
            'furnished' => $design !== null
                && (PlanningStageStore::metadataOf($design->input_json)[VesselDesign::INTERIOR_POLICY_KEY] ?? null) === VesselDesign::FURNISHED_ROOMS_POLICY,
            'design_hash' => $design === null ? null : (string) ($source['content_hash'] ?? ''),
        ]);
    }

    /**
     * @param  array<string, mixed>  $design  the verified source design output
     * @return array<string, array<string, mixed>> film_brief space key => the fitted texts the design gives that room
     */
    public static function designSpaces(array $design): array
    {
        $spaces = [];

        foreach ((array) ($design[\App\Video\Screenplay\ProtagonistProfile::OUTPUT_KEY][\App\Video\Screenplay\ProtagonistProfile::INTERIOR_KEY] ?? []) as $space) {
            if (is_array($space) && is_string($space['space'] ?? null)) {
                $spaces[$space['space']] = $space;
            }
        }

        if (! VesselDesign::isV3($design)) {
            return $spaces;
        }

        foreach ((array) ($design[VesselDesign::CANONICAL_KEY]['spatial_topology'] ?? []) as $row) {
            if (is_array($row) && is_string($row['brief_space'] ?? null) && is_array($row['interior'] ?? null)) {
                $spaces[$row['brief_space']] = array_filter([
                    'materials_and_light' => trim(trim((string) ($row['interior']['materials'] ?? '')).' '.trim((string) ($row['light'] ?? ''))),
                    'fixed_furniture' => trim((string) ($row['interior']['fixed_furniture'] ?? '')),
                    'layout' => trim((string) ($row['interior']['layout'] ?? '')),
                ], static fn (string $text): bool => $text !== '');
            }
        }

        return $spaces;
    }

    /**
     * @param  array{stages: list<string>, fitted_from: ?string, spaces: array<string, array<string, mixed>>, furnished: bool, design_hash: ?string}  $context
     * @param  array<string, mixed>  $screenplayScene
     * @param  array<string, mixed>|null  $shot  the planned shot's state
     * @return array{0: ?string, 1: ?string}
     */
    private function roomPhase(array $context, array $screenplayScene, ?array $shot = null): array
    {
        $key = \App\Video\Screenplay\SceneBeats::ROOM_STATE_KEY;
        $fromShot = is_array($shot[$key] ?? null);
        $room = $fromShot ? $shot[$key] : ($screenplayScene[$key] ?? null);

        if (is_array($room)) {
            $progress = $fromShot
                ? ($shot['keyframe_state']['progress'] ?? null)
                : ($screenplayScene['subject_state']['start']['progress'] ?? null);
            $installed = is_string($progress) && trim($progress) !== '' ? trim($progress) : null;

            return match ($room['start'] ?? null) {
                \App\Video\Screenplay\SceneBeats::FITTED => [EnvironmentPlatePrompt::ROOM_FITTED, null],
                \App\Video\Screenplay\SceneBeats::IN_FIT_OUT => $installed === null
                    ? [EnvironmentPlatePrompt::ROOM_UNFITTED, null]
                    : [EnvironmentPlatePrompt::ROOM_IN_FIT_OUT, $installed],
                \App\Video\Screenplay\SceneBeats::SHELL_ONLY => [EnvironmentPlatePrompt::ROOM_UNFITTED, null],
                default => [null, null],
            };
        }

        $stage = array_search((string) ($screenplayScene['stage'] ?? ''), $context['stages'], true);
        $fittedFrom = $context['fitted_from'] === null ? false : array_search($context['fitted_from'], $context['stages'], true);

        if ($stage === false || $fittedFrom === false) {
            return [null, null];
        }

        return [$stage < $fittedFrom ? EnvironmentPlatePrompt::ROOM_UNFITTED : EnvironmentPlatePrompt::ROOM_FITTED, null];
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @param  array<string, mixed>  $screenplayScene
     * @param  array{stages: list<string>, fitted_from: ?string, spaces: array<string, array<string, mixed>>, furnished: bool}  $context
     * @param  array<string, mixed>|null  $shot  the planned shot's state
     * @return array<string, mixed>|null
     */
    private function sceneEnvironmentRequirement(array $screenplay, array $screenplayScene, array $context, ?array $shot = null): ?array
    {
        $locationId = (string) ($screenplayScene['location_id'] ?? '');
        $location = collect((array) ($screenplay['locations'] ?? []))->firstWhere('id', $locationId);

        if (! is_array($location)
            || trim((string) ($location['name'] ?? '')) === ''
            || trim((string) ($location['description'] ?? '')) === '') {
            return null;
        }

        if (\App\Video\Screenplay\LocationProfile::isProfiled($location)) {
            [$phase, $installed] = $this->roomPhase($context, $screenplayScene, $shot);
            $recorded = is_array($screenplayScene[\App\Video\Screenplay\SceneBeats::ROOM_STATE_KEY] ?? null)
                || is_array($shot[\App\Video\Screenplay\SceneBeats::ROOM_STATE_KEY] ?? null);

            return $this->profiledEnvironmentRequirement(
                $screenplay, $location, $phase, $context['spaces'], $context['furnished'],
                $recorded ? ($context['design_hash'] ?? null) : null,
                $installed,
            );
        }

        $identity = [
            'contract' => self::LEGACY_ENVIRONMENT_CONTRACT,
            'location_id' => $locationId,
            'location_description' => trim((string) $location['description']),
        ];
        $sha = $this->digest($identity);

        return [
            'kind' => self::ENVIRONMENT_EXTERNAL,
            'location_id' => $locationId,
            'key' => 'story_'.substr($sha, 0, 24),
            'label' => trim((string) $location['name']),
            'place' => trim((string) $location['description']),
            'version' => self::LEGACY_ENVIRONMENT_CONTRACT,
            'sha256' => $sha,
        ];
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @param  array<string, mixed>  $location
     * @param  array<string, array<string, mixed>>  $spaces
     * @param  bool  $furnished  the design was written under the furnished-rooms interior policy
     * @param  string|null  $designHash  the source design hash, set only for scenes that record their room state
     * @param  string|null  $installed  the recorded progress, set only in the in-fit-out phase
     * @return array<string, mixed>
     */
    private function profiledEnvironmentRequirement(array $screenplay, array $location, ?string $phase, array $spaces, bool $furnished = false, ?string $designHash = null, ?string $installed = null): array
    {
        $place = \App\Video\Screenplay\LocationProfile::forPrompt($location, (array) ($screenplay['locations'] ?? []));
        $room = $place['spatial_relation'] === \App\Video\Screenplay\LocationProfile::SUBJECT_PART
            && ($location[\App\Video\Screenplay\LocationProfile::ENCLOSURE_KEY] ?? null) === \App\Video\Screenplay\LocationProfile::INTERIOR;

        if ($place['spatial_relation'] !== \App\Video\Screenplay\LocationProfile::EXTERNAL && ! $room) {
            return [
                'plate' => false,
                'location_id' => $place['id'],
                'label' => $place['name'],
                'spatial_relation' => $place['spatial_relation'],
            ];
        }

        $characters = collect((array) ($screenplay['characters'] ?? []));
        $subjectId = $place['subject_id'] ?? ($characters->firstWhere('role', 'protagonist')['id'] ?? null);
        $subject = $characters->firstWhere('id', $subjectId);
        $subjectName = is_array($subject) ? trim((string) ($subject['name'] ?? '')) : '';
        $contract = $room ? self::ROOM_ENVIRONMENT_CONTRACT : self::LOCATION_ENVIRONMENT_CONTRACT;
        $space = $spaces[(string) ($location['brief_space'] ?? '')] ?? null;
        $fitted = $room && $phase === EnvironmentPlatePrompt::ROOM_FITTED && is_array($space)
            ? array_intersect_key($space, array_flip($furnished
                ? ['materials_and_light', 'fixed_furniture', 'layout']
                : ['materials_and_light', 'fixed_furniture']))
            : null;

        $installed = $room && $phase === EnvironmentPlatePrompt::ROOM_IN_FIT_OUT ? $installed : null;
        $identity = [
            'contract' => $contract,
            'place' => $place,
            'subject_name' => $subjectName,
        ] + ($room ? ['phase' => $phase, 'fitted' => $fitted] : [])
            + ($installed === null ? [] : ['installed' => $installed])
            + ($designHash === null ? [] : ['design_content_hash' => $designHash]);
        $sha = $this->digest($identity);

        return [
            'plate' => true,
            'kind' => $room ? self::ENVIRONMENT_ROOM : self::ENVIRONMENT_EXTERNAL,
            'location_id' => $place['id'],
            'phase' => $room ? $phase : null,
            'key' => 'story_'.substr($sha, 0, 24),
            'label' => $place['name'].match ($room ? $phase : null) {
                EnvironmentPlatePrompt::ROOM_UNFITTED => ' · trước hoàn thiện',
                EnvironmentPlatePrompt::ROOM_IN_FIT_OUT => ' · đang hoàn thiện',
                EnvironmentPlatePrompt::ROOM_FITTED => ' · đã hoàn thiện',
                default => '',
            },
            'place' => EnvironmentPlatePrompt::placeBlock($place, $room),
            'prompt' => $room
                ? EnvironmentPlatePrompt::forRoom($place, $subjectName, (string) $phase, $fitted, $installed)
                : EnvironmentPlatePrompt::forLocation(
                    $place,
                    $subjectName,
                    ($location[\App\Video\Screenplay\LocationProfile::ENCLOSURE_KEY] ?? null) === \App\Video\Screenplay\LocationProfile::INTERIOR,
                ),
            'plate_version' => $room ? EnvironmentPlatePrompt::ROOM_VERSION : EnvironmentPlatePrompt::LOCATION_VERSION,
            'spatial_relation' => $place['spatial_relation'],
            'version' => $contract,
            'sha256' => $sha,
        ] + ($installed === null ? [] : ['installed' => $installed]);
    }

    private function rememberSource(string $key, mixed $value): mixed
    {
        if ($this->sourceMemo !== null) {
            $this->sourceMemo[$key] = $value;
        }

        return $value;
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

            if ($row->artifact === null || ($spec['reference_mode'] ?? null) === \App\Video\Reference\ReferenceSheet::MODE) {
                continue;
            }

            $anchorKeys = match (true) {
                in_array($spec['derivation_version'] ?? null, [ReferencePromptWriter::DERIVATION_VERSION, ReferencePromptWriter::PLAN_DERIVATION_VERSION], true) => ['identity_anchor_artifact_id', 'identity_anchor_sha256'],
                ($spec['derivation'] ?? null) === 'gpt_edit' => ['source_artifact_id', 'source_artifact_sha256'],
                default => null,
            };

            $views[] = [
                'artifact_id' => (string) $row->selected_artifact_id,
                'candidate_id' => (string) $row->id,
                'sha256' => (string) $row->artifact->sha256,
                'environment' => (string) ($spec['environment'] ?? ''),
                'anchor_artifact_id' => $anchorKeys === null ? null : (is_string($spec[$anchorKeys[0]] ?? null) ? $spec[$anchorKeys[0]] : null),
                'anchor_sha256' => $anchorKeys === null ? null : (is_string($spec[$anchorKeys[1]] ?? null) ? $spec[$anchorKeys[1]] : null),
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

    /** @return list<array{artifact_id: string, candidate_id: string, sha256: string, title: string, kind: string}> */
    private function designReferenceOptions(string $projectId): array
    {
        $anchor = $this->productionAnchor($projectId);
        $artifact = $anchor?->selected_artifact_id === null ? null : VideoArtifact::query()->whereKey($anchor->selected_artifact_id)->first();

        if ($artifact === null) {
            return [];
        }

        $options = [[
            'artifact_id' => (string) $artifact->id,
            'candidate_id' => (string) $anchor->id,
            'sha256' => (string) $artifact->sha256,
            'title' => 'Ảnh neo',
            'kind' => 'anchor',
        ]];

        foreach ($this->approvedReferenceViews($projectId) as $view) {
            if (($view['anchor_artifact_id'] ?? null) === (string) $artifact->id
                && ($view['anchor_sha256'] ?? null) === (string) $artifact->sha256) {
                $options[] = [
                    'artifact_id' => (string) $view['artifact_id'],
                    'candidate_id' => (string) $view['candidate_id'],
                    'sha256' => (string) $view['sha256'],
                    'title' => (string) $view['title'],
                    'kind' => 'reference',
                ];
            }
        }

        return $options;
    }

    /** @return array<string, mixed>|null */
    public function referencePageData(string $projectId): ?array
    {
        $project = $this->videoProjectRepository->getById($projectId);

        if ($project === null) {
            return null;
        }

        $anchor = $this->referenceAnchor($projectId);
        $designs = app(\App\Services\Video\VesselDesignService::class);
        $designFirst = VesselDesign::isDesignFirst($project);
        $anchorLock = $designFirst ? $designs->anchorSelection($project) : null;

        return [
            'id' => $projectId,
            'project' => $project,
            'designFirst' => $designFirst,
            'referenceLock' => $designFirst ? $designs->referenceSelection($project) : null,
            'lockableReferences' => $anchorLock === null ? [] : $designs->approvedReferences($projectId, $anchorLock),
            'approvedAnchor' => $anchor,
            'referenceViews' => $this->designImageStore->referenceCellsFor($projectId),
            'referenceViewCases' => ReferenceView::menu(),
            'referenceEnvironmentCases' => ReferenceEnvironment::cases(),
            'referencePrompts' => app(ReferencePromptWriter::class)->previews($projectId, $anchor),
            'referencePlan' => $designFirst ? $this->referencePlanPanel($projectId) : null,
            'referenceSource' => app(ReferencePromptWriter::class)->source($projectId, $anchor),
            'referenceSheet' => [
                'option' => \App\Video\Reference\ReferenceSheet::VIEW_OPTION,
                'legend' => \App\Video\Reference\ReferenceSheet::legend(),
                'finish_ready' => app(ReferencePromptWriter::class)->sheetFinishReady($projectId, $anchor),
                'default_size' => \App\Enums\ImageSize::LANDSCAPE_4K->value,
                'default_environment' => ReferenceEnvironment::NEUTRAL_STUDIO->value,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?VideoPlanningStage, 1: string, 2: list<string>}
     */
    public function writeReferencePrompt(string $projectId, array $data): array
    {
        $result = $this->referenceAnchor($projectId);
        return app(ReferencePromptWriter::class)->write(
            $projectId,
            $result,
            ReferenceView::from((string) $data['view']),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?VideoDesignImage, 1: string} [$image, $reason]
     */
    public function renderReferenceDirect(string $projectId, string $creator, array $data): array
    {
        if (($data['view'] ?? null) === \App\Video\Reference\ReferenceSheet::VIEW_OPTION) {
            return $this->renderReferenceSheet($projectId, $creator, $data);
        }

        $view = ReferenceView::from((string) $data['view']);
        $environment = ReferenceEnvironment::from((string) $data['environment']);

        [$ready, $why] = app(ReferencePromptWriter::class)->renderable(
            $projectId,
            $this->referenceAnchor($projectId),
            (string) $data['reference_prompt_stage_id'],
            $view,
            $environment,
            (string) $data['prompt_sha256'],
        );

        if ($ready === null) {
            return [null, $why];
        }

        [$image, $reason] = $this->designImageStore->createReference($projectId, $creator, [
            'operation' => 'edit',
            'derivation' => 'gpt_edit',
            'source_image_id' => $ready['anchor_image_id'],
            'source_artifact_id' => $ready['anchor_artifact_id'],
            'source_artifact_sha256' => $ready['anchor_sha256'],
            'identity_anchor_artifact_id' => $ready['anchor_artifact_id'],
            'identity_anchor_sha256' => $ready['anchor_sha256'],
            'reference_prompt_stage_id' => $ready['stage_id'],
            'source_packet_hash' => $ready['packet_hash'],
            'prompt' => $ready['prompt'],
            'variations' => (int) $data['variations'],
            'project_id' => $projectId,
            'image_type' => 'reference_view',
            'view_key' => $view->value,
            'environment' => $environment->value,
            'slot_index' => $view->slot(),
            'identity_lock_id' => null,
            'identity_lock_hash' => null,
            'derivation_version' => ReferencePromptWriter::DERIVATION_VERSION,
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

    /**
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    public function referenceSheetPreview(string $projectId, string $state, string $environment): array
    {
        $place = ReferenceEnvironment::tryFrom($environment);

        if ($place === null) {
            return [null, 'reference_preview_stale'];
        }

        return app(ReferencePromptWriter::class)->sheet($projectId, $this->referenceAnchor($projectId), $state, $place);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?VideoDesignImage, 1: string}
     */
    private function renderReferenceSheet(string $projectId, string $creator, array $data): array
    {
        [$sheet, $why] = $this->referenceSheetPreview($projectId, (string) $data['sheet_state'], (string) $data['environment']);

        if ($sheet === null) {
            return [null, $why];
        }

        if (! hash_equals($sheet['prompt_sha256'], (string) $data['prompt_sha256'])) {
            return [null, 'reference_preview_stale'];
        }

        [$image, $reason] = $this->designImageStore->createReference($projectId, $creator, [
            'operation' => 'edit',
            'derivation' => 'gpt_edit',
            'reference_mode' => \App\Video\Reference\ReferenceSheet::MODE,
            'layout_version' => $sheet['layout_version'],
            'panels' => $sheet['panels'],
            'sheet_state' => $sheet['state'],
            'sheet_prompt_sha256' => $sheet['prompt_sha256'],
            'design_stage_id' => $sheet['design_stage_id'],
            'design_content_hash' => $sheet['design_content_hash'],
            'source_image_id' => $sheet['anchor_image_id'],
            'source_artifact_id' => $sheet['anchor_artifact_id'],
            'source_artifact_sha256' => $sheet['anchor_sha256'],
            'identity_anchor_artifact_id' => $sheet['anchor_artifact_id'],
            'identity_anchor_sha256' => $sheet['anchor_sha256'],
            'prompt' => $sheet['prompt'],
            'variations' => 1,
            'project_id' => $projectId,
            'image_type' => DesignImageStore::REFERENCE_TYPE,
            'view_key' => null,
            'environment' => $sheet['environment'],
            'slot_index' => \App\Video\Reference\ReferenceSheet::SLOTS[$sheet['state']],
            'identity_lock_id' => null,
            'identity_lock_hash' => null,
            'derivation_version' => \App\Video\Reference\ReferenceSheet::DERIVATION_VERSION,
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

    /**
     * @return array{0: ?array<string, mixed>, 1: string} the production chain the reference plan is written from:
     *         approved storyboard → its screenplay → the verified design → the approved anchor of that design
     */
    public function referencePlanSource(string $projectId, bool $readBytes = false): array
    {
        $project = VideoProject::query()->find($projectId);

        if ($project === null) {
            return [null, 'project_not_found'];
        }

        if (! VesselDesign::isDesignFirst($project)) {
            return [null, 'reference_plan_design_first_only'];
        }

        $storyboard = $project->selectedScenePlanStage()->first();
        $input = is_array($storyboard?->input_json) ? $storyboard->input_json : [];

        if ($storyboard === null || $storyboard->status !== VideoPlanningStageStatus::SUCCEEDED->value
            || ($input['source_mode'] ?? null) !== ScenePlanAuthor::DESIGN_FIRST_SOURCE
            || ! in_array($input['scene_contract_version'] ?? null, ScenePlanAuthor::SHOT_CONTRACT_VERSIONS, true)) {
            return [null, 'reference_plan_no_production_storyboard'];
        }

        $changed = $this->storyboardSourceChange($projectId, $input);

        if ($changed !== null) {
            return [null, 'reference_plan_source_changed|'.$changed];
        }

        $screenplayStage = VideoPlanningStage::query()->whereKey((string) $input['screenplay_stage_id'])->first();
        $output = is_array($screenplayStage?->output_json) ? $screenplayStage->output_json : [];
        $recorded = $output[VesselDesign::SOURCE_DESIGN_KEY] ?? null;
        $design = is_array($recorded) ? app(VesselDesignService::class)->verifiedSource($projectId, $recorded) : null;

        if ($design === null || (string) $design->id !== (string) ($input['design_stage_id'] ?? '')
            || (int) $design->planning_revision !== (int) ($recorded['revision'] ?? -1)
            || (int) $design->planning_revision !== (int) ($input['design_revision'] ?? -1)) {
            return [null, 'reference_plan_design_mismatch'];
        }

        $anchor = $this->productionAnchor($projectId);
        $anchorSpec = is_array($anchor?->prompt_spec_json) ? $anchor->prompt_spec_json : [];

        if ($anchor?->artifact === null || $anchor->status !== DesignImageStatus::APPROVED->value
            || ($anchorSpec['subject_key'] ?? null) !== VesselDesign::subjectKey((string) $design->id)
            || (is_string($anchorSpec['design_content_hash'] ?? null) && ! hash_equals($anchorSpec['design_content_hash'], (string) $input['design_content_hash']))) {
            return [null, 'reference_plan_anchor_mismatch'];
        }

        $revision = (int) ($storyboard->output_json['revision'] ?? 0);
        $rows = VideoRenderScene::query()
            ->where('project_id', $projectId)
            ->where('revision', $revision)
            ->where('screenplay_stage_id', $screenplayStage->id)
            ->orderBy('scene_index')
            ->get();

        if ($revision < 1 || $rows->isEmpty()) {
            return [null, 'reference_plan_no_production_storyboard'];
        }

        $meta = PlanningStageStore::metadataOf($screenplayStage->input_json);
        $foundation = VideoPlanningStage::query()
            ->whereKey((string) ($meta['foundation_stage_id'] ?? ''))
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::SCREENPLAY_FOUNDATION->value)
            ->first();

        if ($foundation === null || ! hash_equals((string) ($meta['foundation_output_hash'] ?? ''), (string) $foundation->output_hash)) {
            return [null, 'reference_plan_profile_unknown'];
        }

        [$profile] = ScreenplayExpansionService::profileForFoundation(
            $foundation, app(ScreenplayExpansionService::class)->screenplayProfile((string) ($project->article?->category?->slug ?? '')),
        );
        $unfinished = $profile['film_cut']['finished_before_launch_stages'] ?? null;

        if ($profile === null) {
            return [null, 'reference_plan_profile_unknown'];
        }

        if (! is_array($unfinished) || ! array_is_list($unfinished)) {
            return [null, 'reference_plan_profile_invalid'];
        }

        $bytes = '';

        if ($readBytes) {
            try {
                $bytes = (string) Storage::disk((string) $anchor->artifact->storage_disk)->get((string) $anchor->artifact->storage_path);
            } catch (\Throwable) {
                $bytes = '';
            }

            if ($bytes === '' || ! hash_equals((string) $anchor->artifact->sha256, hash('sha256', $bytes))) {
                return [null, 'artifact_checksum_mismatch'];
            }
        }

        [$needs, $interior] = $this->referencePlanNeeds($rows, $this->productionScreenplayContent($output), $unfinished);

        return [[
            'storyboard_stage_id' => (string) $storyboard->id,
            'storyboard_revision' => $revision,
            'screenplay_stage_id' => (string) $screenplayStage->id,
            'screenplay_hash' => (string) $input['screenplay_hash'],
            'design_stage_id' => (string) $design->id,
            'design_revision' => (int) $design->planning_revision,
            'design_content_hash' => (string) $input['design_content_hash'],
            'design' => (array) $design->output_json,
            'anchor_image_id' => (string) $anchor->id,
            'anchor_artifact_id' => (string) $anchor->artifact->id,
            'anchor_sha256' => (string) $anchor->artifact->sha256,
            'anchor_mime' => (string) ($anchor->artifact->mime_type ?: 'image/png'),
            'anchor_bytes' => $bytes,
            'needs' => $needs,
            'interior' => $interior,
            'catalog' => $this->referencePlanCatalog($projectId, (string) $anchor->artifact->id, (string) $anchor->artifact->sha256),
        ], 'ok'];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, VideoRenderScene>  $rows
     * @param  array<string, mixed>  $screenplay
     * @param  list<string>  $unfinished  the screenplay stages whose vessel is not finished yet
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>} [$needs, $interior] the image needs of the real vessel or its drawing, and those inside its rooms
     */
    private function referencePlanNeeds(\Illuminate\Support\Collection $rows, array $screenplay, array $unfinished): array
    {
        $scenes = collect((array) ($screenplay['scenes'] ?? []))->filter(static fn (mixed $row): bool => is_array($row))->keyBy('id');
        $locations = collect((array) ($screenplay['locations'] ?? []))->filter(static fn (mixed $row): bool => is_array($row))->keyBy('id');
        $needs = [];
        $interior = [];

        foreach ($rows as $row) {
            $state = is_array($row->state_json) ? $row->state_json : [];
            $video = is_array($row->video_plan_json) ? $row->video_plan_json : [];
            $scene = $scenes[(string) $row->screenplay_scene_code] ?? [];
            $location = $locations[(string) ($scene['location_id'] ?? '')] ?? [];
            $inside = ($location['spatial_relation'] ?? null) === \App\Video\Screenplay\LocationProfile::SUBJECT_PART
                && ($location[\App\Video\Screenplay\LocationProfile::ENCLOSURE_KEY] ?? null) === \App\Video\Screenplay\LocationProfile::INTERIOR;

            foreach (array_values(array_filter((array) ($state['reference_requirements'] ?? []), 'is_array')) as $index => $need) {
                $kind = $need['kind'] ?? null;

                if (! in_array($kind, ScenePlanAuthor::VIEWED_REFERENCE_KINDS, true)) {
                    continue;
                }

                $item = [
                    'need_id' => $row->scene_code.'#'.$index,
                    'need_sha256' => $this->referenceNeedHash($need),
                    'kind' => $kind,
                    'shot_code' => (string) $row->scene_code,
                    'scene_id' => (string) $row->screenplay_scene_code,
                    'stage' => $scene['stage'] ?? null,
                    'presentation' => in_array($scene['stage'] ?? null, $unfinished, true) ? ReferencePromptWriter::UNFINISHED : ReferencePromptWriter::FINISHED,
                    'view' => is_string($need['view'] ?? null) ? $need['view'] : null,
                    'state' => $need['state'] ?? null,
                    'must_show' => $need['must_show'] ?? null,
                    'purpose' => $need['purpose'] ?? null,
                    'design_refs' => array_values((array) ($need['design_refs'] ?? [])),
                    'camera' => $video['camera'] ?? null,
                    'action' => $video['action'] ?? null,
                    'start_state' => $state['keyframe_state'] ?? null,
                ];

                if ($kind === 'identity' && $inside) {
                    $interior[] = $item + ['location' => (string) ($location['name'] ?? $scene['location_id'] ?? '')];

                    continue;
                }

                $needs[] = $item;
            }
        }

        return [$needs, $interior];
    }

    /**
     * @return list<array{artifact_id: string, view: string, environment: string, presentation: string, state_details: ?string}> the approved references made from this anchor
     */
    private function referencePlanCatalog(string $projectId, string $anchorArtifactId, string $anchorSha): array
    {
        $catalog = [];

        foreach (VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::REFERENCE_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->whereNotNull('selected_artifact_id')
            ->with('artifact')
            ->orderBy('slot_index')
            ->get() as $image) {
            $spec = is_array($image->prompt_spec_json) ? $image->prompt_spec_json : [];

            if ($image->artifact === null || ($spec['reference_mode'] ?? null) === \App\Video\Reference\ReferenceSheet::MODE
                || ! is_string($spec['view_key'] ?? null)
                || ($spec['identity_anchor_artifact_id'] ?? null) !== $anchorArtifactId
                || ($spec['identity_anchor_sha256'] ?? null) !== $anchorSha) {
                continue;
            }

            $catalog[] = [
                'artifact_id' => (string) $image->artifact->id,
                'view' => $spec['view_key'],
                'environment' => (string) ($spec['environment'] ?? ''),
                'presentation' => is_string($spec['presentation'] ?? null) ? $spec['presentation'] : ReferencePromptWriter::BARE_METAL,
                'build_stage' => is_string($spec['build_stage'] ?? null) ? $spec['build_stage'] : null,
                'state_details' => is_string($spec['state_details'] ?? null) ? $spec['state_details'] : null,
            ];
        }

        return $catalog;
    }

    /**
     * @return array{0: ?VideoPlanningStage, 1: string, 2: list<string>}
     */
    public function writeReferencePlan(string $projectId, ?string $actorId, bool $force = false): array
    {
        $actor = $actorId === null ? null : Admin::find($actorId);
        $project = $actor === null ? null : $this->videoProjectRepository->getById($projectId);

        if ($project === null || Gate::forUser($actor)->denies('update', $project)) {
            return [null, 'project_not_found', []];
        }

        [$source, $why] = $this->referencePlanSource($projectId, true);

        if ($source === null) {
            return [null, $why, []];
        }

        $keys = ['storyboard_stage_id', 'screenplay_hash', 'design_content_hash', 'anchor_sha256', 'needs'];
        $frozen = array_intersect_key($source, array_flip($keys));

        return app(ReferencePromptWriter::class)->writePlan($projectId, $source, $force, function () use ($projectId, $frozen, $keys): ?string {
            [$now, $why] = $this->referencePlanSource($projectId);

            if ($now === null) {
                return $why;
            }

            return array_intersect_key($now, array_flip($keys)) == $frozen ? null : 'production_changed';
        });
    }

    /**
     * @return array{0: bool, 1: string}
     */
    public function decideReferencePlanItem(string $projectId, ?string $actorId, string $stageId, string $requestId, string $status): array
    {
        $actor = $actorId === null ? null : Admin::find($actorId);
        $project = $actor === null ? null : $this->videoProjectRepository->getById($projectId);

        if ($project === null || Gate::forUser($actor)->denies('update', $project)) {
            return [false, 'project_not_found'];
        }

        if (! in_array($status, [ReferencePromptWriter::APPROVED, ReferencePromptWriter::SKIPPED], true)) {
            return [false, 'reference_plan_decision_unknown'];
        }

        [$source, $why] = $this->referencePlanSource($projectId);

        if ($source === null) {
            return [false, $why];
        }

        return DB::transaction(function () use ($projectId, $actorId, $stageId, $requestId, $status, $source): array {
            $current = app(ReferencePromptWriter::class)->currentPlan($projectId, $source);

            if ($current === null || (string) $current->id !== $stageId) {
                return [false, 'reference_plan_stale'];
            }

            $stage = VideoPlanningStage::query()->whereKey($current->id)->lockForUpdate()->firstOrFail();
            $output = (array) $stage->output_json;
            $item = collect((array) ($output['references'] ?? []))->firstWhere('request_id', $requestId);

            if (! is_array($item)) {
                return [false, 'reference_plan_item_not_found'];
            }

            if ($status === ReferencePromptWriter::APPROVED && $item['recommendation'] === ReferencePromptWriter::NEEDS_REVIEW) {
                return [false, 'reference_plan_item_needs_review'];
            }

            $output['decisions'][$requestId] = ['status' => $status, 'by' => $actorId, 'at' => now()->toIso8601String()];
            $stage->forceFill(['output_json' => $output])->save();

            return [true, 'ok'];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?VideoDesignImage, 1: string}
     */
    public function renderReferencePlanItem(string $projectId, string $creator, array $data): array
    {
        [$source, $why] = $this->referencePlanSource($projectId);

        if ($source === null) {
            return [null, $why];
        }

        $environment = ReferenceEnvironment::from((string) $data['environment']);
        [$ready, $why] = app(ReferencePromptWriter::class)->plannedRenderable(
            $projectId, $source, (string) $data['reference_plan_stage_id'], (string) $data['request_id'], $environment, (string) $data['prompt_sha256'],
        );

        if ($ready === null) {
            return [null, $why];
        }

        $item = $ready['item'];
        $stages = collect((array) ($ready['stage']->output_json['needs'] ?? []))
            ->whereIn('need_id', $item['need_ids'])
            ->where('kind', 'identity')
            ->where('presentation', ReferencePromptWriter::UNFINISHED)
            ->pluck('stage')->unique()->values()->all();

        [$image, $reason] = $this->designImageStore->createReference($projectId, $creator, [
            'operation' => 'edit',
            'derivation' => 'gpt_edit',
            'reference_mode' => ReferencePromptWriter::PLAN_MODE,
            'reference_plan_stage_id' => (string) $ready['stage']->id,
            'request_id' => $item['request_id'],
            'need_ids' => $item['need_ids'],
            'presentation' => $item['presentation'],
            'build_stage' => count($stages) === 1 ? $stages[0] : null,
            'planned_frame_version' => \App\Video\Reference\IdentityPreservationPrompt::PLANNED_VERSION,
            'state_details' => $item['state_details'],
            'design_stage_id' => $source['design_stage_id'],
            'design_content_hash' => $source['design_content_hash'],
            'source_image_id' => $source['anchor_image_id'],
            'source_artifact_id' => $source['anchor_artifact_id'],
            'source_artifact_sha256' => $source['anchor_sha256'],
            'identity_anchor_artifact_id' => $source['anchor_artifact_id'],
            'identity_anchor_sha256' => $source['anchor_sha256'],
            'prompt' => $ready['prompt'],
            'variations' => (int) $data['variations'],
            'project_id' => $projectId,
            'image_type' => DesignImageStore::REFERENCE_TYPE,
            'view_key' => $item['view'],
            'environment' => $environment->value,
            'slot_index' => ReferenceView::tryFrom((string) $item['view'])?->slot() ?? 0,
            'identity_lock_id' => null,
            'identity_lock_hash' => null,
            'derivation_version' => ReferencePromptWriter::PLAN_DERIVATION_VERSION,
            'model' => (string) $data['model'],
            'quality' => (string) $data['quality'],
            'size' => (string) $data['size'],
        ]);

        if ($image === null) {
            return [null, $reason];
        }

        if ($reason === 'already_exists' && in_array($image->status, [DesignImageStatus::RENDERED->value, DesignImageStatus::APPROVED->value], true)) {
            return [$image, 'already_exists'];
        }

        return $this->designImageDirectRenderer->renderNow($image->id);
    }

    /**
     * @return array<string, mixed> what the Reference page shows of the production reference plan
     */
    public function referencePlanPanel(string $projectId): array
    {
        [$source, $why] = $this->referencePlanSource($projectId);
        $panel = ['error' => $source === null ? $why : null, 'source' => null, 'plan' => null, 'needs' => [], 'interior' => [], 'run' => null,
            'model' => (string) config('image_prompt.reference_plan.model'), 'effort' => (string) config('image_prompt.reference_plan.effort')];
        $latest = VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::REFERENCE_PROMPT->value)
            ->where('input_json->mode', ReferencePromptWriter::PLAN_MODE)
            ->orderByDesc('updated_at')
            ->first(['id', 'status', 'error_message', 'updated_at']);
        $panel['run'] = $latest === null ? null : ['status' => (string) $latest->status, 'error' => $latest->status === VideoPlanningStageStatus::FAILED->value ? (string) $latest->error_message : null, 'at' => $latest->updated_at?->format('d/m H:i')];

        if ($source === null) {
            return $panel;
        }

        $panel['source'] = array_intersect_key($source, array_flip(['storyboard_revision', 'design_revision', 'design_stage_id', 'anchor_artifact_id']));
        $panel['needs'] = $source['needs'];
        $panel['interior'] = $source['interior'];
        $stage = app(ReferencePromptWriter::class)->currentPlan($projectId, $source);

        if ($stage === null) {
            return $panel;
        }

        $output = (array) $stage->output_json;
        $cells = collect($this->designImageStore->referenceCellsFor($projectId));
        $items = [];

        foreach ((array) ($output['references'] ?? []) as $item) {
            $prompts = [];

            if ($item['recommendation'] === ReferencePromptWriter::RENDER_NEW) {
                foreach (ReferenceEnvironment::cases() as $environment) {
                    $prompt = ReferencePromptWriter::assemblePlanned($item, $environment);
                    $prompts[$environment->value] = ['prompt' => $prompt, 'prompt_sha256' => hash('sha256', $prompt)];
                }
            }

            $items[] = $item + [
                'decision' => $output['decisions'][$item['request_id']]['status'] ?? null,
                'prompts' => $prompts,
                'cells' => $cells->filter(static fn (array $cell): bool => ($cell['reference_mode'] ?? null) === ReferencePromptWriter::PLAN_MODE
                    && ($cell['request_id'] ?? null) === $item['request_id'] && ($cell['reference_plan_stage_id'] ?? null) === (string) $stage->id)->values()->all(),
            ];
        }

        $panel['plan'] = [
            'stage_id' => (string) $stage->id,
            'at' => $stage->updated_at?->format('d/m H:i'),
            'author_model' => $output['author_model'] ?? null,
            'usage' => $output['usage'] ?? null,
            'items' => $items,
            'unresolved' => (array) ($output['unresolved'] ?? []),
        ];

        return $panel;
    }

    /**
     * @return array<string, array{need_sha256: string, artifact_ids: list<string>}> need id => the images an approved reference plan item gives it
     */
    private function referencePlanLinks(string $projectId, ?VideoPlanningStage $storyboard): array
    {
        $key = 'plan_links:'.$projectId.':'.($storyboard?->id ?? '');

        if ($this->sourceMemo !== null && array_key_exists($key, $this->sourceMemo)) {
            return $this->sourceMemo[$key];
        }

        [$source] = $storyboard === null ? [null] : $this->referencePlanSource($projectId);
        $plan = $source === null || $source['storyboard_stage_id'] !== (string) $storyboard->id
            ? null
            : app(ReferencePromptWriter::class)->currentPlan($projectId, $source);
        $output = is_array($plan?->output_json) ? $plan->output_json : [];
        $hashes = collect((array) ($output['needs'] ?? []))->pluck('need_sha256', 'need_id')->all();
        $links = [];

        foreach ((array) ($output['references'] ?? []) as $item) {
            if (($output['decisions'][$item['request_id']]['status'] ?? null) === ReferencePromptWriter::SKIPPED) {
                continue;
            }

            $artifacts = match ($item['recommendation']) {
                ReferencePromptWriter::REUSE_ANCHOR => [$source['anchor_artifact_id']],
                ReferencePromptWriter::REUSE_REFERENCE => in_array($item['existing_artifact_id'], array_column($source['catalog'], 'artifact_id'), true)
                    ? [(string) $item['existing_artifact_id']] : [],
                ReferencePromptWriter::RENDER_NEW => VideoDesignImage::query()
                    ->where('project_id', $projectId)
                    ->where('image_type', DesignImageStore::REFERENCE_TYPE)
                    ->where('status', DesignImageStatus::APPROVED->value)
                    ->where('prompt_spec_json->reference_plan_stage_id', (string) $plan->id)
                    ->where('prompt_spec_json->request_id', $item['request_id'])
                    ->where('prompt_spec_json->identity_anchor_artifact_id', $source['anchor_artifact_id'])
                    ->where('prompt_spec_json->identity_anchor_sha256', $source['anchor_sha256'])
                    ->pluck('selected_artifact_id')->map('strval')->all(),
                default => [],
            };

            foreach ($item['need_ids'] as $needId) {
                if (is_string($hashes[$needId] ?? null)) {
                    $links[$needId] = ['need_sha256' => $hashes[$needId], 'artifact_ids' => array_values(array_filter($artifacts))];
                }
            }
        }

        if ($this->sourceMemo !== null) {
            $this->sourceMemo[$key] = $links;
        }

        return $links;
    }

    /** @return array<string, mixed>|null */
    public function environmentPageData(string $projectId): ?array
    {
        $project = $this->videoProjectRepository->getById($projectId);

        if ($project === null) {
            return null;
        }

        [$linkedPlan, $requirements, $requirementsError, $environmentSource, $withoutPlate] = $this->selectedEnvironmentRequirements($project);
        $profile = $linkedPlan ? null : $this->environmentProfile($project);
        $environments = [];

        foreach ($requirements as $requirement) {
            $environments[] = $requirement + [
                'prompt' => EnvironmentPlatePrompt::text($requirement['place']),
            ];
        }

        foreach ($profile?->environmentKeys() ?? [] as $key) {
            $environments[] = [
                'key' => $key,
                'label' => (string) $profile->environmentLabelOf($key),
                'place' => (string) $profile->environmentPromptOf($key),
                'prompt' => EnvironmentPlatePrompt::text((string) $profile->environmentPromptOf($key)),
                'version' => $profile->version,
                'sha256' => $profile->sha256,
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
            'profileVersion' => $linkedPlan ? 'screenplay-environment-v1' : $profile?->version,
            'environments' => $environments,
            'environmentCells' => $this->designImageStore->environmentCellsFor($projectId),
            'plateVersion' => EnvironmentPlatePrompt::VERSION,
            'mediaModels' => $mediaModels,
            'defaultMediaModel' => $defaultMediaModel,
            'mediaModelsError' => $mediaModelsError,
            'environmentRequirementsError' => $requirementsError,
            'environmentSource' => $environmentSource,
            'environmentsWithoutPlate' => $withoutPlate,
            'renderedModels' => $this->modelsWithAnEnvironmentRender(),
            'qualityCosts' => collect(ImageQuality::cases())
                ->mapWithKeys(fn (ImageQuality $quality) => [
                    $quality->value => $quality->estimatedCostUsd(),
                ])
                ->all(),
        ];
    }

    /**
     * @return array{0: bool, 1: list<array<string, mixed>>, 2: ?string, 3: string, 4: list<array{label: string, scenes: list<string>}>}
     */
    private function selectedEnvironmentRequirements(VideoProject $project): array
    {
        $stage = $project->selectedScenePlanStage()
            ->where('project_id', $project->id)
            ->where('stage', PlanningStageName::SCENE_PLAN->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->first();
        $revision = is_array($stage?->output_json)
            ? (int) ($stage->output_json['revision'] ?? 0)
            : 0;

        if ($revision >= 1 && $this->reviewForRevision($stage)['status'] === 'passed') {
            $scenes = VideoRenderScene::query()
                ->where('project_id', $project->id)
                ->where('revision', $revision)
                ->where('screenplay_stage_id', $project->selected_screenplay_stage_id)
                ->orderBy('scene_index')
                ->get();
            $linked = $scenes->contains(
                static fn (VideoRenderScene $scene): bool => $scene->screenplay_stage_id !== null,
            );

            if (! $linked) {
                return [false, [], null, self::ENVIRONMENT_FROM_PROFILE, []];
            }

            $previousMemo = $this->sourceMemo;
            $this->sourceMemo ??= [];

            try {
                $entries = $scenes->map(fn (VideoRenderScene $scene): array => [
                    (string) $scene->screenplay_scene_code,
                    $this->screenplayEnvironmentRequirement($scene),
                ])->all();
            } finally {
                $this->sourceMemo = $previousMemo;
            }

            return $this->environmentRequirementsOf($entries, self::ENVIRONMENT_FROM_SCENE_PLAN);
        }

        $screenplayStage = $this->selectedProductionScreenplay($project);

        if ($screenplayStage === null || ! is_array($screenplayStage->output_json)) {
            return [false, [], null, self::ENVIRONMENT_FROM_PROFILE, []];
        }

        $screenplay = $this->productionScreenplayContent($screenplayStage->output_json);
        $context = $this->environmentContext((string) $project->id, $screenplayStage->output_json);
        $entries = [];

        foreach ((array) ($screenplay['scenes'] ?? []) as $screenplayScene) {
            if (is_array($screenplayScene)) {
                $entries[] = [(string) ($screenplayScene['id'] ?? ''), $context === null ? null : $this->sceneEnvironmentRequirement($screenplay, $screenplayScene, $context)];
            }
        }

        return $this->environmentRequirementsOf($entries, self::ENVIRONMENT_FROM_SCREENPLAY);
    }

    /**
     * @param  list<array{0: string, 1: array<string, mixed>|null}>  $entries
     * @return array{0: bool, 1: list<array<string, mixed>>, 2: ?string, 3: string, 4: list<array{label: string, scenes: list<string>}>}
     */
    private function environmentRequirementsOf(array $entries, string $source): array
    {
        $requirements = [];
        $withoutPlate = [];
        $keyOfLocation = [];
        $error = null;

        foreach ($entries as [$code, $requirement]) {
            if ($requirement === null) {
                $error ??= 'environment_requirement_unreadable';

                continue;
            }

            $location = (string) ($requirement['location_id'] ?? '');
            $setting = $location.'|'.(string) ($requirement['phase'] ?? '').'|'.(string) ($requirement['installed'] ?? '');

            if (($requirement['plate'] ?? true) === false) {
                $withoutPlate[$location] ??= ['label' => (string) $requirement['label'], 'scenes' => []];
                $withoutPlate[$location]['scenes'] = array_values(array_unique([...$withoutPlate[$location]['scenes'], $code]));

                continue;
            }

            if (isset($keyOfLocation[$setting]) && $keyOfLocation[$setting] !== $requirement['key']) {
                $error = 'environment_location_conflict';

                continue;
            }

            $keyOfLocation[$setting] = $requirement['key'];
            $requirements[$requirement['key']] ??= $requirement + ['scenes' => []];
            $requirements[$requirement['key']]['scenes'] = array_values(array_unique([...$requirements[$requirement['key']]['scenes'], $code]));
        }

        return [
            true,
            array_values($requirements),
            $error,
            $source,
            array_values($withoutPlate),
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
        $key = (string) ($data['environment_key'] ?? '');
        [$linkedPlan, $requirements, $requirementsError, $environmentSource] = $this->selectedEnvironmentRequirements($project);

        if ($requirementsError !== null) {
            return [null, $requirementsError];
        }
        $requirement = collect($requirements)->firstWhere('key', $key);

        if ($linkedPlan) {
            if (! is_array($requirement)) {
                return [null, 'unknown_environment_key'];
            }
        } else {
            if ($profile === null) {
                return [null, 'no_environment_profile'];
            }

            $place = $profile->environmentPromptOf($key);

            if ($place === null) {
                return [null, 'unknown_environment_key'];
            }

            $requirement = [
                'key' => $key,
                'place' => $place,
                'version' => $profile->version,
                'sha256' => $profile->sha256,
            ];
        }

        $place = (string) $requirement['place'];

        [$entry, $reason] = $this->environmentMediaModel((string) ($data['provider_model'] ?? ''));

        if ($entry === null) {
            return [null, $reason];
        }

        [$spec, $reason] = $this->environmentSpec(
            $projectId,
            $key,
            (string) $requirement['version'],
            (string) $requirement['sha256'],
            (string) ($requirement['prompt'] ?? EnvironmentPlatePrompt::text($place)),
            (string) ($requirement['plate_version'] ?? EnvironmentPlatePrompt::VERSION),
            $entry,
            $data,
            $linkedPlan ? [
                'location_id' => (string) ($requirement['location_id'] ?? ''),
                'plate_kind' => (string) ($requirement['kind'] ?? self::ENVIRONMENT_EXTERNAL),
                'environment_source' => $environmentSource,
            ] : [],
        );

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
     * @param  array<string, string>  $source
     * @return array{0: ?array<string, mixed>, 1: string} [$spec, $reason]
     */
    private function environmentSpec(
        string $projectId,
        string $key,
        string $profileVersion,
        string $profileSha256,
        string $prompt,
        string $plateVersion,
        array $entry,
        array $data,
        array $source = [],
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
            'spec_version' => $plateVersion,
            'environment_key' => $key,
            'profile_version' => $profileVersion,
            'profile_sha256' => $profileSha256,
            'prompt' => $prompt,
            'provider' => $entry['provider'],
            'model' => $entry['model'],
            'pricing' => $entry['pricing'],
            'variations' => $variations,
        ] + $source;

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
        string $projectId
    ): array {
        $project = $this->videoProjectRepository->getById($projectId);

        if ($project === null) {
            return [null, 'project_not_found', null];
        }
        $result = $this->skillAnchorPrompt($project->id);
        return $result;
    }

    /**
     * @return array{0: ?CompiledAnchorPrompt, 1: string, 2: ?array<string, mixed>}
     */
    private function skillAnchorPrompt(
        string $projectId
    ): array {
        $stage = AnchorStage::FABRICATION_GEOMETRY_ANCHOR;
        $size = ImageSize::LANDSCAPE;
        $model = CharacterAnchorPromptService::anchorModel();

        [$latestInspiration] = $this->stageStore->latestStageForProject($projectId, PlanningStageName::INSPIRATION, [], skipOrphans: true);

        if (ClaudeInspirationAnalyst::isPhaseOneRun($latestInspiration)) {
            return [null, 'inspiration_phase1_not_supported', null];
        }

        $brief = $this->stageStore->latestOutputForProject(
            $projectId,
            PlanningStageName::INSPIRATION,
        );

        if (! is_array($brief) || $brief === []) {
            return [null, 'no_inspiration_brief', null];
        }


        $input = [
            'brief_hash' => hash('sha256', json_encode($brief, JSON_UNESCAPED_UNICODE)),
            'skill_hash' => $this->geometryPromptAuthor->skillHash(),
        ];
        [$claimed, $token, $reason] = $this->stageStore->claimProjectStage(
            $projectId,
            PlanningStageName::ANCHOR_PROMPT,
            $input,
        );

        if ($reason === 'already_succeeded') {
            $compiled = $this->geometryPromptAuthor->rehydrate(
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
            $result = $this->geometryPromptAuthor->author($brief, $stage, $size, $model);
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
     * @return array{foundation: ?array<string, mixed>, stage_id: ?string, revision: ?int,
     *               selectable: bool, running: bool, error: ?string, written_at: ?string,
     *               profile_brief_revision: ?int, foundation_brief_revision: ?int, profile_source: ?string}
     */
    public function latestScreenplayFoundation(string $projectId): array
    {
        $project = $this->videoProjectRepository->getById($projectId);
        $profile = $project?->article === null
            ? null
            : $this->screenplayExpansion->screenplayProfile((string) ($project->article->category?->slug ?? ''));
        [$latest] = $this->stageStore->latestStageForProject(
            $projectId,
            PlanningStageName::SCREENPLAY_FOUNDATION,
            [],
        );

        $succeeded = VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::SCREENPLAY_FOUNDATION->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->orderByDesc('planning_revision')
            ->first();

        $stored = $succeeded?->output_json;
        $foundation = is_array($stored) && $stored !== [] ? $stored : null;

        return [
            'foundation' => $foundation,
            'stage_id' => $foundation !== null ? $succeeded->id : null,
            'revision' => $foundation !== null ? $succeeded->planning_revision : null,
            'selectable' => $foundation !== null
                && in_array($foundation['schema_version'] ?? null, (array) config('video.screenplay.scenes.foundation_versions'), true),
            'running' => $latest?->status === VideoPlanningStageStatus::RUNNING->value
                && $latest->lease_expires_at?->isFuture() === true,
            'error' => $latest?->status === VideoPlanningStageStatus::FAILED->value
                ? $latest->error_message
                : null,
            'written_at' => $foundation !== null ? $succeeded->finished_at?->format('d/m/Y H:i') : null,
            'profile_brief_revision' => $profile === null ? null : (FilmBrief::ofProfile($profile)[0]['revision'] ?? null),
            'foundation_brief_revision' => $foundation !== null
                ? (ScreenplayExpansionService::foundationBrief($succeeded)['revision'] ?? null)
                : null,
            'profile_source' => $foundation !== null
                ? ScreenplayExpansionService::profileForFoundation($succeeded, $profile)[1]
                : null,
        ];
    }

    /** @return array{0: bool, 1: string} */
    public function resetScreenplayFoundation(string $projectId): array
    {
        [$latest] = $this->stageStore->latestStageForProject(
            $projectId,
            PlanningStageName::SCREENPLAY_FOUNDATION,
            [],
        );

        if ($latest === null) {
            return [false, 'Chua co luot viet nen tang nao'];
        }

        return $this->stageStore->releaseClaim($latest->id, 'Nguoi dung reset thu cong')
            ? [true, 'ok']
            : [false, 'Luot nay khong con giu claim — khong co gi de reset'];
    }

    /**
     * @return array{screenplay: ?array<string, mixed>, stage_id: ?string, approved: bool,
     *               selected: bool, selected_stage_id: ?string, selection_version: int,
     *               warnings: list<string>, running: bool,
     *               error: ?string, written_at: ?string, foundation_stage_id: ?string,
     *               foundation_revision: ?int}
     */
    public function latestScreenplayScenes(string $projectId): array
    {
        $attempt = $this->latestSceneExpansionStage($projectId);
        $latest = $this->latestSuccessfulSceneExpansionStage($projectId);

        $stored = $latest?->output_json;

        $warnings = is_array($stored) ? ($stored['warnings'] ?? []) : [];

        if (is_array($stored)) {
            unset($stored['warnings']);
        }

        $source = is_array($latest?->input_json) ? ($latest->input_json['_meta'] ?? []) : [];
        $approval = $latest === null ? null : $this->screenplayApprovalService->matchingApproval($latest);
        $project = VideoProject::query()->find($projectId);
        $selectedStageId = $project?->selected_screenplay_stage_id;

        return [
            'screenplay' => is_array($stored) && $stored !== [] ? $stored : null,
            'stage_id' => is_array($stored) && $stored !== [] ? $latest->id : null,
            'approved' => $approval !== null,
            'selected' => $latest !== null && (string) $selectedStageId === (string) $latest->id,
            'selected_stage_id' => $selectedStageId,
            'selection_version' => (int) ($project?->production_selection_version ?? 0),
            'warnings' => is_array($warnings) ? array_values($warnings) : [],
            'running' => $attempt?->status === VideoPlanningStageStatus::RUNNING->value
                && $attempt->lease_expires_at?->isFuture() === true,
            'error' => $attempt?->status === VideoPlanningStageStatus::FAILED->value
                ? $attempt->error_message
                : null,
            'written_at' => $latest?->finished_at?->format('d/m/Y H:i'),
            'revision' => $latest?->planning_revision === null ? null : (int) $latest->planning_revision,
            'foundation_stage_id' => $source['foundation_stage_id'] ?? null,
            'foundation_revision' => $source['foundation_revision'] ?? null,
        ];
    }

    /** @return array{0: bool, 1: string} */
    public function approveScreenplay(
        string $projectId,
        string $stageId,
        ?string $reviewerId,
        string $operationId,
        ?string $reason = null,
    ): array {
        [$decision, $result] = $this->screenplayApprovalService->approve(
            $projectId, $stageId, $reviewerId, $operationId, $reason,
        );

        return [$decision !== null, $result];
    }

    /** @return array{0: bool, 1: string} */
    public function selectScreenplayForProduction(
        string $projectId,
        string $stageId,
        int $expectedVersion,
    ): array {
        [$project, $result] = $this->productionSelectionService->selectScreenplay(
            $projectId, $stageId, $expectedVersion,
        );

        return [$project !== null, $result];
    }

    /** @return array{0: bool, 1: string} */
    public function resetScreenplayScenes(string $projectId): array
    {
        $latest = $this->latestSceneExpansionStage($projectId);

        if ($latest === null) {
            return [false, 'Chua co luot tao phan canh nao'];
        }

        return $this->stageStore->releaseClaim($latest->id, 'Nguoi dung reset thu cong')
            ? [true, 'ok']
            : [false, 'Luot nay khong con giu claim — khong co gi de reset'];
    }

    private function latestSceneExpansionStage(string $projectId): ?VideoPlanningStage
    {
        return VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::SCREENPLAY->value)
            ->whereIn('input_json->contract_version', \App\Video\Screenplay\ScreenplayValidator::EXPANSION_CONTRACTS)
            ->whereNull('input_json->orphan_of_stage_id')
            ->orderByDesc('planning_revision')
            ->first();
    }

    private function latestSuccessfulSceneExpansionStage(string $projectId): ?VideoPlanningStage
    {
        return VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::SCREENPLAY->value)
            ->whereIn('input_json->contract_version', \App\Video\Screenplay\ScreenplayValidator::EXPANSION_CONTRACTS)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->orderByDesc('planning_revision')
            ->first();
    }

    private function selectedProductionScreenplay(VideoProject $project): ?VideoPlanningStage
    {
        return $this->productionSelectionService->selectedScreenplay($project);
    }

    /** @param array<string, mixed> $output */
    private function productionScreenplayContent(array $output): array
    {
        foreach (['author_model', 'source_foundation', 'source_characters', 'source_locations', 'warnings',
            VesselDesign::SOURCE_DESIGN_KEY, VesselDesign::SOURCE_ANCHOR_KEY] as $metadata) {
            unset($output[$metadata]);
        }

        return $output;
    }

    /** @return array{0: ?string, 1: string} [$subjectKey, $reason] */
    public function productionSubjectKey(string $projectId): array
    {
        $memoKey = 'production_subject:'.$projectId;

        if ($this->sourceMemo !== null && array_key_exists($memoKey, $this->sourceMemo)) {
            return $this->sourceMemo[$memoKey];
        }

        $project = VideoProject::query()->find($projectId);

        if ($project === null) {
            return $this->rememberSource($memoKey, [null, 'project_not_found']);
        }

        if ($project->selected_screenplay_stage_id === null) {
            return $this->rememberSource($memoKey, [VisualIdentityStore::DEFAULT_SUBJECT_KEY, 'ok']);
        }

        $stage = $this->selectedProductionScreenplay($project);

        if ($stage === null) {
            return $this->rememberSource($memoKey, [null, 'screenplay_not_selected']);
        }

        $subjectKey = app(ScreenplaySubjectService::class)->primarySubjectKey($stage);

        return $this->rememberSource($memoKey, $subjectKey === null
            ? [null, 'screenplay_has_no_main_object']
            : [$subjectKey, 'ok']);
    }

    private function referenceAnchor(string $projectId): ?VideoDesignImage
    {
        $project = VideoProject::query()->find($projectId);

        if (! VesselDesign::isDesignFirst($project)) {
            return $this->productionAnchor($projectId);
        }

        $designs = app(VesselDesignService::class);
        $lock = $designs->anchorSelection($project);

        return $lock === null ? null : $designs->anchorBySource($projectId, $lock);
    }

    private function productionAnchor(string $projectId): ?VideoDesignImage
    {
        $project = VideoProject::query()->find($projectId);
        $stage = $project === null ? null : $this->selectedProductionScreenplay($project);
        $locked = is_array($stage?->output_json) ? ($stage->output_json[VesselDesign::SOURCE_ANCHOR_KEY] ?? null) : null;

        if (is_array($locked)) {
            $designs = app(VesselDesignService::class);
            $image = $designs->anchorBySource($projectId, $locked);
            $sourceDesign = $stage->output_json[VesselDesign::SOURCE_DESIGN_KEY] ?? null;
            $replacement = $image?->status === DesignImageStatus::SUPERSEDED->value && VesselDesign::isDesignFirst($project) && is_array($sourceDesign)
                ? $designs->replacementAnchor($project, $locked, $sourceDesign)
                : null;

            return $replacement ?? $image;
        }

        if ($stage === null && VesselDesign::isDesignFirst($project)) {
            $designs = app(VesselDesignService::class);
            $lock = $designs->anchorSelection($project);

            return $lock === null ? null : $designs->anchorBySource($projectId, $lock);
        }

        [$subjectKey] = $this->productionSubjectKey($projectId);

        return $subjectKey === null
            ? null
            : $this->designImageStore->approvedAnchorFor($projectId, $subjectKey);
    }


    /** @return array{prompt:string,prompt_sha256:string,stage:string,viewpoint:string,size:string,compiled_at:string,identity_id:?string,identity_hash:?string,identity_version:?int}|null */
    public function anchorPromptPreview(string $projectId, ?string $characterId = null): ?array
    {
        $project = $this->videoProjectRepository->getById($projectId);
        $preview = $characterId === null
            ? ($project?->metadata_json['anchor_prompt_preview'] ?? null)
            : ($project?->metadata_json['anchor_prompt_previews'][$characterId] ?? null);

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
        ?array $character = null,
    ): bool {
        $project = $this->videoProjectRepository->getById($projectId);

        if ($project === null) {
            return false;
        }

        $metadata = $project->metadata_json ?? [];

        if ($character !== null) {
            $metadata['anchor_prompt_previews'][(string) $character['id']] = [
                'prompt' => $compiled->prompt,
                'prompt_sha256' => $compiled->promptHash,
                'negative_prompt' => $compiled->negativePrompt,
                'native_controls' => $compiled->nativeControls,
                'lineage' => $compiled->lineage(),
                'identity_id' => null,
                'identity_hash' => null,
                'identity_version' => null,
                'character_id' => (string) $character['id'],
                'character_name' => (string) ($character['name'] ?? $character['id']),
                'character_kind' => (string) ($character['kind'] ?? ''),
                'screenplay_stage_id' => isset($character['screenplay_stage_id'])
                    ? (string) $character['screenplay_stage_id']
                    : null,
                'design_stage_id' => isset($character['design_stage_id']) ? (string) $character['design_stage_id'] : null,
                'design_content_hash' => isset($character['design_content_hash'])
                    ? (string) $character['design_content_hash']
                    : null,
                'anchor_prompt_stage_id' => isset($character['anchor_prompt_stage_id'])
                    ? (string) $character['anchor_prompt_stage_id']
                    : null,
                'source_hash' => isset($character['source_hash']) ? (string) $character['source_hash'] : null,
                'skill_hash' => isset($character['skill_hash']) ? (string) $character['skill_hash'] : null,
                'stage' => $stage->value,
                'viewpoint' => $viewpoint->value,
                'size' => $size->value,
                'compiled_at' => now()->toIso8601String(),
            ];

            $project->metadata_json = $metadata;

            return $project->save();
        }

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
            'prompt' => null,
            'run_error' => null,
            'source' => null,
            'format' => null,
            'json' => null,
            'json_error' => null,
            'source_check' => null,
            'source_current' => false,
            'source_passed' => false,
            'can_design' => false,
        ];
    }

    /** @return array{0: AiPhase, 1: string, 2: array<string, mixed>} [$prompt, $articleBlock, $input] */
    private function inspirationRun(VideoProject $project): array
    {
        $category = $project->article?->category
            ?? throw new \RuntimeException('The article of this project has no category.');
        $workflow = config('video.superyacht.contracts.categories')[$category->slug] ?? null;

        if (! is_string($workflow) || $workflow === '') {
            throw new \RuntimeException("Category {$category->slug} has no configured workflow.");
        }

        $prompt = AiPhase::currentFor((string) $category->id, $workflow, ClaudeInspirationAnalyst::PHASE_CODE);
        $articleBlock = $this->canonicalConceptInputBuilder->articleBlock($this->rawArticleFromModel($project->article));

        return [$prompt, $articleBlock, [
            'execution' => ClaudeInspirationAnalyst::EXECUTION,
            'article_id' => (string) $project->article->id,
            'category_id' => (string) $category->id,
            'workflow' => $workflow,
            'phase_id' => (string) $prompt->id,
            'phase_revision' => (int) $prompt->revision,
            'prompt_sha256' => (string) $prompt->content_sha256,
            'article_sha256' => hash('sha256', $articleBlock),
        ] + $this->canonicalConceptInputBuilder->inspirationSettings()];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function inspirationOutput(array $input, string $rawText, string $articleBlock): array
    {
        [$json, $error] = self::parseForDisplay($rawText);

        return [
            'execution' => ClaudeInspirationAnalyst::EXECUTION,
            'checked' => false,
            'prompt' => array_intersect_key($input, array_flip(['phase_id', 'phase_revision', 'prompt_sha256', 'workflow', 'category_id'])),
            'raw_text' => $rawText,
            'parsed_json' => $json,
            'json_parse_error' => $error,
            'source' => self::sourceCheckOf($rawText, $articleBlock),
        ];
    }

    /** @return array<string, mixed> */
    private static function sourceCheckOf(string $rawText, string $articleBlock): array
    {
        [$json, $error] = self::parseForDisplay($rawText);
        $response = $error === null ? json_decode((string) json_encode($json), true) : null;

        return SourceCheck::evaluate(is_array($response) ? $response : null, $articleBlock);
    }

    /** @return array{0: mixed, 1: ?string} */
    private static function parseForDisplay(string $text): array
    {
        $decoded = json_decode($text);

        if (json_last_error() === JSON_ERROR_NONE) {
            return [$decoded, null];
        }

        $error = json_last_error_msg();

        if (preg_match('/\A\s*```(?:json)?[ \t]*\r?\n(.*)\r?\n```\s*\z/s', $text, $fenced) === 1) {
            $decoded = json_decode($fenced[1]);

            if (json_last_error() === JSON_ERROR_NONE) {
                return [$decoded, null];
            }

            $error = json_last_error_msg();
        }

        return [null, $error];
    }

    /** @return array{0: ?int, 1: string} [$shotCount, $reason] */
    public function generateStoryboard(string $projectId, ?string $actorId, bool $force = false, string $mode = self::STORYBOARD_MODE): array
    {
        $actor = $actorId === null ? null : Admin::find($actorId);

        if ($actor === null) {
            return [null, 'project_not_found'];
        }

        $project = $this->videoProjectRepository->getById($projectId);

        if ($project === null || Gate::forUser($actor)->denies('update', $project)) {
            return [null, 'project_not_found'];
        }

        $screenplayStage = $this->selectedProductionScreenplay($project);

        if ($screenplayStage === null) {
            return [null, 'screenplay_not_selected'];
        }

        $screenplay = $this->productionScreenplayContent($screenplayStage->output_json);

        if (! \App\Video\Screenplay\SceneBeats::usesBeats($screenplay)) {
            return [null, 'storyboard_needs_beats'];
        }

        $screenplayHash = \App\Video\Screenplay\ScreenplayContentHash::of($screenplay);

        [$subjectKey, $subjectReason] = $this->productionSubjectKey($projectId);

        if ($subjectKey === null) {
            return [null, $subjectReason];
        }

        $designFirst = VesselDesign::isDesignFirst($project);
        $anchor = $designFirst ? null : $this->productionAnchor($projectId);

        if (! $designFirst && $anchor === null) {
            return [null, 'no_approved_anchor'];
        }

        $category = (string) ($project->article->category?->slug ?? '');
        $version = config("video.scene_plan.profiles.{$category}");

        if (! is_string($version) || $version === '') {
            return [null, 'no_scene_profile'];
        }

        $profile = SceneProfile::load((string) config('video.scene_plan.profile_dir'), $version);

        [$source, $sourceReason] = $anchor === null
            ? $this->designStoryboardSource($projectId, $screenplayStage->output_json[VesselDesign::SOURCE_DESIGN_KEY] ?? null, $this->storyboardFilm($category))
            : $this->legacyStoryboardSource($anchor, $profile);

        if ($source === null) {
            return [null, $sourceReason];
        }

        if ($mode === self::FILM_CUT_MODE) {
            return $source['mode'] === ScenePlanAuthor::DESIGN_FIRST_SOURCE
                ? $this->generateFilmCut($project, $screenplayStage, $screenplay, $screenplayHash, $source, $profile, $force)
                : [null, 'film_cut_design_missing'];
        }

        if ($mode !== self::STORYBOARD_MODE) {
            return [null, 'storyboard_mode_unknown'];
        }

        return $this->planStoryboard($projectId, $screenplayStage, $screenplay, $screenplayHash, $source, $profile, $force, null);
    }

    /**
     * @return array{0: ?int, 1: string}
     */
    public function recheckStoredStoryboard(string $projectId, ?string $actorId): array
    {
        $actor = $actorId === null ? null : Admin::find($actorId);
        $project = $actor === null ? null : $this->videoProjectRepository->getById($projectId);

        if ($project === null || Gate::forUser($actor)->denies('update', $project)) {
            return [null, 'project_not_found'];
        }

        $failed = $this->storedStoryboardAnswer($projectId);

        if ($failed === null) {
            return [null, 'storyboard_nothing_to_recheck'];
        }

        $screenplayStage = $this->selectedProductionScreenplay($project);

        if ($screenplayStage === null) {
            return [null, 'screenplay_not_selected'];
        }

        $screenplay = $this->productionScreenplayContent($screenplayStage->output_json);
        $designFirst = VesselDesign::isDesignFirst($project);
        $anchor = $designFirst ? null : $this->productionAnchor($projectId);
        $version = config('video.scene_plan.profiles.'.($project->article->category?->slug ?? ''));

        if ((! $designFirst && $anchor === null) || ! is_string($version) || $version === '') {
            return [null, 'storyboard_recheck_input_changed'];
        }

        $profile = SceneProfile::load((string) config('video.scene_plan.profile_dir'), $version);
        [$source] = $anchor === null
            ? $this->designStoryboardSource(
                $projectId, $screenplayStage->output_json[VesselDesign::SOURCE_DESIGN_KEY] ?? null,
                $this->storyboardFilm((string) ($project->article->category?->slug ?? '')),
            )
            : $this->legacyStoryboardSource($anchor, $profile);

        if ($source === null) {
            return [null, 'storyboard_recheck_input_changed'];
        }

        return $this->planStoryboard(
            $projectId, $screenplayStage, $screenplay, \App\Video\Screenplay\ScreenplayContentHash::of($screenplay), $source, $profile, true, $failed,
        );
    }

    /**
     * @return VideoPlanningStage|null the newest failed storyboard whose kept answer this contract can check again
     */
    public function storedStoryboardAnswer(string $projectId): ?VideoPlanningStage
    {
        $latest = VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::SCENE_PLAN->value)
            ->whereNull('input_json->orphan_of_stage_id')
            ->orderByDesc('planning_revision')
            ->first();

        return $latest !== null
            && $latest->status === VideoPlanningStageStatus::FAILED->value
            && ($latest->input_json['scene_contract_version'] ?? null) === ScenePlanAuthor::STORYBOARD_CONTRACT_VERSION
            && ! array_key_exists('orphan_of_stage_id', (array) $latest->input_json)
            && trim((string) $latest->raw_response) !== ''
            && json_decode((string) $latest->raw_response, true) !== null
                ? $latest
                : null;
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @param  array<string, mixed>  $source
     * @return array{0: ?int, 1: string}
     */
    private function planStoryboard(
        string $projectId,
        VideoPlanningStage $screenplayStage,
        array $screenplay,
        string $screenplayHash,
        array $source,
        SceneProfile $profile,
        bool $force,
        ?VideoPlanningStage $storedFrom,
    ): array {
        if ($source['design'] !== null && VesselDesign::p0($source['design']) === []) {
            return [null, 'storyboard_design_identity_missing'];
        }

        if ($source['mode'] === ScenePlanAuthor::DESIGN_FIRST_SOURCE && $source['film'] === null) {
            return [null, 'storyboard_film_profile_invalid'];
        }

        $author = app(ScenePlanAuthor::class);

        try {
            $skillHash = $author->skillHash();
            $schemaHash = $author->schemaHash();
        } catch (ScenePlanException $e) {
            $this->quietLog('storyboard: author skill unreadable', $e, ['project_id' => $projectId]);

            return [null, 'scene_plan_misconfigured'];
        }

        $packet = $this->storyboardPacket($screenplay, $source);

        $claimInput = [
            'source_mode' => $source['mode'],
            'screenplay_stage_id' => $screenplayStage->id,
            'screenplay_revision' => $screenplayStage->planning_revision,
            'screenplay_contract_version' => $screenplay['schema_version'],
            'screenplay_hash' => $screenplayHash,
        ] + $source['claim'] + [
            'profile_version' => $profile->version,
            'profile_sha256' => $profile->sha256,
            'packet_version' => ScenePlanAuthor::PACKET_VERSION,
            'packet_hash' => $this->digest($packet),
            'skill_hash' => $skillHash,
            'schema_hash' => $schemaHash,
            'prompt_version' => $author->promptVersion(),
            'scene_contract_version' => $author->contractVersion(),
            'check_version' => StoryboardPlanCheck::VERSION,
            'preservation_version' => ScenePreservationPrompt::VERSION,
            'provider' => (string) config('canonical_concept.provider'),
            'model' => $author->model(),
        ];
        $stored = null;

        if ($storedFrom !== null) {
            $kept = array_diff_key((array) $storedFrom->input_json, ['check_version' => true, PlanningStageStore::METADATA_KEY => true, 'rechecked_stage_id' => true]);

            if ($this->canonical($kept) !== $this->canonical(array_diff_key($claimInput, ['check_version' => true]))) {
                return [null, 'storyboard_recheck_input_changed'];
            }

            try {
                $stored = $author->fromStored((string) $storedFrom->raw_response, is_string($storedFrom->provider_model) ? $storedFrom->provider_model : null);
            } catch (ScenePlanException) {
                return [null, 'storyboard_nothing_to_recheck'];
            }

            $claimInput['rechecked_stage_id'] = (string) $storedFrom->id;
        }

        $recheck = $source['mode'] === ScenePlanAuthor::DESIGN_FIRST_SOURCE
            ? fn (): ?string => $this->storyboardSourceChange($projectId, $claimInput)
            : null;

        return $this->authorScenePlan(
            $projectId, $author, $packet, $claimInput, $force,
            function (ScenePlanResult $result) use ($screenplay, $screenplayStage, $screenplayHash, $author, $source, $recheck): array {
                $changed = $recheck === null ? null : $recheck();

                if ($changed !== null) {
                    throw new ScenePlanException(self::SOURCE_CHANGED.$changed);
                }

                [$shots, $warnings, $conflicts] = $this->storyboardShots(
                    $result->scenes, $screenplay, $source['design'], $source['clip_durations_ms'],
                    is_int($source['film']['min_shot_used_ms'] ?? null) ? $source['film']['min_shot_used_ms'] : null,
                );
                $check = StoryboardPlanCheck::run(
                    $shots, $this->screenplayScenesOf($screenplay), $conflicts,
                    is_int($source['film']['total_ms'] ?? null) ? $source['film']['total_ms'] : null, $warnings,
                );

                foreach ($shots as $index => $shot) {
                    $shots[$index]['timeline'] = $check['timeline'][$shot['scene_code']] ?? null;
                }

                return [
                    $shots,
                    $this->storyboardRows($shots, $screenplayStage, $screenplayHash, $author->promptVersion()),
                    $warnings,
                    ['findings' => $check['findings'], 'check_version' => StoryboardPlanCheck::VERSION],
                ];
            },
            $stored,
        );
    }

    /**
     * @return array{total_ms: int, aspect_ratio: string, min_shot_used_ms: int}|null the finished film the screenplay profile sets
     */
    private function storyboardFilm(string $category): ?array
    {
        $profile = app(ScreenplayExpansionService::class)->screenplayProfile($category);

        if ($profile === null || \App\Video\Screenplay\ScreenplayValidator::filmCutViolations($profile) !== []) {
            return null;
        }

        $film = $profile['film_cut'];

        return [
            'total_ms' => $film['total_ms'],
            'aspect_ratio' => (string) config('video.screenplay.aspect_ratio'),
            'min_shot_used_ms' => $film['min_shot_ms'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $film  the finished film the screenplay profile sets
     * @return array{0: ?array{mode: string, subject: array<string, mixed>, claim: array<string, mixed>, design: ?array<string, mixed>}, 1: string}
     */
    private function designStoryboardSource(string $projectId, mixed $designSource, ?array $film = null): array
    {
        if (! is_array($designSource) || ! is_string($designSource['stage_id'] ?? null) || ! is_string($designSource['content_hash'] ?? null)) {
            return [null, 'storyboard_design_source_missing'];
        }

        $stage = app(VesselDesignService::class)->verifiedSource($projectId, $designSource);

        if ($stage === null) {
            return [null, 'storyboard_design_source_invalid'];
        }

        if (! is_int($designSource['revision'] ?? null) || (int) $stage->planning_revision !== $designSource['revision']) {
            return [null, 'storyboard_design_revision_mismatch'];
        }

        $design = (array) $stage->output_json;

        $clip = (array) config('video.scene_plan.shot_clip');

        return [[
            'mode' => ScenePlanAuthor::DESIGN_FIRST_SOURCE,
            'subject' => ['design' => VesselDesign::storyboardIdentity($design)],
            'claim' => [
                'design_stage_id' => (string) $stage->id,
                'design_revision' => (int) $stage->planning_revision,
                'design_content_hash' => $designSource['content_hash'],
                'film' => $film,
                'shot_clip' => $clip,
            ],
            'design' => $design,
            'clip_durations_ms' => [(int) ($clip['duration_ms'] ?? 0)],
            'film' => $film,
        ], 'ok'];
    }

    /**
     * @return array{0: ?array{mode: string, subject: array<string, mixed>, claim: array<string, mixed>, design: null}, 1: string}
     */
    private function legacyStoryboardSource(VideoDesignImage $anchor, SceneProfile $profile): array
    {
        $summary = $this->identitySummary((string) ($anchor->prompt_spec_json['prompt'] ?? ''), $profile->subjectClass);

        if ($summary === null) {
            return [null, 'identity_summary_unreadable'];
        }

        return [[
            'mode' => ScenePlanAuthor::LEGACY_SOURCE,
            'subject' => ['identity' => array_intersect_key($summary, array_flip(['subject_class', 'identity', 'proportion']))],
            'claim' => [
                'identity_summary_hash' => $this->digest($summary),
                'anchor_identity_sha256' => (string) $anchor->prompt_sha256,
            ],
            'design' => null,
            'clip_durations_ms' => $this->clipDurationsMs(),
            'film' => null,
        ], 'ok'];
    }

    /**
     * @param  array<string, mixed>  $input  the claim input of a design-first storyboard
     * @return string|null why the production screenplay or its design no longer match the input; null when they still do
     */
    private function storyboardSourceChange(string $projectId, array $input): ?string
    {
        $project = VideoProject::query()->find($projectId);

        if ($project === null || (string) $project->selected_screenplay_stage_id !== (string) ($input['screenplay_stage_id'] ?? '')) {
            return 'screenplay_selection_changed';
        }

        $screenplay = VideoPlanningStage::query()->whereKey((string) $input['screenplay_stage_id'])->first();
        $output = is_array($screenplay?->output_json) ? $screenplay->output_json : [];

        if ($screenplay === null || ! hash_equals(
            (string) ($input['screenplay_hash'] ?? ''),
            \App\Video\Screenplay\ScreenplayContentHash::of($this->productionScreenplayContent($output)),
        )) {
            return 'screenplay_changed';
        }

        $source = $output[VesselDesign::SOURCE_DESIGN_KEY] ?? null;

        if (! is_array($source)
            || (string) ($source['stage_id'] ?? '') !== (string) ($input['design_stage_id'] ?? '')
            || ! hash_equals((string) ($input['design_content_hash'] ?? ''), (string) ($source['content_hash'] ?? ''))
            || app(VesselDesignService::class)->verifiedSource($projectId, $source) === null) {
            return 'design_changed';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $packet
     * @param  array<string, mixed>  $claimInput
     * @param  callable(ScenePlanResult): array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<string>, 3: array<string, mixed>}  $build
     * @return array{0: ?int, 1: string}
     */
    private function authorScenePlan(
        string $projectId,
        ScenePlanAuthor|FilmCutAuthor $author,
        array $packet,
        array $claimInput,
        bool $force,
        callable $build,
        ?ScenePlanResult $stored = null,
    ): array {
        $stageName = PlanningStageName::SCENE_PLAN;
        $provider = (string) $claimInput['provider'];
        $claimMeta = ['pricing' => $stored === null ? 'unpriced' : 'free'];

        [$claimed, $token, $reason] = $this->stageStore->claimProjectStage(
            $projectId, $stageName, $claimInput, $force, $claimMeta,
        );

        if ($token === null) {
            return [null, $reason === 'already_succeeded' ? 'storyboard_unchanged' : 'storyboard_running'];
        }

        $extra = [];
        $raw = '';
        $totals = [];
        $authorUsage = [];
        $authorModel = null;
        $shots = [];
        $warnings = [];

        try {
            $authorAttempted = false;

            try {
                $result = $stored ?? $author->plan($packet, static function () use (&$authorAttempted): void {
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

            [$shots, $rows, $warnings, $extra] = $build($result);

            $usageColumn = $this->usageColumn($totals, $provider, $author->promptVersion(), $authorModel);
            $committed = $this->commitScenePlan(
                $projectId, $rows, $claimed->id, $token, $raw, $usageColumn,
                fn (int $revision, string $planHash): array => $this->storyboardOutput(
                    $shots, $warnings, $authorUsage, $totals, $raw, $revision, $planHash, $extra,
                ),
            );

            if ($committed === null) {
                return $this->orphanScenePlan(
                    $projectId, $claimInput, $claimMeta, $claimed->id, $token,
                    $shots, $warnings, $authorUsage, $usageColumn, $totals, $raw, $stageName, $extra,
                );
            }
        } catch (\Throwable $e) {
            if ($e instanceof ScenePlanException && $raw === '') {
                $raw = $e->raw;
            }

            $usageColumn = $this->usageColumn($totals, $provider, $author->promptVersion(), $authorModel);
            $output = $this->safeOutput(fn (): array => $this->storyboardOutput(
                $shots, $warnings, $authorUsage, $totals, $raw, null, null, $extra,
            ));
            $wrote = null;
            $orphaned = null;

            try {
                $wrote = $this->stageStore->finishFailed(
                    $claimed->id, $token, $e->getMessage(), $usageColumn, $raw, $output,
                );
            } catch (\Throwable $ledgerError) {
                $this->quietLog('storyboard: finishFailed threw', $ledgerError);
            }

            if ($wrote !== true) {
                try {
                    $orphaned = $this->stageStore->recordOrphanAttempt(
                        $projectId, $stageName,
                        $claimInput, $claimMeta,
                        $claimed->id, $token,
                        $e->getMessage(), $usageColumn, $raw, $output,
                    );
                } catch (\Throwable $orphanError) {
                    $this->quietLog('storyboard: recordOrphanAttempt threw', $orphanError);
                }
            }

            $this->quietLog('storyboard: failed', $e, [
                'project_id' => $projectId,
                'claim_kept' => $wrote,
            ]);

            if ($wrote !== true && $orphaned === null) {
                return [null, 'storyboard_failed_unrecorded'];
            }

            return [null, str_starts_with($e->getMessage(), self::SOURCE_CHANGED) ? 'storyboard_source_changed' : 'storyboard_failed'];
        }

        Log::info('storyboard: written', [
            'project_id' => $projectId,
            'revision' => $committed,
            'shots' => count($shots),
            'warnings' => count($warnings),
            'calls' => $totals['calls'] ?? 0,
        ]);

        return [count($shots), $stored === null ? 'storyboard_draft' : 'storyboard_rechecked'];
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @param  array<string, mixed>  $source  the verified design source of the screenplay
     * @return array{0: ?int, 1: string}
     */
    private function generateFilmCut(
        VideoProject $project,
        VideoPlanningStage $screenplayStage,
        array $screenplay,
        string $screenplayHash,
        array $source,
        SceneProfile $profile,
        bool $force,
    ): array {
        $projectId = (string) $project->id;

        if (! in_array($screenplay['schema_version'] ?? null, \App\Video\Screenplay\ScreenplayValidator::FILM_CONTRACTS, true)) {
            return [null, 'film_cut_screenplay_outdated'];
        }

        $inputs = $this->filmCutInputs($project, $screenplayStage);

        if (is_string($inputs)) {
            return [null, $inputs];
        }

        try {
            $plan = FilmCutPlan::fromScreenplay($screenplay, $inputs['preset'], $inputs['context']['stages'], $inputs['context']['fitted_from']);
        } catch (ScenePlanException $e) {
            return [null, 'film_cut_screenplay_invalid|'.$e->getMessage()];
        }

        $claimInput = [
            'source_mode' => $source['mode'],
            'screenplay_stage_id' => $screenplayStage->id,
            'screenplay_revision' => $screenplayStage->planning_revision,
            'screenplay_contract_version' => $screenplay['schema_version'],
            'screenplay_hash' => $screenplayHash,
        ] + $source['claim'] + [
            'profile_version' => $profile->version,
            'profile_sha256' => $profile->sha256,
            'scene_contract_version' => FilmCutAuthor::CONTRACT_VERSION,
            'planner' => FilmCutAuthor::PLANNER,
            'preservation_version' => ScenePreservationPrompt::VERSION,
            'film_cut' => [
                'preset' => $inputs['preset'],
                'sources' => $inputs['frozen'],
                'compile_version' => FilmSegmentPrompt::VERSION,
            ],
        ];

        [$claimed, $token, $reason] = $this->stageStore->claimProjectStage(
            $projectId, PlanningStageName::SCENE_PLAN, $claimInput, $force, ['pricing' => 'free'],
        );

        if ($token === null) {
            return [null, $reason === 'already_succeeded' ? 'storyboard_unchanged' : 'storyboard_running'];
        }

        $usage = $this->usageColumn([], FilmCutAuthor::PLANNER, FilmCutAuthor::CONTRACT_VERSION, null);

        try {
            $rows = $this->filmCutRows($plan, $screenplayStage, $screenplayHash, FilmCutAuthor::CONTRACT_VERSION);
            $committed = $this->commitScenePlan(
                $projectId, $rows, $claimed->id, $token, '', $usage,
                fn (int $revision, string $planHash): array => $this->storyboardOutput(
                    $plan['sub_shots'], [], [], [], '', $revision, $planHash, ['film_cut' => $plan],
                ),
            );
        } catch (\Throwable $e) {
            $this->stageStore->finishFailed($claimed->id, $token, $e->getMessage(), $usage, '', ['film_cut' => $plan]);
            $this->quietLog('film cut: failed', $e, ['project_id' => $projectId]);

            return [null, 'storyboard_failed'];
        }

        if ($committed === null) {
            return [null, 'storyboard_failed_unrecorded'];
        }

        return [count($plan['sub_shots']), 'storyboard_draft'];
    }

    /**
     * @return array<string, mixed>|string the inputs, or the reason the film cut cannot be planned
     */
    private function filmCutInputs(VideoProject $project, VideoPlanningStage $screenplayStage): array|string
    {
        $projectId = (string) $project->id;
        $preset = (array) config('video.film_cut.preset');

        if ($preset === []) {
            return 'film_cut_misconfigured';
        }

        try {
            $entry = app(\App\Video\Media\VideoModelRegistry::class)->defaultFor((string) $preset['model_task']);
        } catch (InvalidArgumentException $e) {
            $this->quietLog('film cut: no segment model', $e, ['project_id' => $projectId]);

            return 'film_cut_misconfigured';
        }

        $designSource = $screenplayStage->output_json[VesselDesign::SOURCE_DESIGN_KEY] ?? null;
        $design = is_array($designSource) ? app(VesselDesignService::class)->verifiedSource($projectId, $designSource) : null;

        if ($design === null) {
            return 'film_cut_design_missing';
        }

        $context = $this->environmentContext($projectId, $screenplayStage->output_json) ?? ['stages' => [], 'fitted_from' => null];

        return [
            'preset' => $preset,
            'entry' => $entry,
            'context' => $context,
            'frozen' => [
                'screenplay' => ['stage_id' => (string) $screenplayStage->id],
                'design' => ['stage_id' => (string) ($designSource['stage_id'] ?? ''), 'content_hash' => (string) ($designSource['content_hash'] ?? '')],
                'identity' => null,
                'views' => [],
                'preset_sha256' => $this->digest(['preset' => $preset]),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $frozen  the film_cut block a film plan recorded
     * @return array<string, mixed>|null the identity image the segments send: the one the plan froze, else the approved production anchor now
     */
    private function filmIdentity(string $projectId, array $frozen): ?array
    {
        if (is_array($frozen['sources']['identity'] ?? null)) {
            return $frozen['sources']['identity'];
        }

        $anchor = $this->productionAnchor($projectId);
        $artifact = $anchor?->selected_artifact_id === null ? null : VideoArtifact::query()->whereKey($anchor->selected_artifact_id)->first();

        if ($anchor === null || $artifact === null || $anchor->status !== DesignImageStatus::APPROVED->value) {
            return null;
        }

        return [
            'role' => FilmSegmentPrompt::ROLE_IDENTITY,
            'label' => 'Ảnh neo thiết kế',
            'candidate_id' => (string) $anchor->id,
            'artifact_id' => (string) $artifact->id,
            'original_sha256' => (string) $artifact->sha256,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @return list<array<string, mixed>>
     */
    private function filmCutRows(array $plan, VideoPlanningStage $screenplayStage, string $screenplayHash, string $promptVersion): array
    {
        $shots = [];
        $previous = null;
        $seconds = static fn (int $ms): string => rtrim(rtrim(number_format($ms / 1000, 2), '0'), '.');

        foreach ($plan['sub_shots'] as $sub) {
            $code = sprintf('film_s%02d', $sub['order']);
            $moment = ['progress' => $sub['frame']['progress'], 'configuration' => $sub['frame']['configuration']];
            $room = $sub['frame']['room_state'];
            $shot = [
                'scene_code' => $code,
                'screenplay_scene_code' => $sub['scene_id'],
                'shot_index' => $sub['order'],
                'location_id' => $sub['location_id'],
                'character_ids' => $sub['source']['character_ids'],
                'title' => sprintf('Đoạn %d · %s · %s–%s s', $sub['segment'], $sub['scene_id'], $seconds($sub['start_ms']), $seconds($sub['end_ms'])),
                'purpose' => $sub['purpose'],
                'coverage_ids' => [],
                'basis' => 'source_supported',
                'state_before' => $previous === null ? 'film_start' : $previous['video']['end_state'],
                'scene_state' => $code.'_keyframe',
                'transition_mode' => ScenePreservationPrompt::HARD_CUT,
                'continuity_group' => $code,
                'source_scene_code' => '',
                'camera' => $sub['camera'],
                'delta' => $sub['keyframe'],
                'video' => [
                    'action' => $sub['source']['action'],
                    'preserve' => self::STORYBOARD_PRESERVE,
                    'end_state' => $code.'_end',
                    'camera_relation' => ScenePlanAuthor::NEW_CAMERA,
                    'duration_ms' => $sub['end_ms'] - $sub['start_ms'],
                ],
                'beat_ids' => $sub['beat_ids'],
                'beat_coverage' => array_map(static fn (string $beatId): array => ['beat_id' => $beatId, 'part' => 'whole'], $sub['beat_ids']),
                'keyframe_state' => $moment,
                'end_state' => $moment,
                'objects_start' => [],
                'objects_end' => [],
                'objects_first_frame' => [],
                'objects_last_frame' => [],
                'reference_requirements' => [],
            ] + ($room === null ? [] : [
                \App\Video\Screenplay\SceneBeats::ROOM_STATE_KEY => ['start' => $room, 'end' => $room],
            ]);
            $shots[] = $shot;
            $previous = $shot;
        }

        $rows = $this->storyboardRows($shots, $screenplayStage, $screenplayHash, $promptVersion);

        foreach (array_keys($rows) as $index) {
            $rows[$index]['state_json'] += ['film_cut_shape' => FilmCutAuthor::SHAPE, 'film' => $plan['sub_shots'][$index]];
        }

        return $rows;
    }

    private function artifactFileExists(string $artifactId): bool
    {
        $artifact = VideoArtifact::query()->find($artifactId);

        return $artifact !== null
            && (string) $artifact->storage_path !== ''
            && Storage::disk((string) $artifact->storage_disk)->exists((string) $artifact->storage_path);
    }

    private function artifactFileSize(string $artifactId): int
    {
        $artifact = VideoArtifact::query()->find($artifactId);

        return $artifact === null || ! $this->artifactFileExists($artifactId)
            ? 0
            : (int) Storage::disk((string) $artifact->storage_disk)->size((string) $artifact->storage_path);
    }

    private function isLegacyFilmRow(VideoRenderScene $row): bool
    {
        $shape = $row->state_json['film_cut_shape'] ?? null;

        return FilmCutAuthor::isFilmShape($shape) && ($shape !== FilmCutAuthor::SHAPE || ! is_array($row->state_json['film'] ?? null));
    }

    /**
     * @return list<string>
     */
    private function filmCutDrift(VideoPlanningStage $stage): array
    {
        $frozen = is_array($stage->input_json['film_cut'] ?? null) ? $stage->input_json['film_cut'] : null;

        if ($frozen === null || ! is_array($frozen['sources'] ?? null)) {
            return ['film_cut_sources_unrecorded'];
        }

        $projectId = (string) $stage->project_id;
        $sources = $frozen['sources'];
        $drift = [];

        $planning = static fn (array $preset): array => array_diff_key($preset, array_flip(['audio']));

        if ($this->digest($planning((array) ($frozen['preset'] ?? []))) !== $this->digest($planning((array) config('video.film_cut.preset')))) {
            $drift[] = 'Cấu hình phim 30 giây đã đổi kể từ lúc lập.';
        }

        $screenplay = VideoPlanningStage::query()->whereKey((string) ($stage->input_json['screenplay_stage_id'] ?? ''))->first();

        if ($screenplay === null || ! hash_equals(
            (string) ($stage->input_json['screenplay_hash'] ?? ''),
            \App\Video\Screenplay\ScreenplayContentHash::of($this->productionScreenplayContent((array) $screenplay->output_json)),
        )) {
            $drift[] = 'Phân cảnh đã đổi hoặc không còn kể từ lúc lập.';
        }

        if (app(VesselDesignService::class)->verifiedSource($projectId, (array) ($sources['design'] ?? [])) === null) {
            $drift[] = 'Bản thiết kế đã đổi hoặc không còn.';
        }

        $entries = [];

        foreach ([is_array($sources['identity'] ?? null) ? $sources['identity'] : null] as $item) {
            if (is_array($item) && is_string($item['candidate_id'] ?? null)) {
                $entries[$item['candidate_id']] = $item;
            }
        }

        foreach ($entries as $candidateId => $item) {
            $image = VideoDesignImage::query()->whereKey($candidateId)->with('artifact')->first();

            if ($image === null
                || $image->status !== DesignImageStatus::APPROVED->value
                || (string) $image->selected_artifact_id !== (string) ($item['artifact_id'] ?? '')
                || ! hash_equals((string) ($item['original_sha256'] ?? ''), (string) ($image->artifact?->sha256 ?? ''))) {
                $drift[] = 'Ảnh tham chiếu "'.($item['label'] ?? $candidateId).'" đã đổi hoặc bỏ duyệt.';
            }
        }

        return $drift;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function filmCutView(string $projectId, int $revision): ?array
    {
        $stage = $revision < 1 ? null : $this->stageStore->stageForProjectRevision($projectId, PlanningStageName::SCENE_PLAN, $revision);

        if ($stage === null || ! FilmCutAuthor::isFilmCut($stage->input_json['scene_contract_version'] ?? null)) {
            return null;
        }

        $legacy = ($stage->input_json['scene_contract_version'] ?? null) !== FilmCutAuthor::CONTRACT_VERSION
            || VideoRenderScene::query()->where('project_id', $projectId)->where('revision', $revision)->get()
                ->contains(fn (VideoRenderScene $row): bool => $this->isLegacyFilmRow($row));
        $plan = is_array($stage->output_json['film_cut'] ?? null) ? $stage->output_json['film_cut'] : [];
        $segments = [];

        foreach ($legacy ? [] : FilmCutPlan::segments((array) ($plan['sub_shots'] ?? [])) as $index => $shots) {
            $segments[$index + 1] = array_map(static fn (array $shot): array => [
                'order' => (int) $shot['order'],
                'scene_id' => (string) $shot['scene_id'],
                'location_name' => (string) $shot['location_name'],
                'start_ms' => (int) $shot['start_ms'],
                'end_ms' => (int) $shot['end_ms'],
                'focus' => (string) $shot['focus'],
            ], $shots);
        }

        return [
            'revision' => $revision,
            'legacy' => $legacy,
            'segments' => $segments,
            'junctions' => $legacy ? [] : array_values((array) ($plan['junctions'] ?? [])),
            'preset' => (array) ($stage->input_json['film_cut']['preset'] ?? []),
            'drift' => $legacy ? [] : $this->filmCutDrift($stage),
        ];
    }

    /**
     * @return list<array{index: int, owner: VideoRenderScene, rows: list<VideoRenderScene>, title: string, prompt: string,
     *               references: list<array<string, mixed>>, keyframes: list<array{order: int, url: ?string}>,
     *               blocked: ?string, duration_seconds: int, shot_code: string}>
     */
    public function filmSegments(string $projectId, int $revision): array
    {
        $stage = $revision < 1 ? null : $this->stageStore->stageForProjectRevision($projectId, PlanningStageName::SCENE_PLAN, $revision);

        if ($stage === null || ! FilmCutAuthor::isFilmCut($stage->input_json['scene_contract_version'] ?? null)) {
            return [];
        }

        if (($stage->input_json['scene_contract_version'] ?? null) !== FilmCutAuthor::CONTRACT_VERSION) {
            return [];
        }

        $frozen = (array) ($stage->input_json['film_cut'] ?? []);
        $preset = (array) ($frozen['preset'] ?? []);
        $plan = (array) ($stage->output_json['film_cut'] ?? []);
        $design = app(VesselDesignService::class)->verifiedSource($projectId, (array) ($frozen['sources']['design'] ?? []));
        $designOutput = is_array($design?->output_json) ? $design->output_json : [];
        $finish = array_map(
            static fn (mixed $value): string => is_string($value) ? trim($value) : '',
            array_intersect_key((array) ($designOutput['canonical_design']['appearance_identity'] ?? []), array_flip(['hull', 'superstructure', 'glazing', 'materials'])),
        );
        $junctions = collect((array) ($plan['junctions'] ?? []))->keyBy('after_shot')->all();
        $limit = (int) (app(\App\Video\Media\VideoModelRegistry::class)->defaultFor((string) ($preset['model_task'] ?? SceneClipDispatchService::FILM_TASK))['controls']['max_reference_images'] ?? 10);
        $viewCount = max(0, (int) config('video.film_cut.segment_reference_views', 3));
        $identity = $this->filmIdentity($projectId, $frozen);
        $anchorArtifactId = (string) ($identity['artifact_id'] ?? '');
        $views = array_values(array_map(static fn (array $view): array => [
            'role' => FilmSegmentPrompt::ROLE_DESIGN_VIEW,
            'label' => $view['title'],
            'candidate_id' => $view['candidate_id'],
            'artifact_id' => $view['artifact_id'],
            'original_sha256' => $view['sha256'],
        ], array_filter(
            $this->approvedReferenceViews($projectId),
            fn (array $view): bool => $anchorArtifactId !== ''
                && ($view['anchor_artifact_id'] ?? null) === $anchorArtifactId
                && $this->artifactFileExists($view['artifact_id']),
        )));
        $groups = [];

        foreach (VideoRenderScene::query()->where('project_id', $projectId)->where('revision', $revision)->orderBy('scene_index')->get() as $row) {
            if ($this->isLegacyFilmRow($row)) {
                return [];
            }

            $groups[(int) ($row->state_json['film']['segment'] ?? 0)][] = $row;
        }

        ksort($groups);
        $segments = [];

        foreach ($groups as $index => $rows) {
            $shots = array_map(static fn (VideoRenderScene $row): array => (array) $row->state_json['film'], $rows);
            $orders = array_column($shots, 'order');
            $references = [];
            $keyframes = [];
            $plates = [];
            $missingPlates = [];
            $roomProblems = [];
            $blocked = null;

            foreach ($rows as $position => $row) {
                $room = $this->filmRoomPlate($projectId, $row);
                $use = $room['room'] ? $this->filmRoomKeyframe($row) : null;
                $image = VideoDesignImage::query()
                    ->where('render_scene_id', $row->id)
                    ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
                    ->where('status', DesignImageStatus::APPROVED->value)
                    ->whereNotNull('selected_artifact_id')
                    ->with('artifact')
                    ->first();

                if ($use !== null && $use['stale']) {
                    $roomProblems[] = strtoupper((string) ($shots[$position]['scene_id'] ?? '')).': '.__('messages.scene_keyframe_film_keyframe_stale');
                }

                if ($image?->artifact !== null && ($use === null || $use['chosen'])) {
                    $blocked ??= $this->clipSourcesNeedReview($row);
                    $keyframes[] = ['order' => $orders[$position], 'url' => route('video-artifacts.show', $image->artifact->id)];
                    $references[] = [
                        'role' => FilmSegmentPrompt::ROLE_KEYFRAME,
                        'label' => (string) ($shots[$position]['scene_id'] ?? ''),
                        'candidate_id' => (string) $image->id,
                        'artifact_id' => (string) $image->artifact->id,
                        'original_sha256' => (string) $image->artifact->sha256,
                        'used_by' => [$orders[$position]],
                    ];
                } else {
                    $keyframes[] = ['order' => $orders[$position], 'url' => null];
                }

                [$plate, $plateReason] = $room['room']
                    ? [$room['plate'], $room['why']]
                    : $this->approvedPlate($projectId, $row);

                if ($plate === null && $room['room']) {
                    [$code, $name] = array_pad(explode('|', $plateReason, 2), 2, '');
                    $roomProblems[] = strtoupper((string) ($shots[$position]['scene_id'] ?? '')).': '
                        .__('messages.scene_keyframe_'.$code, ['name' => $name]);
                } elseif ($plate !== null) {
                    $plates[$plate['artifact_id']] ??= [
                        'role' => FilmSegmentPrompt::ROLE_ENVIRONMENT,
                        'label' => (string) $plate['title'],
                        'candidate_id' => (string) $plate['candidate_id'],
                        'artifact_id' => (string) $plate['artifact_id'],
                        'original_sha256' => (string) $plate['sha256'],
                        'used_by' => [],
                    ];
                    $plates[$plate['artifact_id']]['used_by'][] = $orders[$position];
                } elseif ($plateReason !== self::NO_PLATE) {
                    $missingPlates[] = str_contains($plateReason, '|') ? substr($plateReason, (int) strpos($plateReason, '|') + 1) : $plateReason;
                }
            }

            if ($identity !== null) {
                $references[] = $identity + ['used_by' => $orders];
            }

            $references = [...$references, ...array_values($plates)];
            $framesAndPlaces = count($references);
            $viewRoom = max(0, $limit - $framesAndPlaces);
            $viewChoice = $rows[0]->state_json[self::FILM_VIEW_CHOICE_KEY] ?? null;
            $viewsById = array_column($views, null, 'artifact_id');
            $missingViews = [];

            if (is_array($viewChoice)) {
                foreach ($viewChoice as $artifactId) {
                    if (isset($viewsById[$artifactId])) {
                        $references[] = $viewsById[$artifactId] + ['used_by' => $orders];
                    } else {
                        $missingViews[] = (string) $artifactId;
                    }
                }

                $overLimit = count($references) > $limit;
            } else {
                $overLimit = count($references) > $limit;

                foreach (array_slice($views, 0, max(0, min($viewCount, $viewRoom))) as $view) {
                    $references[] = $view + ['used_by' => $orders];
                }
            }

            $unreadable = array_values(array_filter(
                $references,
                fn (array $item): bool => $item['role'] !== FilmSegmentPrompt::ROLE_DESIGN_VIEW && ! $this->artifactFileExists((string) $item['artifact_id']),
            ));
            $perImage = (int) config('video.omni.reference.max_bytes');
            $heaviest = array_sum(array_map(
                fn (array $item): int => min($perImage, $this->artifactFileSize((string) $item['artifact_id'])),
                $references,
            ));
            $ceiling = (int) config('video.omni.max_reference_bytes');

            $first = (int) $orders[0];
            $last = (int) $orders[count($orders) - 1];

            $segments[] = [
                'index' => $index,
                'owner' => $rows[0],
                'rows' => $rows,
                'title' => 'Đoạn '.$index.' · '.($shots[0]['scene_id'] ?? '').'–'.($shots[count($shots) - 1]['scene_id'] ?? ''),
                'prompt' => FilmSegmentPrompt::compile($shots, [
                    'name' => trim((string) ($designOutput['vessel']['name'] ?? '')),
                    'finish' => $finish,
                    'segment' => $index,
                    'segments' => count($groups),
                    'aspect_ratio' => (string) ($preset['aspect_ratio'] ?? ''),
                    'junction_in' => $junctions[$first - 1]['link'] ?? null,
                    'junction_out' => $junctions[$last]['link'] ?? null,
                ], $references),
                'references' => $references,
                'keyframes' => $keyframes,
                'views' => [
                    'auto' => ! is_array($viewChoice),
                    'stale' => $missingViews !== [],
                    'room' => $viewRoom,
                    'sent' => array_column(array_filter($references, static fn (array $item): bool => $item['role'] === FilmSegmentPrompt::ROLE_DESIGN_VIEW), 'artifact_id'),
                    'options' => array_map(static fn (array $view): array => [
                        'artifact_id' => (string) $view['artifact_id'],
                        'label' => (string) $view['label'],
                        'url' => route('video-artifacts.show', (string) $view['artifact_id']),
                    ], $views),
                ],
                'blocked' => match (true) {
                    $design === null => 'bản thiết kế đã đổi — lập lại phim',
                    $identity === null => 'chưa có ảnh neo đã duyệt của bản thiết kế này — duyệt ảnh neo trước khi render đoạn',
                    $missingPlates !== [] => 'cần duyệt ảnh nền: '.implode(', ', array_unique($missingPlates)).' trong Environment Library trước',
                    $roomProblems !== [] => 'ảnh địa điểm: '.implode('; ', $roomProblems),
                    $missingViews !== [] => 'góc thiết kế đã chọn không còn là ảnh duyệt của ảnh neo hiện tại — chọn lại ở mục Góc thiết kế gửi kèm',
                    $overLimit && $framesAndPlaces <= $limit => 'đoạn này cần '.count($references).' ảnh tham chiếu, Omni nhận tối đa '.$limit.' — bỏ bớt góc thiết kế đã chọn',
                    $overLimit => 'đoạn này cần '.count($references).' ảnh tham chiếu, Omni nhận tối đa '.$limit.' — giảm ảnh khung hoặc số địa điểm của đoạn',
                    $unreadable !== [] => 'không còn file ảnh: '.implode(', ', array_column($unreadable, 'label')).' — render và duyệt lại ảnh đó',
                    $heaviest > $ceiling => sprintf('ảnh gửi đi có thể tới %.1f MB, vượt giới hạn %.1f MB của một request Omni', $heaviest / 1048576, $ceiling / 1048576),
                    default => $blocked,
                },
                'duration_seconds' => intdiv((int) ($preset['segment_ms'] ?? 0), 1000),
                'shot_code' => self::FILM_SEGMENT_CODE.$index,
            ];
        }

        return $segments;
    }

    /**
     * @return array{0: ?VideoRender, 1: string}
     */
    /**
     * @param  array<string, mixed>  $controls  model_id, resolution, aspect_ratio, duration_seconds chosen on the page
     * @return array{0: ?VideoRender, 1: string}
     */
    public function renderFilmSegment(
        string $projectId,
        ?string $actorId,
        string $ownerId,
        array $controls,
        int $expectedIntentVersion,
        string $operationId,
    ): array {
        $owner = $this->ownedScene($projectId, $actorId, $ownerId);

        if ($owner === null || ($owner->state_json['film_cut_shape'] ?? null) !== FilmCutAuthor::SHAPE) {
            return [null, 'scene_not_found'];
        }

        [$ok, $reason] = $this->sceneRenderGate($owner);

        if (! $ok) {
            return [null, $reason];
        }

        $segment = collect($this->filmSegments($projectId, (int) $owner->revision))
            ->first(static fn (array $item): bool => (string) $item['owner']->id === (string) $owner->id);

        if ($segment === null) {
            return [null, 'film_segment_not_owner'];
        }

        if ($segment['blocked'] !== null) {
            return [null, 'film_segment_blocked|'.$segment['blocked']];
        }

        try {
            $plan = $this->filmSegmentPlan($segment);
            $shot = app(\App\Video\Render\Video\SceneShotFactory::class)->forScene($owner, $plan);
            $render = app(SceneClipDispatchService::class)->createFilmSegment(
                $shot, $owner, $plan, $segment['references'], $controls, $expectedIntentVersion, $operationId,
            );
        } catch (RuntimeException $e) {
            return [null, 'film_segment_blocked|'.$e->getMessage()];
        }

        return [$render, 'ok'];
    }

    /**
     * @param  list<mixed>|null  $artifactIds  null returns the segment to the automatic design views
     * @return array{0: bool, 1: string} [$done, $reason]
     */
    public function chooseFilmReferenceViews(string $projectId, ?string $actorId, string $ownerId, ?array $artifactIds): array
    {
        $owner = $this->ownedScene($projectId, $actorId, $ownerId);

        if ($owner === null || ($owner->state_json['film_cut_shape'] ?? null) !== FilmCutAuthor::SHAPE) {
            return [false, 'scene_not_found'];
        }

        $segment = collect($this->filmSegments($projectId, (int) $owner->revision))
            ->first(static fn (array $item): bool => (string) $item['owner']->id === (string) $owner->id);

        if ($segment === null) {
            return [false, 'film_segment_not_owner'];
        }

        $ids = null;

        if ($artifactIds !== null) {
            $ids = array_values(array_map(static fn (mixed $id): string => (string) $id, $artifactIds));
            $options = array_column($segment['views']['options'], 'artifact_id');

            if (count($ids) !== count(array_unique($ids)) || array_diff($ids, $options) !== []) {
                return [false, 'film_views_invalid'];
            }

            if (count($ids) > $segment['views']['room']) {
                return [false, 'film_views_over_limit|'.$segment['views']['room']];
            }

            $ids = array_values(array_intersect($options, $ids));
        }

        DB::update(
            $ids === null
                ? "UPDATE video_render_scenes SET state_json = JSON_REMOVE(state_json, '$.".self::FILM_VIEW_CHOICE_KEY."'), updated_at = ? WHERE id = ?"
                : "UPDATE video_render_scenes SET state_json = JSON_SET(state_json, '$.".self::FILM_VIEW_CHOICE_KEY."', JSON_EXTRACT(?, '$')), updated_at = ? WHERE id = ?",
            $ids === null
                ? [now(), $owner->id]
                : [json_encode($ids, JSON_THROW_ON_ERROR), now(), $owner->id],
        );

        return [true, $ids === null ? 'film_views_auto' : 'film_views_saved'];
    }

    /**
     * @param  array<string, mixed>  $segment
     * @return array<string, mixed>
     */
    private function filmSegmentPlan(array $segment): array
    {
        return [
            'video_prompt' => $segment['prompt'],
            'shot_code' => $segment['shot_code'],
            'title' => $segment['title'],
            'purpose' => implode(' · ', array_map(static fn (VideoRenderScene $row): string => (string) ($row->state_json['film']['focus'] ?? ''), $segment['rows'])),
            'phase' => 'film_segment',
            'duration_seconds' => $segment['duration_seconds'],
            'scene_state' => $segment['shot_code'].'_end',
            'state_before' => $segment['shot_code'].'_start',
        ];
    }

    /**
     * @param  array<string, mixed>  $request  the render's frozen request
     * @return string|null the reason the render no longer matches its plan, or null when it still does
     */
    public function filmSegmentNeedsReview(VideoRenderScene $row, array $request): ?string
    {
        $stage = $this->stageStore->stageForProjectRevision((string) $row->project_id, PlanningStageName::SCENE_PLAN, (int) $row->revision);

        if ($stage === null) {
            return 'scene_plan_unverifiable';
        }

        if ($this->filmCutDrift($stage) !== []) {
            return 'film_cut_sources_changed';
        }

        $segment = collect($this->filmSegments((string) $row->project_id, (int) $row->revision))
            ->first(static fn (array $item): bool => (string) $item['owner']->id === (string) $row->id);

        if ($segment === null) {
            return 'segment_row_missing';
        }

        if ($segment['blocked'] !== null) {
            return 'keyframe_sources_changed';
        }

        $sent = array_column((array) ($request['references'] ?? []), 'artifact_id');

        if ($sent !== array_column($segment['references'], 'artifact_id')) {
            return 'keyframe_changed';
        }

        return ($request['compiled_prompt']['prompt'] ?? null) === $segment['prompt'] ? null : 'compiled_prompt_changed';
    }

    /**
     * @return array{revision: int, shot_board: bool, film_cut: bool, approved: bool, selected_revision: int, contract_version: ?string, old_rules: bool}
     */
    public function storyboardApproval(string $projectId, int $revision): array
    {
        $project = VideoProject::query()->whereKey($projectId)->first();
        $selected = $project?->selectedScenePlanStage()->first();
        $selectedRevision = is_array($selected?->output_json) ? (int) ($selected->output_json['revision'] ?? 0) : 0;
        $stage = $revision < 1 ? null : $this->stageStore->stageForProjectRevision($projectId, PlanningStageName::SCENE_PLAN, $revision);
        $contract = $stage?->input_json['scene_contract_version'] ?? null;
        $shotBoard = $stage !== null && in_array($contract, ScenePlanAuthor::STORYBOARD_CONTRACT_VERSIONS, true);

        return [
            'revision' => $revision,
            'contract_version' => is_string($contract) ? $contract : null,
            'old_rules' => $shotBoard && ($contract !== ScenePlanAuthor::STORYBOARD_CONTRACT_VERSION
                || ($stage->input_json['prompt_version'] ?? null) !== (string) config('video.scene_plan.prompt_version')),
            'shot_board' => $shotBoard,
            'film_cut' => $stage !== null
                && FilmCutAuthor::isFilmCut($stage->input_json['scene_contract_version'] ?? null),
            'approved' => $stage !== null && (string) $project?->selected_scene_plan_stage_id === (string) $stage->id,
            'selected_revision' => $selectedRevision,
        ];
    }

    /**
     * @return list<array<string, mixed>> the findings of a storyboard revision with their status and whether they still block approval
     */
    public function storyboardFindings(string $projectId, int $revision): array
    {
        $stage = $revision < 1 ? null : $this->stageStore->stageForProjectRevision($projectId, PlanningStageName::SCENE_PLAN, $revision);

        return $stage === null || ! is_array($stage->output_json)
            ? []
            : array_map(
                fn (array $finding): array => $finding + ['unblocked' => $this->findingUnblocked($finding)],
                StoryboardPlanCheck::withStatus($stage->output_json['findings'] ?? [], $stage->output_json['finding_decisions'] ?? []),
            );
    }

    /** @param array<string, mixed> $finding */
    private function findingUnblocked(array $finding): bool
    {
        return in_array($finding['rule'] ?? null, (array) config('video.scene_plan.unblocked_findings', []), true);
    }

    /**
     * @return array{0: bool, 1: string}
     */
    public function acceptStoryboardFinding(string $projectId, ?string $actorId, int $revision, string $findingId, string $reason): array
    {
        $actor = $actorId === null ? null : Admin::find($actorId);
        $project = $actor === null ? null : $this->videoProjectRepository->getById($projectId);

        if ($project === null || Gate::forUser($actor)->denies('update', $project)) {
            return [false, 'project_not_found'];
        }

        $reason = trim($reason);

        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
            return [false, 'storyboard_finding_reason_required'];
        }

        return DB::transaction(function () use ($projectId, $actorId, $revision, $findingId, $reason): array {
            $stage = $revision < 1 ? null : $this->stageStore->stageForProjectRevision($projectId, PlanningStageName::SCENE_PLAN, $revision);
            $stage = $stage === null ? null : VideoPlanningStage::query()->whereKey($stage->id)->lockForUpdate()->first();
            $output = is_array($stage?->output_json) ? $stage->output_json : [];
            $finding = collect(StoryboardPlanCheck::withStatus($output['findings'] ?? [], []))->firstWhere('id', $findingId);

            if ($stage === null || $finding === null) {
                return [false, 'storyboard_finding_not_found'];
            }

            if ($finding['severity'] !== StoryboardPlanCheck::WARNING) {
                return [false, 'storyboard_finding_not_acceptable'];
            }

            if ((string) VideoProject::query()->whereKey($projectId)->value('selected_scene_plan_stage_id') === (string) $stage->id) {
                return [false, 'storyboard_finding_after_approval'];
            }

            $decisions = is_array($output['finding_decisions'] ?? null) ? $output['finding_decisions'] : [];
            $decisions[$findingId] = [
                'status' => StoryboardPlanCheck::ACCEPTED,
                'reason' => $reason,
                'by' => $actorId,
                'at' => now()->toIso8601String(),
            ];
            $stage->forceFill(['output_json' => ['finding_decisions' => $decisions] + $output])->save();

            return [true, 'ok'];
        });
    }

    /**
     * @return array{0: ?int, 1: string}
     */
    public function approveStoryboard(string $projectId, ?string $actorId, int $revision): array
    {
        $actor = $actorId === null ? null : Admin::find($actorId);

        if ($actor === null) {
            return [null, 'project_not_found'];
        }

        $project = $this->videoProjectRepository->getById($projectId);

        if ($project === null || Gate::forUser($actor)->denies('update', $project)) {
            return [null, 'project_not_found'];
        }

        return DB::transaction(function () use ($projectId, $actorId, $revision): array {
            $project = VideoProject::query()->whereKey($projectId)->lockForUpdate()->first();

            if ($project === null) {
                return [null, 'project_not_found'];
            }

            $latest = (int) VideoRenderScene::query()->where('project_id', $projectId)->max('revision');

            if ($revision < 1 || $revision !== $latest) {
                return [null, 'storyboard_not_latest'];
            }

            $stage = $this->stageStore->stageForProjectRevision($projectId, PlanningStageName::SCENE_PLAN, $revision);

            if ($stage === null
                || ! in_array($stage->input_json['scene_contract_version'] ?? null, ScenePlanAuthor::VALIDATED_CONTRACT_VERSIONS, true)) {
                return [null, 'scene_contract_unsupported'];
            }

            if ((string) $project->selected_scene_plan_stage_id === (string) $stage->id) {
                return [$revision, 'storyboard_already_approved'];
            }

            $review = $this->reviewForRevision($stage);
            $rows = VideoRenderScene::query()
                ->where('project_id', $projectId)
                ->where('revision', $revision)
                ->orderBy('scene_index')
                ->get();

            if ($review['status'] !== 'passed' || $rows->isEmpty()) {
                return [null, 'scene_plan_not_reviewed'];
            }

            if ($rows->contains(static fn (VideoRenderScene $row): bool => (string) $row->screenplay_stage_id
                !== (string) $project->selected_screenplay_stage_id)) {
                return [null, 'screenplay_selection_changed'];
            }

            if (($stage->input_json['source_mode'] ?? null) === ScenePlanAuthor::DESIGN_FIRST_SOURCE
                && in_array($stage->input_json['scene_contract_version'] ?? null, ScenePlanAuthor::SHOT_CONTRACT_VERSIONS, true)
                && $this->storyboardSourceChange($projectId, $stage->input_json) !== null) {
                return [null, 'storyboard_source_changed'];
            }

            $findings = StoryboardPlanCheck::withStatus($stage->output_json['findings'] ?? [], $stage->output_json['finding_decisions'] ?? []);
            $unblocked = array_values(array_filter($findings, fn (array $finding): bool => $finding['status'] === StoryboardPlanCheck::OPEN
                && $this->findingUnblocked($finding)));
            $findings = array_values(array_filter($findings, fn (array $finding): bool => ! $this->findingUnblocked($finding)));

            if (array_filter($findings, static fn (array $finding): bool => $finding['severity'] === StoryboardPlanCheck::ERROR) !== []) {
                return [null, 'storyboard_findings_blocking'];
            }

            if (array_filter($findings, static fn (array $finding): bool => $finding['status'] === StoryboardPlanCheck::OPEN) !== []) {
                return [null, 'storyboard_findings_open'];
            }

            $live = $rows->map(fn (VideoRenderScene $row): array => $this->planShapeOf($row))->all();

            if (! hash_equals((string) $review['reviewed_plan_sha256'], $this->planHash($live))) {
                return [null, 'scene_plan_changed_since_review'];
            }

            if (FilmCutAuthor::isFilmCut($stage->input_json['scene_contract_version'] ?? null)
                && (($stage->input_json['scene_contract_version'] ?? null) !== FilmCutAuthor::CONTRACT_VERSION
                    || $rows->contains(fn (VideoRenderScene $row): bool => $this->isLegacyFilmRow($row)))) {
                return [null, 'film_cut_legacy_rows'];
            }

            if (($stage->input_json['scene_contract_version'] ?? null) === FilmCutAuthor::CONTRACT_VERSION
                && $this->filmCutDrift($stage) !== []) {
                return [null, 'film_cut_sources_changed'];
            }

            $metadata = is_array($project->metadata_json) ? $project->metadata_json : [];
            $history = is_array($metadata['production_selection_history'] ?? null)
                ? $metadata['production_selection_history']
                : [];
            $nextSelectionVersion = (int) $project->production_selection_version + 1;
            $history[] = [
                'version' => $nextSelectionVersion,
                'screenplay_stage_id' => $project->selected_screenplay_stage_id,
                'scene_plan_stage_id' => $stage->id,
                'scene_plan_revision' => $revision,
                'plan_sha256' => $review['reviewed_plan_sha256'],
                'approved_by' => $actorId,
                'selected_at' => now()->toIso8601String(),
            ] + ($unblocked === [] ? [] : [
                'unblocked_findings' => array_map(static fn (array $finding): array => array_intersect_key($finding, array_flip(['id', 'rule', 'scene_id', 'shot_code'])), $unblocked),
            ]);
            $metadata['production_selection_history'] = $history;

            $project->forceFill([
                'selected_scene_plan_stage_id' => $stage->id,
                'production_selection_version' => $nextSelectionVersion,
                'metadata_json' => $metadata,
            ])->save();

            return [$revision, 'ok'];
        });
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

    /** @return array{status: string, error: ?string, at: ?string}|null */
    public function storyboardRun(string $projectId): ?array
    {
        $latest = VideoPlanningStage::query()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::SCENE_PLAN->value)
            ->orderByDesc('updated_at')
            ->first(['status', 'error_message', 'lease_expires_at', 'updated_at']);

        if ($latest === null) {
            return null;
        }

        $status = (string) $latest->status;

        if ($status === VideoPlanningStageStatus::RUNNING->value && ! ($latest->lease_expires_at?->isFuture() ?? false)) {
            $status = 'interrupted';
        }

        return [
            'status' => $status,
            'error' => $status === VideoPlanningStageStatus::FAILED->value ? (string) $latest->error_message : null,
            'at' => $latest->updated_at?->format('d/m H:i'),
        ];
    }

    /**
     * @return array{revision: int, shots: int, approved: bool, selected_revision: int, old_rules: bool, run: ?array<string, mixed>, scenes: int,
     *               model: string, effort: string, max_tokens: int, timeout_minutes: int}
     */
    public function storyboardPanel(string $projectId): array
    {
        $revision = (int) VideoRenderScene::query()->where('project_id', $projectId)->max('revision');
        $approval = $this->storyboardApproval($projectId, $revision);

        return [
            'revision' => $revision,
            'shots' => $revision === 0 ? 0 : VideoRenderScene::query()->where('project_id', $projectId)->where('revision', $revision)->count(),
            'approved' => $approval['approved'],
            'selected_revision' => $approval['selected_revision'],
            'old_rules' => $approval['old_rules'],
            'run' => $this->storyboardRun($projectId),
            'scenes' => count($this->storyboardScenes($projectId)['scenes']),
            'model' => app(ScenePlanAuthor::class)->model(),
            'effort' => (string) config('video.scene_plan.effort'),
            'max_tokens' => (int) config('video.scene_plan.max_tokens'),
            'timeout_minutes' => intdiv((int) config('video.scene_plan.client.timeout_seconds'), 60),
        ];
    }

    /** @return array{scenes: list<array{id: string, location: string}>} */
    public function storyboardScenes(string $projectId): array
    {
        $project = VideoProject::query()->find($projectId);
        $stage = $project === null ? null : $this->selectedProductionScreenplay($project);
        $output = is_array($stage?->output_json) ? $stage->output_json : [];
        $locationNames = [];

        foreach ((array) ($output['locations'] ?? []) as $location) {
            if (is_array($location) && is_string($location['id'] ?? null) && is_string($location['name'] ?? null)) {
                $locationNames[$location['id']] = $location['name'];
            }
        }

        $scenes = [];

        foreach ((array) ($output['scenes'] ?? []) as $scene) {
            if (! is_array($scene) || ! is_string($scene['id'] ?? null)) {
                continue;
            }

            $locationId = (string) ($scene['location_id'] ?? '');
            $scenes[] = ['id' => $scene['id'], 'location' => $locationNames[$locationId] ?? $locationId];
        }

        return ['scenes' => $scenes];
    }

    /**
     * Production readers must follow the explicit selection, never the newest draft.
     *
     * @return array{revision: int, scenes: list<array<string, mixed>>, warnings: list<string>,
     *               records: int, unpriced: bool}
     */
    public function selectedScenePlan(string $projectId): array
    {
        $ledger = $this->stageStore->attemptSummaryForProject($projectId, PlanningStageName::SCENE_PLAN);
        $project = VideoProject::query()->whereKey($projectId)->first();
        $stage = $project?->selectedScenePlanStage()
            ->where('project_id', $projectId)
            ->where('stage', PlanningStageName::SCENE_PLAN->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->first();
        $revision = is_array($stage?->output_json)
            ? (int) ($stage->output_json['revision'] ?? 0)
            : 0;

        if ($revision === 0 || $this->reviewForRevision($stage)['status'] !== 'passed') {
            return [
                'revision' => 0, 'scenes' => [], 'warnings' => [],
                'profile_notice' => null, 'preservation_notice' => null,
                'review' => $this->reviewForRevision($stage),
            ] + $ledger;
        }

        $rows = VideoRenderScene::query()
            ->where('project_id', $projectId)
            ->where('revision', $revision)
            ->where('screenplay_stage_id', $project?->selected_screenplay_stage_id)
            ->orderBy('scene_index')
            ->get();
        [$profile, $notice] = $this->profileForRevision($stage);
        [$preservation, $preservationNotice] = $this->preservationForRevision($stage);

        return [
            'revision' => $revision,
            'scenes' => $rows
                ->map(fn (VideoRenderScene $scene): array => $this->sceneView($scene, $profile, $preservation))
                ->all(),
            'warnings' => array_values((array) ($stage->output_json['warnings'] ?? [])),
            'profile_notice' => $notice,
            'preservation_notice' => $preservationNotice,
            'review' => $this->reviewForRevision($stage),
        ] + $ledger;
    }

    /**
     * @param  list<array<string, mixed>>  $rows  render scene attributes without project and revision
     * @param  array<string, mixed>  $usageColumn
     * @param  callable(int, string): array<string, mixed>  $output
     * @return int|null the draft revision; null when the claim was lost
     */
    private function commitScenePlan(
        string $projectId,
        array $rows,
        string $stageId,
        string $claimToken,
        string $raw,
        array $usageColumn,
        callable $output,
    ): ?int {
        $lost = false;

        try {
            return DB::transaction(function () use (
                $projectId, $rows, $stageId, $claimToken, $raw, $usageColumn, $output, &$lost
            ) {
                VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();

                $revision = ((int) VideoRenderScene::query()
                    ->where('project_id', $projectId)
                    ->max('revision')) + 1;

                foreach ($rows as $row) {
                    VideoRenderScene::create(['project_id' => $projectId, 'revision' => $revision] + $row);
                }

                $stored = VideoRenderScene::query()
                    ->where('project_id', $projectId)
                    ->where('revision', $revision)
                    ->orderBy('scene_index')
                    ->get()
                    ->map(fn (VideoRenderScene $row): array => $this->planShapeOf($row))
                    ->all();

                $recorded = $this->stageStore->finishSucceeded(
                    $stageId,
                    $claimToken,
                    $raw,
                    $output($revision, $this->planHash($stored)),
                    $usageColumn,
                );

                if (! $recorded) {
                    $lost = true;

                    throw new ScenePlanException(
                        'Storyboard claim was lost before the ledger could record it.'
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
     * @param  list<array<string, mixed>>  $scenes
     * @return list<array<string, mixed>>
     */
    private function storyboardRows(array $scenes, VideoPlanningStage $screenplayStage, string $screenplayHash, string $promptVersion): array
    {
        $screenplayScenes = collect((array) ($screenplayStage->output_json['scenes'] ?? []))
            ->filter(static fn ($scene) => is_array($scene) && is_string($scene['id'] ?? null))
            ->keyBy('id')
            ->all();
        $rows = [];

        foreach ($scenes as $index => $scene) {
            $source = $screenplayScenes[$scene['screenplay_scene_code']] ?? [];
            $room = $scene[\App\Video\Screenplay\SceneBeats::ROOM_STATE_KEY] ?? null;

            $rows[] = [
                'screenplay_stage_id' => $screenplayStage->id,
                'screenplay_scene_code' => $scene['screenplay_scene_code'],
                'screenplay_hash' => $screenplayHash,
                'scene_index' => $index + 1,
                'shot_index' => $scene['shot_index'],
                'scene_code' => $scene['scene_code'],
                'scene_type' => mb_substr((string) ($source['stage'] ?? ''), 0, 40),
                'milestone_keys' => null,
                'basis' => $scene['basis'],
                'title' => $scene['title'],
                'purpose' => $scene['purpose'],
                'state_json' => [
                    'state_before' => $scene['state_before'],
                    'scene_state' => $scene['scene_state'],
                    'location_id' => $scene['location_id'],
                    'character_ids' => $scene['character_ids'],
                    'coverage_ids' => $scene['coverage_ids'],
                ] + (array_key_exists('subject_state', $source) ? [
                    'scene_subject_state' => $source['subject_state'],
                    'beat_ids' => $scene['beat_ids'],
                    'keyframe_state' => $scene['keyframe_state'],
                    'end_state' => $scene['end_state'],
                ] : [
                    'build_state' => $source['build_state'] ?? null,
                ]) + (array_key_exists('beat_coverage', $scene) ? [
                    'storyboard_shape' => ScenePlanAuthor::SHOT_SHAPE,
                    'beat_coverage' => $scene['beat_coverage'],
                    'objects_start' => $scene['objects_start'],
                    'objects_end' => $scene['objects_end'],
                    'objects_first_frame' => $scene['objects_first_frame'],
                    'objects_last_frame' => $scene['objects_last_frame'],
                    'reference_requirements' => $scene['reference_requirements'],
                ] : []) + (array_key_exists('light_and_weather', $source) ? [
                    'setting' => array_key_exists('beat_coverage', $scene)
                        ? array_replace(\App\Video\Screenplay\LocationProfile::sceneSetting($source), ['props' => []])
                        : \App\Video\Screenplay\LocationProfile::sceneSetting($source),
                ] : []) + (($space = $this->spaceOfScene($screenplayStage, $scene['location_id'])) === null ? [] : [
                    'space' => $space,
                ]) + (! is_array($room) ? [] : [
                    \App\Video\Screenplay\SceneBeats::ROOM_STATE_KEY => $room,
                ]) + (! is_array($scene['timeline'] ?? null) ? [] : [
                    'timeline' => $scene['timeline'],
                ]) + (! is_array($scene[self::AFTER_JUMP_KEY] ?? null) ? [] : [
                    self::AFTER_JUMP_KEY => $scene[self::AFTER_JUMP_KEY],
                ]),
                'delta_prompt' => $scene['delta'],
                'prompt_version' => $promptVersion,
                'transition_mode' => $scene['transition_mode'],
                'continuity_group' => $scene['continuity_group'],
                'source_scene_code' => $scene['source_scene_code'] !== ''
                    ? $scene['source_scene_code']
                    : null,
                'camera_change_reason' => $scene['camera_change_reason'] ?? null,
                'video_plan_json' => $scene['video'] + [
                    'camera_mode' => ($scene['camera']['movement']['type'] ?? ScenePlanAuthor::STATIC_MOVEMENT) === ScenePlanAuthor::STATIC_MOVEMENT ? 'locked' : 'moving',
                    'camera' => $scene['camera'],
                ],
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $claimInput
     * @param  array<string, mixed>  $claimMeta
     * @param  list<array<string, mixed>>  $scenes
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $authorUsage
     * @param  array<string, mixed>  $usageColumn
     * @param  array<string, mixed>  $totals
     * @param  array<string, mixed>  $extra
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
        array $authorUsage,
        array $usageColumn,
        array $totals,
        string $raw,
        PlanningStageName $stageName = PlanningStageName::SCENE_PLAN,
        array $extra = [],
    ): array {
        $orphaned = null;

        try {
            $orphaned = $this->stageStore->recordOrphanAttempt(
                $projectId, $stageName, $claimInput, $claimMeta,
                $stageId, $claimToken, 'Storyboard claim was lost.',
                $usageColumn, $raw,
                $this->safeOutput(fn (): array => $this->storyboardOutput(
                    $scenes, $warnings, $authorUsage, $totals, $raw, null, null, $extra,
                )),
            );
        } catch (\Throwable $e) {
            $this->quietLog('storyboard: orphan write threw', $e, ['stage_id' => $stageId]);
        }

        return [null, $orphaned !== null ? 'storyboard_claim_lost' : 'storyboard_failed_unrecorded'];
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @return list<array<string, mixed>>
     */
    private function screenplayScenesOf(array $screenplay): array
    {
        return array_values(array_filter(
            (array) ($screenplay['scenes'] ?? []),
            static fn (mixed $scene): bool => is_array($scene) && is_string($scene['id'] ?? null),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $scenes
     * @return array<string, bool>
     */
    private function continuableScenes(array $scenes): array
    {
        $continuable = [];
        $previous = null;

        foreach ($scenes as $scene) {
            $firstBeat = is_array($scene['beats'][0] ?? null) ? $scene['beats'][0] : [];
            $sameCast = static function (array $a, array $b): bool {
                $a = array_values(array_filter((array) ($a['character_ids'] ?? []), 'is_string'));
                $b = array_values(array_filter((array) ($b['character_ids'] ?? []), 'is_string'));
                sort($a);
                sort($b);

                return $a === $b;
            };

            $continuable[$scene['id']] = $previous !== null
                && trim((string) ($firstBeat['time_jump'] ?? '')) === ''
                && $sameCast($previous, $scene)
                && $this->canonical(($previous['subject_state'] ?? null)['end'] ?? null)
                    === $this->canonical(($scene['subject_state'] ?? null)['start'] ?? null);

            foreach (['location_id', 'stage', 'int_ext', 'time', 'light_and_weather'] as $field) {
                if ($previous !== null && ($previous[$field] ?? null) !== ($scene[$field] ?? null)) {
                    $continuable[$scene['id']] = false;
                }
            }

            $previous = $scene;
        }

        return $continuable;
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @param  array{mode: string, subject: array<string, mixed>, design: ?array<string, mixed>, clip_durations_ms: list<int>, film: ?array<string, mixed>}  $origin
     * @return array<string, mixed>
     */
    private function storyboardPacket(array $screenplay, array $origin): array
    {
        $sources = $this->screenplayScenesOf($screenplay);
        $continuable = $this->continuableScenes($sources);
        $pick = static fn (array $row, array $keys): array => array_combine(
            $keys,
            array_map(static fn (string $key): mixed => $row[$key] ?? null, $keys),
        );
        $scenes = [];
        $characterIds = [];
        $locationIds = [];
        $sceneIds = [];

        foreach ($sources as $source) {
            $sceneIds[] = $source['id'];
            $locationIds[(string) ($source['location_id'] ?? '')] = true;

            foreach ((array) ($source['character_ids'] ?? []) as $characterId) {
                $characterIds[(string) $characterId] = true;
            }

            $scenes[] = $pick($source, [
                'id', 'stage', 'location_id', 'character_ids', 'int_ext', 'time', 'light_and_weather', 'action', 'props',
                'scene_mode', 'camera', 'duration_estimate_ms',
            ]) + [
                'beats' => array_map(
                    static fn (array $beat): array => $pick($beat, ['id', 'action', 'visible_result', 'time_jump']),
                    array_values(array_filter((array) ($source['beats'] ?? []), 'is_array')),
                ),
                'subject_state' => is_array($source['subject_state'] ?? null) ? $source['subject_state'] : null,
                \App\Video\Screenplay\SceneBeats::ROOM_STATE_KEY => is_array($source[\App\Video\Screenplay\SceneBeats::ROOM_STATE_KEY] ?? null)
                    ? $source[\App\Video\Screenplay\SceneBeats::ROOM_STATE_KEY]
                    : null,
                'may_continue_previous_scene' => $continuable[$source['id']],
            ];
        }

        $listed = static fn (string $key, array $ids, array $fields): array => array_values(array_map(
            static fn (array $row): array => $pick($row, $fields),
            array_filter(
                (array) ($screenplay[$key] ?? []),
                static fn (mixed $row): bool => is_array($row) && isset($ids[(string) ($row['id'] ?? '')]),
            ),
        ));

        $coverage = [];

        foreach ((array) ($screenplay['coverage'] ?? []) as $item) {
            if (! is_array($item) || ($item['mode'] ?? null) !== 'shown' || ! is_string($item['coverage_id'] ?? null)) {
                continue;
            }

            $carriers = array_values(array_intersect((array) ($item['scene_ids'] ?? []), $sceneIds));

            if ($carriers !== []) {
                $coverage[] = ['coverage_id' => $item['coverage_id'], 'scene_ids' => $carriers];
            }
        }

        $design = $origin['design'];
        $locations = $listed('locations', $locationIds, [
            'id', 'name', 'description', 'spatial_relation', 'subject_id', 'enclosure',
            'layout', 'fixed_features', 'light_sources', 'connections', ...($design === null ? [] : [DesignLocationBuilder::DESIGN_REFS_KEY]),
        ]);

        if ($design !== null) {
            foreach ($locations as $index => $location) {
                $rows = DesignLocationBuilder::placeDesignRows($design, $location[DesignLocationBuilder::DESIGN_REFS_KEY] ?? []);
                $locations[$index] += $rows === [] ? [] : ['design_rows' => $rows];
            }
        }

        return $origin['subject'] + [
            'characters' => $listed('characters', $characterIds, ['id', 'name', 'description']),
            'locations' => $locations,
            'coverage' => $coverage,
            'clip_durations_ms' => $origin['clip_durations_ms'],
        ] + (is_array($origin['film']) ? ['film' => array_intersect_key($origin['film'], array_flip(['total_ms', 'aspect_ratio', 'min_shot_used_ms']))] : []) + [
            'scenes' => $scenes,
        ];
    }

    /**
     * @param  list<mixed>  $answer
     * @param  array<string, mixed>  $screenplay
     * @param  array<string, mixed>|null  $design  the verified source design; null for a storyboard planned from the anchor prompt
     * @param  list<int>|null  $durations  the clip lengths the shots may take; null for the scene clip models
     * @param  int|null  $minUsedMs  the shortest part of a clip the edit may keep; null when the storyboard has none
     * @return array{0: list<array<string, mixed>>, 1: list<string>, 2: array<string, string>}
     */
    private function storyboardShots(array $answer, array $screenplay, ?array $design = null, ?array $durations = null, ?int $minUsedMs = null): array
    {
        $sources = $this->screenplayScenesOf($screenplay);

        if ($sources === []) {
            throw new ScenePlanException('The selected screenplay has no scenes to break down.');
        }

        if (count($answer) !== count($sources)) {
            throw new ScenePlanException(
                'Storyboard returned '.count($answer).' scenes; the screenplay has '.count($sources).'.'
            );
        }

        $continuable = $this->continuableScenes($sources);
        $coverage = [];

        foreach ((array) ($screenplay['coverage'] ?? []) as $item) {
            if (is_array($item) && ($item['mode'] ?? null) === 'shown' && is_string($item['coverage_id'] ?? null)) {
                foreach ((array) ($item['scene_ids'] ?? []) as $sceneId) {
                    $coverage[(string) $sceneId][$item['coverage_id']] = true;
                }
            }
        }

        $durations = array_values(array_filter($durations ?? $this->clipDurationsMs(), static fn (int $ms): bool => $ms > 0));

        if ($durations === []) {
            throw new ScenePlanException('No clip duration is configured for the storyboard shots.');
        }

        $empty = ['progress' => null, 'configuration' => []];
        $shots = [];
        $warnings = [];
        $conflicts = [];
        $previous = null;
        $carried = [];
        $designIds = $design === null ? [] : VesselDesign::canonicalIds($design);
        $codes = [];
        $movement = ScenePlanAuthor::STATIC_MOVEMENT;

        foreach ($sources as $index => $source) {
            $sceneId = (string) $source['id'];
            $answered = $answer[$index] ?? null;

            if (! is_array($answered) || ($answered['scene_id'] ?? null) !== $sceneId) {
                throw new ScenePlanException('storyboard scene '.($index + 1).': expected '.$sceneId.'.');
            }

            $conflict = $answered['duration_conflict'] ?? null;

            if ($conflict !== null && (! is_string($conflict) || trim($conflict) === '')) {
                throw new ScenePlanException($sceneId.': duration_conflict is null or a nonempty text.');
            }

            if (is_string($conflict)) {
                $conflicts[$sceneId] = trim($conflict);
            }

            [$objects, $objectWarnings] = $this->storyboardObjects($sceneId, $answered['tracked_objects'] ?? null, $carried);
            $warnings = array_merge($warnings, $objectWarnings);
            $beatIds = \App\Video\Screenplay\SceneBeats::beatIds($source);
            $answeredShots = $answered['shots'] ?? null;

            if (! is_array($answeredShots) || ! array_is_list($answeredShots) || $answeredShots === []) {
                throw new ScenePlanException($sceneId.': shots must be a nonempty list.');
            }

            $shown = is_array($source['subject_state'] ?? null);
            $state = $shown ? $source['subject_state']['start'] : $empty;
            $cursor = ['next' => 0, 'open' => null];
            $room = is_array($source[\App\Video\Screenplay\SceneBeats::ROOM_STATE_KEY] ?? null)
                ? $source[\App\Video\Screenplay\SceneBeats::ROOM_STATE_KEY]
                : null;
            $roomTracked = \App\Video\Screenplay\SceneBeats::roomOrder($room['start'] ?? null) !== null
                && \App\Video\Screenplay\SceneBeats::roomOrder($room['end'] ?? null) !== null;
            $roomState = $roomTracked ? $room['start'] : null;

            foreach ($answeredShots as $position => $item) {
                $number = sprintf('s%02d', $position + 1);
                $at = $sceneId.'.'.$number;

                if (! is_array($item)) {
                    throw new ScenePlanException($at.': the shot must be an object.');
                }

                [$cursor, $coverageItems] = $this->storyboardCoverage($at, $item['beat_coverage'] ?? null, $beatIds, $cursor);
                $relation = $item['camera_relation'] ?? null;

                if (! in_array($relation, [ScenePlanAuthor::NEW_CAMERA, ScenePlanAuthor::SAME_CAMERA], true)) {
                    throw new ScenePlanException($at.': camera_relation '.var_export($relation, true).' is unknown.');
                }

                $mode = $relation === ScenePlanAuthor::SAME_CAMERA ? ScenePreservationPrompt::CONTINUATION : ScenePreservationPrompt::HARD_CUT;

                if ($previous === null && $mode !== ScenePreservationPrompt::HARD_CUT) {
                    throw new ScenePlanException($at.': the first shot of the film must use '.ScenePlanAuthor::NEW_CAMERA.'.');
                }

                if ($position === 0 && $previous !== null && $mode === ScenePreservationPrompt::CONTINUATION
                    && ! $continuable[$sceneId]) {
                    throw new ScenePlanException(
                        $at.': '.$sceneId.' cannot keep the previous scene\'s camera; place, time, light, cast or subject state differ.'
                    );
                }

                $duration = $item['duration_ms'] ?? null;

                if (! is_int($duration) || ! in_array($duration, $durations, true)) {
                    throw new ScenePlanException(
                        $at.': duration_ms '.var_export($duration, true).' is not a clip length the renderer makes ('.implode(', ', $durations).').'
                    );
                }

                $usedStart = $item['used_start_ms'] ?? null;
                $usedMs = $item['used_ms'] ?? null;
                $resultAt = $item['result_at_ms'] ?? null;

                if (! is_int($usedStart) || ! is_int($usedMs) || $usedStart < 0 || $usedMs <= 0 || $usedStart + $usedMs > $duration) {
                    throw new ScenePlanException(
                        $at.': the used part '.var_export($usedStart, true).' + '.var_export($usedMs, true).' ms must lie inside the clip\'s duration_ms '.$duration.'.'
                    );
                }

                if ($minUsedMs !== null && $usedMs < $minUsedMs) {
                    throw new ScenePlanException(
                        $at.': used_ms '.$usedMs.' is under '.$minUsedMs.' ms, the shortest part a viewer can read; merge it with the shot beside it.'
                    );
                }

                if (! is_int($resultAt) || $resultAt < $usedStart || $resultAt > $usedStart + $usedMs) {
                    throw new ScenePlanException(
                        $at.': result_at_ms '.var_export($resultAt, true).' must fall inside the used part '.$usedStart.'–'.($usedStart + $usedMs)
                        .' ms, so the edit shows the state the next shot starts from.'
                    );
                }

                if ($mode === ScenePreservationPrompt::CONTINUATION && $movement !== ScenePlanAuthor::STATIC_MOVEMENT) {
                    throw new ScenePlanException($at.': same_camera keeps a still frame, but the previous camera moves ('.$movement.').');
                }

                $reason = $item['camera_change_reason'] ?? null;

                if ($mode === ScenePreservationPrompt::CONTINUATION && $reason !== null) {
                    throw new ScenePlanException($at.': a continuation keeps the camera, so camera_change_reason is null.');
                }

                if ($mode === ScenePreservationPrompt::HARD_CUT && $previous !== null
                    && (! is_string($reason) || mb_strlen(trim($reason)) < 3 || mb_strlen(trim($reason)) > ScenePlanAuthor::CAMERA_TEXT_LIMIT)) {
                    throw new ScenePlanException($at.': a new camera after another shot says why the camera changes in camera_change_reason.');
                }

                $purpose = is_string($item['visual_purpose'] ?? null) ? trim($item['visual_purpose']) : '';

                if (mb_strlen($purpose) < 3 || mb_strlen($purpose) > 300) {
                    throw new ScenePlanException($at.': visual_purpose must be 3 to 300 characters.');
                }

                [$afterJump, $state, $roomState, $objects] = $this->storyboardAfterJump(
                    $at, array_key_exists('after_jump', $item) ? $item['after_jump'] : null, $coverageItems, $source, $mode,
                    $state, $roomTracked ? $room : null, $roomState, $objects,
                );

                $last = $position === count($answeredShots) - 1;
                $end = $item['subject_end_state'] ?? null;

                if (($last || ! $shown) && $end !== null) {
                    throw new ScenePlanException($last
                        ? $at.': the last shot of a scene takes the scene\'s end state, so subject_end_state is null.'
                        : $at.': '.$sceneId.' does not show the subject, so subject_end_state is null.');
                }

                if (! $last && $shown && ! is_array($end)) {
                    throw new ScenePlanException($at.': subject_end_state is required before the last shot of the scene.');
                }

                $endState = $last ? ($shown ? $source['subject_state']['end'] : $empty) : ($shown ? $end : $empty);
                $roomEnd = $this->storyboardRoomEnd($at, $item['room_state_end'] ?? null, $last, $roomTracked ? $room : null, $roomState);

                if ($roomTracked && $roomEnd !== $roomState
                    && ! \App\Video\Screenplay\SceneBeats::progressChanges(['start' => $state, 'end' => $endState])) {
                    throw new ScenePlanException(
                        $at.': the room becomes '.$roomEnd.' in this shot but the subject\'s progress does not change in it.'
                    );
                }

                $objectsStart = $objects;
                $firstFrame = $this->storyboardVisibleObjects($at, 'first_frame_object_ids', $item['first_frame_object_ids'] ?? null, $objects);
                $lastFrame = $this->storyboardVisibleObjects($at, 'last_frame_object_ids', $item['last_frame_object_ids'] ?? null, $objects);
                $objects = $this->storyboardStateChanges(
                    $at, $item['state_changes'] ?? null, $objects, array_values(array_unique([...$firstFrame, ...$lastFrame])),
                );
                $continues = $mode === ScenePreservationPrompt::CONTINUATION;
                $code = $sceneId.'_'.$number;
                $keyframe = $this->storyboardText($at, 'keyframe', $item['keyframe'] ?? null);

                $shot = [
                    'scene_code' => $code,
                    'screenplay_scene_code' => $sceneId,
                    'shot_index' => $position + 1,
                    'location_id' => (string) ($source['location_id'] ?? ''),
                    'character_ids' => array_values(array_filter((array) ($source['character_ids'] ?? []), 'is_string')),
                    'title' => strtoupper($sceneId).' · '.$number,
                    'purpose' => $purpose,
                    'coverage_ids' => array_keys($coverage[$sceneId] ?? []),
                    'basis' => 'source_supported',
                    'state_before' => $previous === null
                        ? 'film_start'
                        : ($continues ? $previous['scene_state'] : $previous['video']['end_state']),
                    'scene_state' => $code.'_keyframe',
                    'transition_mode' => $mode,
                    'continuity_group' => $continues ? $previous['continuity_group'] : $code,
                    'source_scene_code' => $continues ? $previous['scene_code'] : '',
                    'camera' => $this->storyboardCamera($at, $mode, $item['camera'] ?? null),
                    'camera_change_reason' => is_string($reason) ? trim($reason) : null,
                    'delta' => $keyframe,
                    'video' => [
                        'action' => $this->storyboardText($at, 'action', $item['action'] ?? null),
                        'preserve' => self::STORYBOARD_PRESERVE,
                        'end_state' => $code.'_end',
                        'camera_relation' => $relation,
                        'duration_ms' => $duration,
                        'used_start_ms' => $usedStart,
                        'used_ms' => $usedMs,
                        'result_at_ms' => $resultAt,
                    ],
                    'beat_ids' => array_values(array_unique(array_column($coverageItems, 'beat_id'))),
                    'beat_coverage' => $coverageItems,
                    'keyframe_state' => $state,
                    'end_state' => $endState,
                    'objects_start' => array_values($objectsStart),
                    'objects_end' => array_values($objects),
                    'objects_first_frame' => $firstFrame,
                    'objects_last_frame' => $lastFrame,
                    'reference_requirements' => $this->storyboardReferenceNeeds($at, $item['reference_requirements'] ?? null, $design === null ? null : $designIds, $codes),
                ] + ($room === null ? [] : [
                    \App\Video\Screenplay\SceneBeats::ROOM_STATE_KEY => ['start' => $roomState, 'end' => $roomEnd],
                ]) + ($afterJump === null ? [] : [
                    self::AFTER_JUMP_KEY => $afterJump,
                ]);

                $this->checkShotStates($at, $shot, $source);

                $warnings = array_merge($warnings, $this->sceneWarnings($at, $keyframe));
                $shots[] = $shot;
                $codes[] = $code;
                $movement = $shot['camera']['movement']['type'] ?? ($continues ? $movement : ScenePlanAuthor::STATIC_MOVEMENT);
                $previous = $shot;
                $state = $endState;
                $roomState = $roomEnd;
            }

            if ($cursor['open'] !== null || $cursor['next'] !== count($beatIds)) {
                throw new ScenePlanException(
                    $sceneId.': the shots do not cover every beat in order; '.($beatIds[$cursor['next']] ?? $cursor['open']).' is not finished.'
                );
            }

            foreach ($objects as $objectId => $object) {
                $carried[$objectId] = $object;
            }
        }

        return [$shots, $warnings, $conflicts];
    }

    /** @return list<int> */
    private function clipDurationsMs(): array
    {
        $seconds = [];

        foreach ((array) config('video.media_models.video.scene_clip', []) as $entry) {
            foreach ((array) ($entry['controls']['durations'] ?? []) as $value) {
                if (is_int($value) && $value > 0) {
                    $seconds[$value] = true;
                }
            }
        }

        ksort($seconds);

        return array_map(static fn (int $value): int => $value * 1000, array_keys($seconds));
    }

    /**
     * @param  array<string, array{object_id: string, name: string, state: string}>  $carried
     * @return array{0: array<string, array{object_id: string, name: string, state: string}>, 1: list<string>}
     */
    private function storyboardObjects(string $sceneId, mixed $tracked, array $carried): array
    {
        if (! is_array($tracked) || ! array_is_list($tracked)) {
            throw new ScenePlanException($sceneId.': tracked_objects must be a list.');
        }

        $objects = [];
        $warnings = [];

        foreach ($tracked as $item) {
            $objectId = is_array($item) ? (string) ($item['object_id'] ?? '') : '';
            $name = is_array($item) && is_string($item['name'] ?? null) ? trim($item['name']) : '';
            $start = is_array($item) ? ($item['start_state'] ?? null) : null;

            if (preg_match('/^[a-z][a-z0-9_]{1,40}$/', $objectId) !== 1 || $name === '' || array_key_exists($objectId, $objects)) {
                throw new ScenePlanException($sceneId.': tracked object '.var_export($objectId, true).' is malformed or repeated.');
            }

            if ($start === null) {
                if (! array_key_exists($objectId, $carried)) {
                    throw new ScenePlanException(
                        $sceneId.': tracked object '.$objectId.' has no start_state and no earlier scene tracked it.'
                    );
                }

                $start = $carried[$objectId]['state'];
            } elseif (! is_string($start) || trim($start) === '') {
                throw new ScenePlanException($sceneId.': tracked object '.$objectId.' has an empty start_state.');
            } elseif (array_key_exists($objectId, $carried) && trim($start) !== $carried[$objectId]['state']) {
                $warnings[] = $sceneId.': '.$objectId.' starts as "'.trim($start).'" but the previous scene left it as "'
                    .$carried[$objectId]['state'].'" — check that the screenplay allows the change.';
            }

            $objects[$objectId] = ['object_id' => $objectId, 'name' => $name, 'state' => trim($start)];
        }

        return [$objects, $warnings];
    }

    /**
     * @param  list<array{beat_id: string, part: string}>  $coverage  the shot's validated coverage
     * @param  array<string, mixed>  $source  the screenplay scene
     * @param  array<string, mixed>  $state  the subject state the previous shot ends on
     * @param  array{start: string, end: string}|null  $room  the scene's room state when it tracks one
     * @param  array<string, array{object_id: string, name: string, state: string}>  $objects
     * @return array{0: ?array<string, mixed>, 1: array<string, mixed>, 2: ?string, 3: array<string, array{object_id: string, name: string, state: string}>}
     *         [$afterJump, $state, $roomState, $objects] the states the shot starts from after a declared time jump; unchanged without one
     */
    private function storyboardAfterJump(
        string $at,
        mixed $jump,
        array $coverage,
        array $source,
        string $mode,
        array $state,
        ?array $room,
        ?string $roomState,
        array $objects,
    ): array {
        $opening = $coverage[0];
        $beat = collect((array) ($source['beats'] ?? []))->first(static fn (mixed $row): bool => is_array($row) && ($row['id'] ?? null) === $opening['beat_id']);
        $jumpText = is_array($beat) && is_string($beat['time_jump'] ?? null) ? trim($beat['time_jump']) : '';
        $opens = $jumpText !== '' && in_array($opening['part'], ['whole', 'opening'], true);

        if ($jump === null) {
            if ($opens) {
                throw new ScenePlanException($at.': the shot opens '.$opening['beat_id'].' after its time_jump ("'.$jumpText
                    .'"), so after_jump gives the later moment it starts from.');
            }

            return [null, $state, $roomState, $objects];
        }

        if (! is_array($jump) || array_diff(ScenePlanAuthor::AFTER_JUMP_FIELDS, array_keys($jump)) !== []
            || array_diff(array_keys($jump), ScenePlanAuthor::AFTER_JUMP_FIELDS) !== []) {
            throw new ScenePlanException($at.': after_jump needs '.implode(', ', ScenePlanAuthor::AFTER_JUMP_FIELDS).'.');
        }

        if (! $opens) {
            throw new ScenePlanException($at.': after_jump is given only on a shot that opens a beat with a time_jump; this shot opens '
                .$opening['beat_id'].' '.$opening['part'].', which has none.');
        }

        if ($jump['beat_id'] !== $opening['beat_id']) {
            throw new ScenePlanException($at.': after_jump.beat_id must be '.$opening['beat_id'].', the beat whose time_jump the shot opens.');
        }

        if ($mode !== ScenePreservationPrompt::HARD_CUT) {
            throw new ScenePlanException($at.': a shot after a time jump starts from a new keyframe of the later moment, so it uses new_camera.');
        }

        $flat = static fn (string $text): string => trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($text)));
        $evidence = is_string($jump['evidence']) ? trim($jump['evidence']) : '';

        if ($evidence === '' || ! str_contains($flat($jumpText), $flat($evidence))) {
            throw new ScenePlanException($at.': after_jump.evidence must quote the time_jump of '.$opening['beat_id'].' word for word.');
        }

        $shown = is_array($source['subject_state'] ?? null);
        $start = $jump['subject_start_state'];

        if ($shown !== is_array($start)) {
            throw new ScenePlanException($shown
                ? $at.': after_jump.subject_start_state gives the subject at the later moment.'
                : $at.': '.($source['id'] ?? '?').' does not show the subject, so after_jump.subject_start_state is null.');
        }

        $roomStart = $jump['room_state_start'];

        if ($room === null && $roomStart !== null) {
            throw new ScenePlanException($at.': the scene has no room state, so after_jump.room_state_start is null.');
        }

        if ($room !== null) {
            $order = \App\Video\Screenplay\SceneBeats::roomOrder(is_string($roomStart) ? $roomStart : null);

            if ($order === null || $order < \App\Video\Screenplay\SceneBeats::roomOrder($roomState)
                || $order > \App\Video\Screenplay\SceneBeats::roomOrder($room['end'])) {
                throw new ScenePlanException($at.': after_jump.room_state_start must lie between '.$roomState.' and the scene\'s room state end '.$room['end'].'.');
            }
        }

        $changes = $jump['object_states'];

        if (! is_array($changes) || ! array_is_list($changes)) {
            throw new ScenePlanException($at.': after_jump.object_states must be a list.');
        }

        $seen = [];

        foreach ($changes as $change) {
            $objectId = is_array($change) ? (string) ($change['object_id'] ?? '') : '';
            $objectState = is_array($change) && is_string($change['state'] ?? null) ? trim($change['state']) : '';

            if (! array_key_exists($objectId, $objects) || $objectState === '' || isset($seen[$objectId])) {
                throw new ScenePlanException($at.': after_jump.object_states names '.var_export($objectId, true).', which the scene does not track, or repeats it or leaves it empty.');
            }

            $seen[$objectId] = true;
            $objects[$objectId]['state'] = $objectState;
        }

        return [[
            'beat_id' => $opening['beat_id'],
            'evidence' => $evidence,
            'subject_start_state' => $start,
            'room_state_start' => $roomStart,
            'object_states' => array_values($changes),
        ], $shown ? $start : $state, $room === null ? $roomState : $roomStart, $objects];
    }

    /**
     * @param  list<string>  $beatIds
     * @param  array{next: int, open: ?string}  $cursor
     * @return array{0: array{next: int, open: ?string}, 1: list<array{beat_id: string, part: string}>}
     */
    private function storyboardCoverage(string $at, mixed $coverage, array $beatIds, array $cursor): array
    {
        if (! is_array($coverage) || ! array_is_list($coverage) || $coverage === []) {
            throw new ScenePlanException($at.': beat_coverage must be a nonempty list.');
        }

        $items = [];

        foreach ($coverage as $entry) {
            $beatId = is_array($entry) ? (string) ($entry['beat_id'] ?? '') : '';
            $part = is_array($entry) ? (string) ($entry['part'] ?? '') : '';

            if ($cursor['open'] !== null) {
                if ($beatId !== $cursor['open'] || ! in_array($part, ['middle', 'closing'], true)) {
                    throw new ScenePlanException($at.': '.$cursor['open'].' was opened and must continue with middle or closing before any other beat.');
                }

                if ($part === 'closing') {
                    $cursor = ['next' => $cursor['next'] + 1, 'open' => null];
                }
            } else {
                $expected = $beatIds[$cursor['next']] ?? null;

                if ($beatId !== $expected || ! in_array($part, ['whole', 'opening'], true)) {
                    throw new ScenePlanException(
                        $at.': expected '.($expected ?? 'no further beat').' as whole or opening, got '.$beatId.' '.$part.'.'
                    );
                }

                $cursor = $part === 'whole'
                    ? ['next' => $cursor['next'] + 1, 'open' => null]
                    : ['next' => $cursor['next'], 'open' => $beatId];
            }

            $shown = is_array($entry) && is_string($entry['shown'] ?? null) ? trim($entry['shown']) : '';

            if (mb_strlen($shown) < 3 || mb_strlen($shown) > ScenePlanAuthor::CAMERA_TEXT_LIMIT) {
                throw new ScenePlanException($at.': beat_coverage '.$beatId.' says in shown what the shot shows of it, 3 to '.ScenePlanAuthor::CAMERA_TEXT_LIMIT.' characters.');
            }

            $items[] = ['beat_id' => $beatId, 'part' => $part, 'shown' => $shown];
        }

        return [$cursor, $items];
    }

    /**
     * @param  array<string, array{object_id: string, name: string, state: string}>  $objects
     * @return list<string>
     */
    private function storyboardVisibleObjects(string $at, string $field, mixed $ids, array $objects): array
    {
        if (! is_array($ids) || ! array_is_list($ids)) {
            throw new ScenePlanException($at.': '.$field.' must be a list.');
        }

        $visible = [];

        foreach ($ids as $objectId) {
            if (! is_string($objectId) || ! array_key_exists($objectId, $objects) || in_array($objectId, $visible, true)) {
                throw new ScenePlanException($at.': '.$field.' names '.var_export($objectId, true).', which the scene does not track, or repeats it.');
            }

            $visible[] = $objectId;
        }

        return $visible;
    }

    /**
     * @param  array<string, array{object_id: string, name: string, state: string}>  $objects
     * @param  list<string>  $visible
     * @return array<string, array{object_id: string, name: string, state: string}>
     */
    private function storyboardStateChanges(string $at, mixed $changes, array $objects, array $visible): array
    {
        if (! is_array($changes) || ! array_is_list($changes)) {
            throw new ScenePlanException($at.': state_changes must be a list.');
        }

        $seen = [];

        foreach ($changes as $change) {
            $objectId = is_array($change) ? (string) ($change['object_id'] ?? '') : '';
            $state = is_array($change) && is_string($change['state'] ?? null) ? trim($change['state']) : '';

            if (! array_key_exists($objectId, $objects)) {
                throw new ScenePlanException($at.': state change names '.var_export($objectId, true).', which the scene does not track.');
            }

            if (! in_array($objectId, $visible, true)) {
                throw new ScenePlanException($at.': '.$objectId.' changes in the clip but is in neither first_frame_object_ids nor last_frame_object_ids.');
            }

            if ($state === '' || array_key_exists($objectId, $seen)) {
                throw new ScenePlanException($at.': state change for '.$objectId.' is empty or repeated.');
            }

            $seen[$objectId] = true;
            $objects[$objectId]['state'] = $state;
        }

        return $objects;
    }

    /**
     * @param  array<string, true>|null  $designIds  the ids of the source design; null when the storyboard has no design
     * @param  list<string>  $earlier  the codes of the shots before this one
     * @return list<array{kind: string, purpose: string, view: ?string, state: ?string, must_show: string, design_refs: list<string>, source_shot: ?string}>
     */
    private function storyboardReferenceNeeds(string $at, mixed $needs, ?array $designIds, array $earlier): array
    {
        if (! is_array($needs) || ! array_is_list($needs)) {
            throw new ScenePlanException($at.': reference_requirements must be a list.');
        }

        $out = [];
        $text = static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;

        foreach ($needs as $need) {
            $kind = is_array($need) ? (string) ($need['kind'] ?? '') : '';
            $purpose = is_array($need) && is_string($need['purpose'] ?? null) ? trim($need['purpose']) : '';

            if (! in_array($kind, ScenePlanAuthor::REFERENCE_KINDS, true) || $purpose === '') {
                throw new ScenePlanException($at.': reference requirement '.var_export($kind, true).' is unknown or has no purpose.');
            }

            if (array_diff(ScenePlanAuthor::REFERENCE_FIELDS, array_keys($need)) !== [] || $text($need['must_show']) === null) {
                throw new ScenePlanException($at.': reference requirement '.$kind.' needs '.implode(', ', ScenePlanAuthor::REFERENCE_FIELDS).' with a must_show.');
            }

            $refs = $need['design_refs'];

            if (! is_array($refs) || ! array_is_list($refs)) {
                throw new ScenePlanException($at.': reference requirement '.$kind.' design_refs must be a list.');
            }

            foreach ($refs as $ref) {
                if (! is_string($ref) || $designIds === null || ! isset($designIds[$ref])) {
                    throw new ScenePlanException($at.': reference requirement '.$kind.' names '.var_export($ref, true)
                        .($designIds === null ? ', but this storyboard has no source design.' : ', which is not an id of the source design.'));
                }
            }

            $view = $need['view'];

            if ($view !== null && (! in_array($kind, ScenePlanAuthor::VIEWED_REFERENCE_KINDS, true) || ! in_array($view, ScenePlanAuthor::referenceViews(), true))) {
                throw new ScenePlanException($at.': view '.var_export($view, true).' is not a reference view, or is given for '.$kind.', which takes no view.');
            }

            $sourceShot = $need['source_shot'];

            if ($sourceShot !== null && ($kind !== 'continuity' || ! in_array($sourceShot, $earlier, true))) {
                throw new ScenePlanException($at.': source_shot '.var_export($sourceShot, true).' must be an earlier shot and is given only for continuity.');
            }

            $out[] = [
                'kind' => $kind,
                'purpose' => $purpose,
                'view' => $view,
                'state' => $text($need['state']),
                'must_show' => (string) $text($need['must_show']),
                'design_refs' => array_values(array_unique($refs)),
                'source_shot' => $sourceShot,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    private function storyboardCamera(string $at, string $mode, mixed $camera): ?array
    {
        if ($mode === ScenePreservationPrompt::CONTINUATION) {
            if ($camera !== null) {
                throw new ScenePlanException($at.': a continuation keeps the previous camera, so camera is null.');
            }

            return null;
        }

        if (! is_array($camera) || array_is_list($camera)
            || array_diff(array_keys($camera), ScenePlanAuthor::CAMERA_FIELDS) !== []
            || array_diff(ScenePlanAuthor::CAMERA_FIELDS, array_keys($camera)) !== []) {
            throw new ScenePlanException($at.': a hard cut needs a camera with '.implode(', ', ScenePlanAuthor::CAMERA_FIELDS).'.');
        }

        $out = [];

        foreach (['position', 'elevation', 'framing'] as $field) {
            $value = is_string($camera[$field]) ? trim($camera[$field]) : '';

            if (mb_strlen($value) < 3 || mb_strlen($value) > ScenePlanAuthor::CAMERA_TEXT_LIMIT) {
                throw new ScenePlanException(
                    $at.': camera.'.$field.' must be 3 to '.ScenePlanAuthor::CAMERA_TEXT_LIMIT.' characters.'
                );
            }

            $out[$field] = $value;
        }

        $side = $camera['subject_side'];

        if ($side !== null && ! in_array($side, ScenePlanAuthor::SUBJECT_SIDES, true)) {
            throw new ScenePlanException($at.': camera.subject_side '.var_export($side, true).' is unknown.');
        }

        $out['subject_side'] = $side;

        foreach (['shot_size' => ScenePlanAuthor::SHOT_SIZES, 'angle' => ScenePlanAuthor::CAMERA_ANGLES, 'lens' => ScenePlanAuthor::CAMERA_LENSES] as $field => $allowed) {
            if (! in_array($camera[$field], $allowed, true)) {
                throw new ScenePlanException($at.': camera.'.$field.' '.var_export($camera[$field], true).' is unknown.');
            }

            $out[$field] = $camera[$field];
        }

        $text = static fn (mixed $value): ?string => is_string($value)
            && mb_strlen(trim($value)) >= 3 && mb_strlen(trim($value)) <= ScenePlanAuthor::CAMERA_TEXT_LIMIT ? trim($value) : null;
        $out['facing'] = $text($camera['facing']) ?? throw new ScenePlanException($at.': camera.facing must be 3 to '.ScenePlanAuthor::CAMERA_TEXT_LIMIT.' characters.');

        $composition = $camera['composition'];

        if (! is_array($composition) || array_diff(ScenePlanAuthor::COMPOSITION_FIELDS, array_keys($composition)) !== []
            || array_diff(array_keys($composition), ScenePlanAuthor::COMPOSITION_FIELDS) !== [] || $text($composition['must_show']) === null) {
            throw new ScenePlanException($at.': camera.composition needs '.implode(', ', ScenePlanAuthor::COMPOSITION_FIELDS).' with a must_show.');
        }

        foreach (ScenePlanAuthor::COMPOSITION_FIELDS as $field) {
            if ($composition[$field] !== null && $text($composition[$field]) === null) {
                throw new ScenePlanException($at.': camera.composition.'.$field.' is null or 3 to '.ScenePlanAuthor::CAMERA_TEXT_LIMIT.' characters.');
            }

            $out['composition'][$field] = $text($composition[$field]);
        }

        $movement = $camera['movement'];

        if (! is_array($movement) || array_diff(ScenePlanAuthor::MOVEMENT_FIELDS, array_keys($movement)) !== []
            || array_diff(array_keys($movement), ScenePlanAuthor::MOVEMENT_FIELDS) !== []
            || ! in_array($movement['type'], ScenePlanAuthor::CAMERA_MOVEMENTS, true)) {
            throw new ScenePlanException($at.': camera.movement needs a known type, a start and an end.');
        }

        $still = $movement['type'] === ScenePlanAuthor::STATIC_MOVEMENT;

        if ($still ? ($movement['start'] !== null || $movement['end'] !== null) : ($text($movement['start']) === null || $text($movement['end']) === null)) {
            throw new ScenePlanException($still
                ? $at.': a static camera has no movement start or end.'
                : $at.': a moving camera ('.$movement['type'].') names where it starts and where it ends.');
        }

        $out['movement'] = ['type' => $movement['type'], 'start' => $text($movement['start']), 'end' => $text($movement['end'])];

        return $out;
    }

    /** @param  array{start: string, end: string}|null  $room */
    private function storyboardRoomEnd(string $at, mixed $answered, bool $last, ?array $room, ?string $current): ?string
    {
        if ($last || $room === null) {
            if ($answered !== null) {
                throw new ScenePlanException($last
                    ? $at.': the last shot of a scene takes the scene\'s room state end, so room_state_end is null.'
                    : $at.': the scene has no room state, so room_state_end is null.');
            }

            return $room === null ? null : $room['end'];
        }

        $order = \App\Video\Screenplay\SceneBeats::roomOrder(is_string($answered) ? $answered : null);

        if ($order === null) {
            throw new ScenePlanException(
                $at.': room_state_end is required before the last shot of a scene aboard: '.implode(', ', \App\Video\Screenplay\SceneBeats::ROOM_STATES).'.'
            );
        }

        if ($order < \App\Video\Screenplay\SceneBeats::roomOrder($current)) {
            throw new ScenePlanException($at.': room_state_end '.$answered.' goes back from '.$current.'.');
        }

        if ($order > \App\Video\Screenplay\SceneBeats::roomOrder($room['end'])) {
            throw new ScenePlanException($at.': room_state_end '.$answered.' passes the scene\'s room state end '.$room['end'].'.');
        }

        return $answered;
    }

    private function storyboardText(string $at, string $field, mixed $value): string
    {
        $text = is_string($value) ? trim($value) : '';

        if ($text === '' || mb_strlen($text) > ScenePlanAuthor::TEXT_LIMIT) {
            throw new ScenePlanException($at.': '.$field.' must be 1 to '.ScenePlanAuthor::TEXT_LIMIT.' characters.');
        }

        if (preg_match('/\bimage\s+\d+\s*:/i', $text) === 1) {
            throw new ScenePlanException($at.': '.$field.' carries a numbered image label.');
        }

        return $text;
    }

    /**
     * @param  list<array<string, mixed>>  $shots
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $authorUsage
     * @param  array<string, mixed>  $totals
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function storyboardOutput(
        array $shots,
        array $warnings,
        array $authorUsage,
        array $totals,
        string $authorRaw,
        ?int $revision = null,
        ?string $planHash = null,
        array $extra = [],
    ): array {
        return [
            'revision' => $revision,
            'scenes' => $shots,
            'warnings' => $warnings,
            'raw' => ['author' => $this->storableText($authorRaw)],
            'usage' => ['author' => $authorUsage, 'total' => $totals],
            'validation' => $planHash === null ? null : ['status' => 'passed', 'plan_sha256' => $planHash],
        ] + $extra;
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
            'screenplay_scene_code' => (string) $scene->screenplay_scene_code,
            'shot_index' => (int) $scene->shot_index,
            'location_id' => (string) ($state['location_id'] ?? ''),
            'character_ids' => array_values((array) ($state['character_ids'] ?? [])),
            'title' => (string) $scene->title,
            'purpose' => (string) $scene->purpose,
            'coverage_ids' => array_values((array) ($state['coverage_ids'] ?? [])),
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
        ] + (array_key_exists('beat_ids', $state) ? [
            'beat_ids' => array_values((array) $state['beat_ids']),
            'keyframe_state' => $state['keyframe_state'] ?? null,
            'end_state' => $state['end_state'] ?? null,
        ] : []) + (array_key_exists('camera', $video) ? [
            'camera' => $video['camera'],
        ] : []) + (($state['storyboard_shape'] ?? null) === ScenePlanAuthor::SHOT_SHAPE ? [
            'storyboard_shape' => ScenePlanAuthor::SHOT_SHAPE,
            'camera_relation' => $video['camera_relation'] ?? null,
            'duration_ms' => $video['duration_ms'] ?? null,
        ] + array_intersect_key($video, array_flip(['used_start_ms', 'used_ms', 'result_at_ms']))
            + array_intersect_key($state, array_flip(self::SHOT_SHAPE_STATE_KEYS)) : []) + (FilmCutAuthor::isFilmShape($state['film_cut_shape'] ?? null) ? [
            'film_cut' => $state['film'] ?? null,
        ] : []);
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

        if (! in_array($contract, ScenePlanAuthor::CONTRACT_VERSIONS, true)) {
            return [false, 'scene_contract_unsupported', $stage, null];
        }

        $review = $this->reviewForRevision($stage);

        if ($review['status'] !== 'passed') {
            return [false, 'scene_plan_not_reviewed', $stage, null];
        }

        $project = VideoProject::query()->whereKey($scene->project_id)->first();

        if ($project === null
            || (string) $project->selected_scene_plan_stage_id !== (string) $stage->id) {
            return [false, 'scene_plan_not_selected', $stage, null];
        }

        if ((string) $project->selected_screenplay_stage_id !== (string) $scene->screenplay_stage_id) {
            return [false, 'screenplay_selection_changed', $stage, null];
        }

        $screenplayStage = VideoPlanningStage::query()
            ->whereKey($project->selected_screenplay_stage_id)
            ->where('project_id', $scene->project_id)
            ->where('stage', PlanningStageName::SCREENPLAY->value)
            ->where('status', VideoPlanningStageStatus::SUCCEEDED->value)
            ->first();

        if ($screenplayStage === null
            || ! is_array($screenplayStage->output_json)
            || $this->screenplayApprovalService->matchingApproval($screenplayStage) === null
            || ! hash_equals(
                (string) $scene->screenplay_hash,
                \App\Video\Screenplay\ScreenplayContentHash::of($screenplayStage->output_json),
            )) {
            return [false, 'screenplay_selection_changed', $stage, null];
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

        if ($this->unresolvedShotCodes($live) !== []) {
            return [false, 'scene_plan_unresolved', $stage, null];
        }

        if (FilmCutAuthor::isFilmCut($contract)
            && ($contract !== FilmCutAuthor::CONTRACT_VERSION || $rows->contains(fn (VideoRenderScene $row): bool => $this->isLegacyFilmRow($row)))) {
            return [false, 'film_cut_legacy_rows', $stage, null];
        }

        if ($contract === FilmCutAuthor::CONTRACT_VERSION && $this->filmCutDrift($stage) !== []) {
            return [false, 'film_cut_sources_changed', $stage, null];
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

    private function isPlaceShot(VideoRenderScene $scene): bool
    {
        $state = is_array($scene->state_json) ? $scene->state_json : [];

        return array_key_exists('scene_subject_state', $state)
            && $state['scene_subject_state'] === null
            && ! is_array($state['space'] ?? null);
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

        $spec = is_array($candidate->prompt_spec_json) ? $candidate->prompt_spec_json : [];

        if (($spec['reference_mode'] ?? null) === \App\Video\Reference\ReferenceSheet::MODE) {
            return [false, 'source_is_reference_sheet'];
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
        $carriers = VideoRenderScene::query()
            ->where('project_id', $projectId)
            ->where('revision', $revision)
            ->where('transition_mode', ScenePreservationPrompt::HARD_CUT)
            ->whereIn('id', VideoDesignImage::query()
                ->where('project_id', $projectId)
                ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
                ->whereNotNull('render_scene_id')
                ->select('render_scene_id'))
            ->orderBy('scene_index')
            ->get();

        $entry = null;

        foreach ($carriers as $carrier) {
            $candidate = VideoDesignImage::query()
                ->where('project_id', $projectId)
                ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
                ->where('render_scene_id', $carrier->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->first();

            $spec = is_array($candidate?->prompt_spec_json) ? $candidate->prompt_spec_json : [];
            $entry = $spec['sources'][0] ?? null;

            if (! is_array($entry) || ($entry['role'] ?? null) !== 'environment') {
                break;
            }

            $entry = null;
        }

        if ($entry === null && $carriers->every(fn (VideoRenderScene $carrier): bool => $this->isPlaceShot($carrier))) {
            return [null, 'no_lock'];
        }

        if (! $this->manifestEntryShaped($entry)
            || $entry['role'] !== 'anchor'
            || $entry['position'] !== 0) {
            return [null, 'anchor_lock_unreadable'];
        }

        if ($this->anchorLockReplaced($projectId, (string) $entry['candidate_id'])) {
            return [null, 'anchor_lock_replaced'];
        }

        return [[
            'artifact_id' => $entry['artifact_id'],
            'candidate_id' => $entry['candidate_id'],
            'sha256' => $entry['sha256'],
        ], 'ok'];
    }

    /** @return bool whether the anchor a keyframe revision was locked to is the screenplay's anchor, since replaced by an approved anchor of the same design */
    private function anchorLockReplaced(string $projectId, string $candidateId): bool
    {
        $project = VideoProject::query()->find($projectId);
        $stage = $project === null || ! VesselDesign::isDesignFirst($project) ? null : $this->selectedProductionScreenplay($project);
        $locked = is_array($stage?->output_json) ? ($stage->output_json[VesselDesign::SOURCE_ANCHOR_KEY] ?? null) : null;
        $current = is_array($locked) && (string) ($locked['image_id'] ?? '') === $candidateId ? $this->productionAnchor($projectId) : null;

        return $current !== null && (string) $current->id !== $candidateId && $current->status === DesignImageStatus::APPROVED->value;
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
        $input = is_array($stage?->input_json) ? $stage->input_json : [];

        if (($input['source_mode'] ?? null) === ScenePlanAuthor::DESIGN_FIRST_SOURCE) {
            $screenplay = VideoPlanningStage::query()->whereKey((string) ($input['screenplay_stage_id'] ?? ''))->first();
            $designSource = is_array($screenplay?->output_json) ? ($screenplay->output_json[VesselDesign::SOURCE_DESIGN_KEY] ?? null) : null;

            if (! is_array($designSource)
                || (string) ($designSource['stage_id'] ?? '') !== (string) ($input['design_stage_id'] ?? '')
                || ! hash_equals((string) ($input['design_content_hash'] ?? ''), (string) ($designSource['content_hash'] ?? ''))
                || app(VesselDesignService::class)->verifiedSource($projectId, $designSource) === null) {
                return [null, 'design_changed_since_planning'];
            }

            $anchor = $this->productionAnchor($projectId);

            return $anchor === null
                ? [null, 'no_approved_anchor']
                : $this->freshApprovedSource($projectId, (string) $anchor->selected_artifact_id, [DesignImageStore::ANCHOR_TYPE], 'anchor');
        }

        $planned = $input['anchor_identity_sha256'] ?? null;

        if (! is_string($planned) || $planned === '') {
            return [null, 'planning_anchor_unknown'];
        }

        $anchor = $this->productionAnchor($projectId);

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

        $room = $role === 'anchor' ? $this->filmRoomPlate($projectId, $scene) : null;

        if ($room !== null && $room['room']) {
            return $room['plate'] === null
                ? [null, $room['why']]
                : [array_replace($room['plate'], ['role' => 'environment']), 'ok'];
        }

        if ($role === 'anchor' && $this->isPlaceShot($scene)) {
            [$plate, $plateWhy] = $this->approvedPlate($projectId, $scene);

            return $plate === null
                ? [null, $plateWhy === self::NO_PLATE ? 'environment_requirement_unreadable' : $plateWhy]
                : [array_replace($plate, ['role' => 'environment']), 'ok'];
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

            $found = match (true) {
                $candidate === null => [null, 'source_keyframe_not_approved'],
                $this->keyframeNeedsReview($candidate) !== null => [null, 'previous_keyframe_needs_review|'.$previous->title],
                default => $this->freshApprovedSource(
                    $projectId,
                    (string) $candidate->selected_artifact_id,
                    [DesignImageStore::SCENE_KEYFRAME_TYPE],
                    'source_keyframe',
                ),
            };

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

    /** @return array{0: list<array<string, mixed>>, 1: ?string} [$models, $error] */
    public function sceneKeyframeModels(): array
    {
        try {
            $models = app(MediaModelRegistry::class)->forTask(EnvironmentPlatePrompt::TASK);
        } catch (InvalidArgumentException $e) {
            Log::error('scene-keyframe: registry model hong', ['error' => $e->getMessage()]);

            return [[], 'environment_media_models_broken'];
        }

        return [array_values(array_filter(
            $models,
            static fn (array $entry): bool => $entry['provider'] === 'openai',
        )), null];
    }

    /**
     * @param  array<string, mixed>|null  $choice
     * @return array{0: ?array{provider_model: string, model: string, size: string, quality: string}, 1: string}
     */
    private function sceneKeyframeMedia(?array $choice): array
    {
        [$models, $error] = $this->sceneKeyframeModels();

        if ($models === []) {
            return [null, $error ?? 'media_invalid'];
        }

        $entry = $choice === null
            ? (collect($models)->firstWhere('default', true) ?? $models[0])
            : collect($models)->first(static fn (array $row): bool => $row['id'] === ($choice['provider_model'] ?? null));

        if ($entry === null) {
            return [null, 'media_invalid'];
        }

        $size = $choice['size'] ?? $entry['controls']['default_size'];
        $quality = $choice['quality'] ?? $entry['controls']['default_quality'];

        if (! in_array($size, $entry['controls']['sizes'], true) || ! in_array($quality, $entry['controls']['qualities'], true)) {
            return [null, 'media_invalid'];
        }

        return [[
            'provider_model' => (string) $entry['id'],
            'model' => (string) $entry['model'],
            'size' => (string) $size,
            'quality' => (string) $quality,
        ], 'ok'];
    }

    /**
     * @param  list<array<string, mixed>>  $manifest
     * @param  array{provider_model: string, model: string, size: string, quality: string}  $media
     * @return array<string, mixed>
     */
    private function sceneImageSpec(VideoRenderScene $scene, string $preservation, array $manifest, array $media): array
    {
        return [
            'operation' => 'scene_keyframe',
            'spec_version' => self::SCENE_SPEC_VERSION,
            'prompt' => ScenePreservationPrompt::forManifest(
                (string) $scene->transition_mode,
                array_column($manifest, 'role'),
                $preservation,
            )."\n\n".$this->keyframeDelta($scene),
            'provider_model' => $media['provider_model'],
            'model' => $media['model'],
            'quality' => $media['quality'],
            'size' => $media['size'],
            'variations' => 1,
            'pricing' => 'unpriced',
            'render_scene_id' => (string) $scene->id,
            'scene_code' => (string) $scene->scene_code,
            'revision' => (int) $scene->revision,
            'transition_mode' => (string) $scene->transition_mode,
            'preservation_version' => ScenePreservationPrompt::versionFor(array_column($manifest, 'role'), $preservation),
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
        ?array $media = null,
        ?int $choiceVersion = null,
    ): array {
        [$preservation] = $this->preservationForRevision($stage);

        if ($preservation === null) {
            return [null, 'preservation_unknown'];
        }

        if ($choiceVersion !== null && $choiceVersion !== $this->referenceChoice($scene)['version']) {
            return [null, 'reference_choice_stale'];
        }

        [$chosenMedia, $mediaWhy] = $this->sceneKeyframeMedia($media);

        if ($chosenMedia === null) {
            return [null, $mediaWhy];
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

        [$slots, $why] = $this->sceneManifestSlots($projectId, $scene, $stage, $this->approvedReferenceViews($projectId));

        if ($slots === null) {
            return [null, $why];
        }

        $gate = $this->shotReferenceGate($scene, $stage);

        if ($gate !== null) {
            return [null, $gate];
        }

        $manifest = array_map(fn (array $slot) => $this->manifestEntry($slot), $slots);
        $spec = $this->sceneImageSpec($scene, $preservation, $manifest, $chosenMedia);

        return [[
            'spec' => $spec,
            'hash' => $this->designImageStore->identityHash($spec, $this->sceneIdentityKeys($spec)),
            'manifest_hash' => $spec['reference_manifest_hash'],
            'slots' => $slots,
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
                ? [
                    $this->sourceRoleForMode((string) $scene->transition_mode),
                    ...((string) $scene->transition_mode === ScenePreservationPrompt::HARD_CUT ? ['environment'] : []),
                ]
                : ['identity', 'environment', 'geometry', 'space_geometry', 'continuity', 'design_reference'];

            if (! in_array($entry['role'], $allowed, true)) {
                return [null, 'candidate_snapshot_role_mismatch'];
            }
        }

        $roles = array_values(array_intersect(array_column($manifest, 'role'), self::SINGLE_ROLES));

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
            $this->designImageStore->identityHash($spec, $this->sceneIdentityKeys($spec)),
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

    /**
     * @return string|null why the shot's images do not meet its reference requirements, as "shot_references_not_ready|detail"; null when they do or the shot predates them
     */
    private function shotReferenceGate(VideoRenderScene $scene, ?VideoPlanningStage $stage): ?string
    {
        $state = is_array($scene->state_json) ? $scene->state_json : [];

        if (! in_array($stage?->input_json['scene_contract_version'] ?? null, ScenePlanAuthor::SHOT_CONTRACT_VERSIONS, true)
            || ($state['storyboard_shape'] ?? null) !== ScenePlanAuthor::SHOT_SHAPE
            || ($state['reference_requirements'] ?? []) === []) {
            return null;
        }

        $projectId = (string) $scene->project_id;
        $scenes = VideoRenderScene::query()
            ->where('project_id', $projectId)
            ->where('revision', $scene->revision)
            ->orderBy('scene_index')
            ->get()
            ->map(static fn (VideoRenderScene $row): array => [
                'id' => (string) $row->scene_code,
                'scene_id' => (string) $row->id,
                'shot_board' => ($row->state_json['storyboard_shape'] ?? null) === ScenePlanAuthor::SHOT_SHAPE,
                'reference_requirements' => array_values(array_filter((array) ($row->state_json['reference_requirements'] ?? []), 'is_array')),
                'reference_confirmations' => is_array($row->state_json[self::REFERENCE_CONFIRMATIONS_KEY] ?? null) ? $row->state_json[self::REFERENCE_CONFIRMATIONS_KEY] : [],
            ])
            ->all();
        $cell = $this->sceneSourceCell($projectId, $scene, $stage, $this->approvedReferenceViews($projectId));
        $shot = $this->referenceReadiness($projectId, (int) $scene->revision, $scenes, [(string) $scene->id => $cell])['shots'][(string) $scene->id] ?? null;

        if ($shot === null || $shot['ready']) {
            return null;
        }

        $open = array_map(
            static fn (array $need): string => $need['kind'].' '.$need['status'],
            array_values(array_filter($shot['needs'], static fn (array $need): bool => ! in_array($need['status'], ['available', 'not_applicable'], true))),
        );

        return 'shot_references_not_ready|'.$scene->scene_code.': '.($open === [] ? 'nguồn ảnh bị chặn' : implode(', ', $open));
    }

    public function clipSourcesNeedReview(VideoRenderScene $scene): ?string
    {
        $gate = $this->shotReferenceGate($scene, $this->stageStore->stageForProjectRevision(
            (string) $scene->project_id, PlanningStageName::SCENE_PLAN, (int) $scene->revision,
        ));

        if ($gate !== null) {
            return 'Ảnh nguồn của '.explode('|', $gate, 2)[1].' — chưa đạt yêu cầu ảnh của shot';
        }

        $successor = VideoRenderScene::query()
            ->where('project_id', $scene->project_id)
            ->where('revision', $scene->revision)
            ->where('source_scene_code', $scene->scene_code)
            ->where('transition_mode', ScenePreservationPrompt::CONTINUATION)
            ->where(fn ($query) => $scene->continuity_group === null
                ? $query->whereNull('continuity_group')
                : $query->where('continuity_group', $scene->continuity_group))
            ->orderBy('scene_index')
            ->first(['id', 'title']);

        foreach (array_filter([$scene, $successor]) as $row) {
            $keyframe = VideoDesignImage::query()
                ->where('render_scene_id', $row->id)
                ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
                ->where('status', DesignImageStatus::APPROVED->value)
                ->first();
            $reason = $keyframe === null ? null : $this->keyframeNeedsReview($keyframe);

            if ($reason !== null) {
                return (string) $row->title.' cần kiểm tra lại: nguồn '.$reason.' đã đổi';
            }
        }

        return null;
    }

    public function keyframeNeedsReview(VideoDesignImage $keyframe): ?string
    {
        $memo = [];

        return $this->keyframeReviewReason($keyframe, $memo);
    }

    /** @param array<string, ?string> $memo */
    private function keyframeReviewReason(VideoDesignImage $keyframe, array &$memo): ?string
    {
        $id = (string) $keyframe->id;

        if (array_key_exists($id, $memo)) {
            return $memo[$id];
        }

        $memo[$id] = null;
        $spec = is_array($keyframe->prompt_spec_json) ? $keyframe->prompt_spec_json : [];
        $sources = $spec['sources'] ?? null;

        if (! is_array($sources)) {
            return null;
        }

        $projectId = (string) $keyframe->project_id;
        $scene = VideoRenderScene::query()->whereKey($keyframe->render_scene_id)->first(['id', 'revision']);
        $design = null;

        foreach ($sources as $entry) {
            if (! is_array($entry) || ! is_string($entry['role'] ?? null) || ! is_string($entry['artifact_id'] ?? null)) {
                continue;
            }

            $label = $this->sourceLabel($entry);

            if ($this->sourceState($projectId, $entry) !== 'current') {
                return $memo[$id] = $label;
            }

            if ($entry['role'] === 'design_reference') {
                $design ??= array_column($this->designReferenceOptions($projectId), 'artifact_id');

                if (! in_array($entry['artifact_id'], $design, true)) {
                    return $memo[$id] = $label;
                }
            }

            if (! in_array($entry['role'], ['source_keyframe', 'continuity'], true)) {
                continue;
            }

            $source = VideoDesignImage::query()->whereKey($entry['candidate_id'] ?? null)->first();
            $sourceScene = $source === null ? null : VideoRenderScene::query()->whereKey($source->render_scene_id)->first(['id', 'revision']);

            if ($source === null || $sourceScene === null || $scene === null
                || (int) $sourceScene->revision !== (int) $scene->revision
                || $this->keyframeReviewReason($source, $memo) !== null) {
                return $memo[$id] = $label;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $entry */
    private function sourceLabel(array $entry): string
    {
        $candidate = VideoDesignImage::query()->whereKey($entry['candidate_id'] ?? null)->first(['id', 'render_scene_id']);
        $scene = $candidate?->render_scene_id === null
            ? null
            : VideoRenderScene::query()->whereKey($candidate->render_scene_id)->first(['title']);

        return $scene !== null ? (string) $scene->title : (string) $entry['role'];
    }

    /** @param array<string, mixed> $entry */
    private function sourceState(string $projectId, array $entry): string
    {
        if ($entry['role'] === 'anchor') {
            $anchor = $this->productionAnchor($projectId);

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
            'render_ready' => $blocked === null,
            'sources' => array_map(fn (array $entry): array => [
                'position' => $entry['position'],
                'role' => $entry['role'],
                'artifact_id' => $entry['artifact_id'],
                'sha' => substr($entry['sha256'], 0, 12),
                'state' => $this->sourceState($projectId, $entry),
                'group' => $built['slots'][$entry['position']]['group'] ?? ($entry['position'] === 0 ? 'primary' : 'extra'),
                'title' => (string) ($built['slots'][$entry['position']]['title'] ?? $entry['role']),
                'url' => route('video-artifacts.show', $entry['artifact_id']),
            ], $spec['sources']),
            'references' => $built['references'] ?? null,
        ], $blocked ?? 'ok'];
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    public function sceneImagePreview(
        string $projectId,
        ?string $actorId,
        string $sceneId,
        ?array $media = null,
    ): array {
        $scene = $this->ownedScene($projectId, $actorId, $sceneId);

        if ($scene === null) {
            return [null, 'scene_not_found'];
        }

        [$ok, $gate, $stage, $verified] = $this->sceneRenderGate($scene);

        if (! $ok) {
            return [null, $gate];
        }

        [$built, $why] = $this->buildSceneImageRequest($verified, $stage, null, false, $media);

        if ($built === null) {
            return [null, $why];
        }

        $approved = VideoDesignImage::query()
            ->where('render_scene_id', $verified->id)
            ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->first(['prompt_spec_json']);
        $built['references'] = $this->referencePanel($verified, $stage) + [
            'approved_differs' => $approved !== null
                && ($approved->prompt_spec_json['reference_manifest_hash'] ?? null) !== $built['manifest_hash'],
        ];

        return $this->sceneImageView($verified, $built, false);
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
        ?array $media = null,
        ?int $choiceVersion = null,
    ): array {
        try {
            return DB::transaction(function () use (
                $projectId, $actorId, $sceneId, $previewHash,
                $confirmAnchorArtifactId, $verifiedManifestHash, $media, $choiceVersion
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
                    $verified, $stage, $confirmAnchorArtifactId, true, $media, $choiceVersion,
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
        ?array $media = null,
        ?int $choiceVersion = null,
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
            $verified, $stage, $confirmAnchorArtifactId, true, $media, $choiceVersion,
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
            $confirmAnchorArtifactId, $built['manifest_hash'], $media, $choiceVersion,
        );

        return $candidate === null
            ? [null, $reason]
            : $this->dispatchSceneCandidate($candidate, false);
    }

    /**
     * @param  list<mixed>|null  $items  null returns the shot to the automatic suggestion; [] removes every extra image
     * @return array{0: ?int, 1: string} [$newVersion, $reason]
     */
    public function saveReferenceChoice(
        string $projectId,
        ?string $actorId,
        string $sceneId,
        int $expectedVersion,
        ?array $items,
    ): array {
        $scene = $this->ownedScene($projectId, $actorId, $sceneId);

        if ($scene === null) {
            return [null, 'scene_not_found'];
        }

        if ($this->filmRoomPlate($projectId, $scene)['room']) {
            return [null, 'room_uses_place_image'];
        }

        $clean = null;

        if ($items !== null) {
            if (! array_is_list($items) || count($items) > self::SCENE_MAX_SOURCE_IMAGES - 1) {
                return [null, 'references_over_limit'];
            }

            $stage = $this->stageStore->stageForProjectRevision($projectId, PlanningStageName::SCENE_PLAN, (int) $scene->revision);
            $basis = $this->manifestBasis($projectId, $scene, $stage, $this->approvedReferenceViews($projectId));
            $options = [];

            foreach ($this->referenceOptions($projectId, $scene, $basis['place'], $basis['identity'], $basis['views']) as $option) {
                $options[$option['artifact_id']] = $option;
            }

            $clean = [];
            $seen = [(string) ($basis['primary']['artifact_id'] ?? '') => true];
            $singles = [];

            foreach ($items as $item) {
                $artifactId = is_array($item) ? (string) ($item['artifact_id'] ?? '') : '';
                $role = is_array($item) ? (string) ($item['role'] ?? '') : '';
                $option = $options[$artifactId] ?? null;

                if ($option === null || ! in_array($role, $option['roles'], true)) {
                    return [null, 'reference_choice_invalid'];
                }

                if (array_key_exists($artifactId, $seen) || array_key_exists($role, $singles)) {
                    return [null, 'reference_choice_duplicated|'.$option['title']];
                }

                $seen[$artifactId] = true;

                if (in_array($role, self::SINGLE_ROLES, true)) {
                    $singles[$role] = true;
                }

                $clean[] = ['artifact_id' => $artifactId, 'role' => $role];
            }

            $probe = clone $scene;
            $probe->state_json = array_replace((array) $scene->state_json, [
                self::REFERENCE_CHOICE_KEY => ['version' => 0, 'items' => $clean],
            ]);

            if ($this->sceneManifestSlots($projectId, $probe, $stage, $this->approvedReferenceViews($projectId))[1] === 'references_over_limit') {
                return [null, 'references_over_limit'];
            }
        }

        return DB::transaction(function () use ($scene, $expectedVersion, $clean): array {
            $row = VideoRenderScene::query()->whereKey($scene->id)->lockForUpdate()->first();
            $current = $row === null ? null : $this->referenceChoice($row)['version'];

            if ($current === null) {
                return [null, 'scene_not_found'];
            }

            if ($current !== $expectedVersion) {
                return [null, 'reference_choice_conflict'];
            }

            DB::update(
                "UPDATE video_render_scenes SET state_json = JSON_SET(state_json, '$.".self::REFERENCE_CHOICE_KEY."', JSON_EXTRACT(?, '$')), updated_at = ? WHERE id = ?",
                [json_encode(['version' => $current + 1, 'items' => $clean], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), now(), $row->id],
            );

            return [$current + 1, 'ok'];
        });
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

        [$ok, $gate, $stage, $verified] = $this->sceneRenderGate($scene);

        if (! $ok) {
            return [null, $gate];
        }

        $references = $this->shotReferenceGate($verified, $stage);

        if ($references !== null) {
            return [null, $references];
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

    /**
     * @return array{encoding: string, text: string}
     */
    private function storableText(string $value): array
    {
        return mb_check_encoding($value, 'UTF-8')
            ? ['encoding' => 'utf8', 'text' => $value]
            : ['encoding' => 'base64', 'text' => base64_encode($value)];
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
     * @param  list<array<string, mixed>>  $shots
     * @return list<string>
     */
    private function unresolvedShotCodes(array $shots): array
    {
        $codes = [];

        foreach ($shots as $shot) {
            if (is_array($shot)
                && is_string($shot['purpose'] ?? null)
                && str_starts_with(trim($shot['purpose']), self::UNRESOLVED_MARK)) {
                $codes[] = (string) ($shot['scene_code'] ?? '');
            }
        }

        return $codes;
    }

    /**
     * @param  array<string, mixed>  $shot
     * @param  array<string, mixed>  $sourceScene
     */
    private function checkShotStates(string $at, array $shot, array $sourceScene): void
    {
        $shown = is_array($sourceScene['subject_state'] ?? null);
        $parts = \App\Video\Screenplay\SceneBeats::partNames($sourceScene);
        $validator = new \App\Video\Screenplay\ScreenplayValidator;

        foreach (['keyframe_state', 'end_state'] as $field) {
            $moment = $shot[$field] ?? null;
            $violations = $validator->subjectMomentViolations($at.'.'.$field, $moment, $shown);

            if ($violations !== []) {
                throw new ScenePlanException(implode('; ', $violations));
            }

            if (! $shown && (($moment['progress'] ?? null) !== null || $moment['configuration'] !== [])) {
                throw new ScenePlanException(
                    $at.'.'.$field.': '.($sourceScene['id'] ?? '?').' does not show the subject, so progress is null and configuration is empty.'
                );
            }

            foreach ($moment['configuration'] as $item) {
                if (! in_array(trim((string) $item['part']), $parts, true)) {
                    throw new ScenePlanException(
                        $at.'.'.$field.': part "'.$item['part'].'" is not named in '.($sourceScene['id'] ?? '?').'.subject_state.'
                    );
                }
            }
        }
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
        $filmCut = FilmCutAuthor::isFilmShape($state['film_cut_shape'] ?? null);

        return [
            'film_cut' => $filmCut,
            'film' => ($state['film_cut_shape'] ?? null) === FilmCutAuthor::SHAPE && is_array($state['film'] ?? null) ? $state['film'] : null,
            'id' => (string) $scene->scene_code,
            'scene_id' => (string) $scene->id,
            'title' => (string) $scene->title,
            'phase' => (string) $scene->scene_type,
            'purpose' => (string) $scene->purpose,
            'transition_mode' => $mode,
            'delta' => (string) $scene->delta_prompt,
            'image_prompt' => $preservation === null
                ? null
                : ScenePreservationPrompt::forMode($mode, $preservation)."\n\n".$this->keyframeDelta($scene),
            'milestones' => array_map(
                static fn (string $key) => $profile?->labelOf($key) ?? $key,
                array_values(array_filter($keys, 'is_string')),
            ),
            'coverage' => array_values(array_filter((array) ($state['coverage_ids'] ?? []), 'is_string')),
            'basis' => is_string($scene->basis) ? $scene->basis : null,
            'continuity_group' => is_string($scene->continuity_group) ? $scene->continuity_group : null,
            'source_scene_code' => is_string($scene->source_scene_code) ? $scene->source_scene_code : null,
            'camera_change_reason' => is_string($scene->camera_change_reason)
                ? $scene->camera_change_reason
                : null,
            'state_before' => $state['state_before'] ?? null,
            'scene_state' => $state['scene_state'] ?? null,
            'location_id' => $state['location_id'] ?? null,
            'character_ids' => array_values((array) ($state['character_ids'] ?? [])),
            'end_state' => $video['end_state'] ?? null,
            'video_prompt' => $video === null || ! is_string($video['action'] ?? null) ? null : $this->videoPrompt($video, $this->sceneSetting($scene), $this->shotMoments($scene)),
            'screenplay_scene_code' => is_string($scene->screenplay_scene_code) ? $scene->screenplay_scene_code : null,
            'shot_index' => (int) $scene->shot_index,
            'beat_ids' => array_values(array_filter((array) ($state['beat_ids'] ?? []), 'is_string')),
            'keyframe_state' => is_array($state['keyframe_state'] ?? null) ? $state['keyframe_state'] : null,
            'shot_end_state' => is_array($state['end_state'] ?? null) ? $state['end_state'] : null,
            'camera' => $this->cameraLines(is_array($video['camera'] ?? null) || is_string($video['camera'] ?? null) ? $video['camera'] : []),
            'storyboard' => is_array($video) && array_key_exists('camera', $video),
            'shot_board' => ($state['storyboard_shape'] ?? null) === ScenePlanAuthor::SHOT_SHAPE,
            'camera_relation' => is_string($video['camera_relation'] ?? null) ? $video['camera_relation'] : null,
            'duration_seconds' => is_int($video['duration_ms'] ?? null) && $video['duration_ms'] % 1000 === 0
                ? intdiv($video['duration_ms'], 1000)
                : null,
            'used_start_ms' => is_int($video['used_start_ms'] ?? null) ? $video['used_start_ms'] : null,
            'used_ms' => is_int($video['used_ms'] ?? null) ? $video['used_ms'] : null,
            'result_at_ms' => is_int($video['result_at_ms'] ?? null) ? $video['result_at_ms'] : null,
            'timeline' => is_array($state['timeline'] ?? null) ? $state['timeline'] : null,
            'camera_movement' => is_array($video['camera']['movement'] ?? null) ? (string) ($video['camera']['movement']['type'] ?? '') : null,
            'beat_coverage' => array_values(array_filter((array) ($state['beat_coverage'] ?? []), 'is_array')),
            'objects_start' => $this->visibleObjects($state, 'objects_start', ['objects_first_frame']),
            'objects_end' => $this->visibleObjects($state, 'objects_end', ['objects_last_frame']),
            'object_moves' => $this->framedObjectMoves($state),
            'reference_requirements' => array_values(array_filter((array) ($state['reference_requirements'] ?? []), 'is_array')),
            'reference_confirmations' => is_array($state[self::REFERENCE_CONFIRMATIONS_KEY] ?? null) ? $state[self::REFERENCE_CONFIRMATIONS_KEY] : [],
            'after_jump' => is_array($state[self::AFTER_JUMP_KEY] ?? null) ? $state[self::AFTER_JUMP_KEY] : null,
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
            && ! in_array($round['verdict'], self::STORED_REVIEW_VERDICTS, true)) {
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

            if (! in_array($finding['rule'], self::STORED_REVIEW_RULES, true)
                || ! in_array($finding['severity'], self::STORED_REVIEW_SEVERITIES, true)) {
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

        if (in_array(
            is_array($stage->input_json) ? $stage->input_json['scene_contract_version'] ?? null : null,
            ScenePlanAuthor::VALIDATED_CONTRACT_VERSIONS,
            true,
        )) {
            $validation = $stage->output_json['validation'] ?? null;
            $hash = is_array($validation) ? $validation['plan_sha256'] ?? null : null;

            if (($validation['status'] ?? null) !== 'passed'
                || ! is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                return $unreviewed('not_validated');
            }

            return [
                'status' => 'passed',
                'reason' => 'validated',
                'reviewed_plan_sha256' => $hash,
                'open_findings' => [],
                'patched_but_unverified' => false,
                'rounds' => [],
                'usage' => $this->readableUsage($stage->output_json['usage'] ?? null),
            ];
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

    /**
     * @param  array<string, mixed>  $video
     * @param  array<string, mixed>  $setting
     */
    public function videoPrompt(array $video, array $setting = [], array $moments = []): string
    {
        $start = \App\Video\Screenplay\SceneBeats::stateLines((array) ($moments['keyframe_state'] ?? []), 'STARTS WITH');
        $end = \App\Video\Screenplay\SceneBeats::stateLines((array) ($moments['end_state'] ?? []), 'ENDS WITH');
        $changed = $this->changedObjectLines(
            (array) ($moments['objects_start'] ?? []),
            (array) ($moments['objects_end'] ?? []),
            (array) ($moments['object_moves']['leaving_ids'] ?? []),
        );
        $entering = (array) ($moments['object_moves']['entering'] ?? []);
        $leaving = (array) ($moments['object_moves']['leaving'] ?? []);

        return implode("\n\n", [
            'The supplied image is the first frame of this shot and is already correct; the shot begins from exactly that state.',
            ...($start === [] ? [] : [implode("\n", $start)]),
            'ACTION: '.$video['action'],
            ...(is_int($video['result_at_ms'] ?? null) && is_int($video['duration_ms'] ?? null) ? [
                'TIMING: the action reaches its result by '.($video['result_at_ms'] / 1000).' s of this '.($video['duration_ms'] / 1000).' s clip.',
            ] : []),
            'CAMERA: '.$this->clipCamera($video),
            'PRESERVE: '.$video['preserve'],
            ...(array_key_exists('camera', $video) ? [] : ['END STATE: '.$video['end_state']]),
            ...($end === [] ? [] : [implode("\n", $end)]),
            ...($entering === [] ? [] : ['ENTERS THE FRAME DURING THE CLIP: '.implode('; ', $entering)]),
            ...($leaving === [] ? [] : ['LEAVES THE FRAME DURING THE CLIP: '.implode('; ', $leaving)]),
            ...($changed === [] ? [] : ["OBJECTS WHEN THE CLIP ENDS:\n".implode("\n", $changed)]),
            ...\App\Video\Screenplay\LocationProfile::settingLines($setting),
        ]);
    }

    /** @return array<string, mixed> */
    private function shotMoments(VideoRenderScene $scene): array
    {
        $state = is_array($scene->state_json) ? $scene->state_json : [];

        return array_intersect_key($state, array_flip(['keyframe_state', 'end_state'])) + [
            'objects_start' => $this->visibleObjects($state, 'objects_start', ['objects_first_frame']),
            'objects_end' => $this->visibleObjects($state, 'objects_end', ['objects_first_frame', 'objects_last_frame']),
            'object_moves' => $this->framedObjectMoves($state),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<string>  $frames
     * @return list<array<string, mixed>>
     */
    private function visibleObjects(array $state, string $key, array $frames): array
    {
        $objects = array_values(array_filter((array) ($state[$key] ?? []), 'is_array'));
        $listed = array_values(array_filter($frames, static fn (string $frame): bool => array_key_exists($frame, $state)));
        $ids = $listed === []
            ? ($state['objects_visible'] ?? null)
            : array_merge(...array_map(static fn (string $frame): array => (array) $state[$frame], $listed));

        if ($ids === null) {
            return $objects;
        }

        $visible = array_values(array_filter((array) $ids, 'is_string'));

        return array_values(array_filter(
            $objects,
            static fn (array $object): bool => in_array($object['object_id'] ?? null, $visible, true),
        ));
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{entering: list<string>, leaving: list<string>, leaving_ids: list<string>}
     */
    private function framedObjectMoves(array $state): array
    {
        $first = $this->objectNames($this->visibleObjects($state, 'objects_start', ['objects_first_frame']));
        $last = $this->objectNames($this->visibleObjects($state, 'objects_end', ['objects_last_frame']));
        $leaving = array_diff_key($first, $last);

        return [
            'entering' => array_values(array_diff_key($last, $first)),
            'leaving' => array_values($leaving),
            'leaving_ids' => array_map('strval', array_keys($leaving)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $objects
     * @return array<string, string>
     */
    private function objectNames(array $objects): array
    {
        $names = [];

        foreach ($objects as $object) {
            if (is_string($object['object_id'] ?? null) && is_string($object['name'] ?? null)) {
                $names[$object['object_id']] = $object['name'];
            }
        }

        return $names;
    }

    /**
     * @param  array<mixed>  $objects
     * @return list<string>
     */
    private function objectLines(array $objects): array
    {
        $lines = [];

        foreach ($objects as $object) {
            if (is_array($object) && is_string($object['name'] ?? null) && is_string($object['state'] ?? null)) {
                $lines[] = '- '.$object['name'].': '.$object['state'];
            }
        }

        return $lines;
    }

    /**
     * @param  array<mixed>  $start
     * @param  array<mixed>  $end
     * @param  list<string>  $leavingIds
     * @return list<string>
     */
    private function changedObjectLines(array $start, array $end, array $leavingIds = []): array
    {
        $before = [];

        foreach ($start as $object) {
            if (is_array($object) && is_string($object['object_id'] ?? null)) {
                $before[$object['object_id']] = $object['state'] ?? null;
            }
        }

        $changed = array_values(array_filter(
            $end,
            static fn (mixed $object): bool => is_array($object)
                && ($before[$object['object_id'] ?? ''] ?? null) !== ($object['state'] ?? null),
        ));

        return $this->objectLines(array_map(
            static fn (array $object): array => in_array($object['object_id'] ?? null, $leavingIds, true)
                ? array_replace($object, ['state' => $object['state'].' (reached before it leaves the frame)'])
                : $object,
            $changed,
        ));
    }

    /** @return array<string, mixed> */
    private function sceneSetting(VideoRenderScene $scene): array
    {
        $state = is_array($scene->state_json) ? $scene->state_json : [];

        return is_array($state['setting'] ?? null) ? $state['setting'] : [];
    }

    private function keyframeDelta(VideoRenderScene $scene): string
    {
        $state = is_array($scene->state_json) ? $scene->state_json : [];
        $space = \App\Video\Screenplay\LocationProfile::spaceLines(is_array($state['space'] ?? null) ? $state['space'] : []);
        $setting = \App\Video\Screenplay\LocationProfile::settingLines($this->sceneSetting($scene));
        $frame = \App\Video\Screenplay\SceneBeats::stateLines(
            is_array($state['keyframe_state'] ?? null) ? $state['keyframe_state'] : [], 'FRAME',
        );
        $video = is_array($scene->video_plan_json) ? $scene->video_plan_json : [];
        $camera = $this->cameraLines(is_array($video['camera'] ?? null) || is_string($video['camera'] ?? null) ? $video['camera'] : []);
        $objects = $this->objectLines($this->visibleObjects($state, 'objects_start', ['objects_first_frame']));
        $room = \App\Video\Screenplay\SceneBeats::roomLine($state[\App\Video\Screenplay\SceneBeats::ROOM_STATE_KEY]['start'] ?? null);
        $jump = is_array($state[self::AFTER_JUMP_KEY] ?? null) ? $state[self::AFTER_JUMP_KEY] : null;

        return implode("\n\n", [
            (string) $scene->delta_prompt,
            ...($jump === null ? [] : ['THIS FRAME IS THE LATER MOMENT AFTER THE TIME JUMP: '.$jump['evidence']]),
            ...($camera === [] ? [] : [self::CAMERA_LEAD."\n".implode("\n", $camera)]),
            ...($frame === [] ? [] : [self::FRAME_LEAD."\n".implode("\n", $frame)]),
            ...($objects === [] ? [] : [self::OBJECTS_LEAD."\n".implode("\n", $objects)]),
            ...($room === null ? [] : [$room]),
            ...($space === [] ? [] : [($this->filmRoomPlate((string) $scene->project_id, $scene)['room'] ? self::ROOM_SPACE_LEAD : self::SPACE_LEAD)."\n".implode("\n", $space)]),
            ...($setting === [] ? [] : [self::SETTING_LEAD."\n".implode("\n", $setting)]),
        ]);
    }

    /**
     * @param  string|array<string, mixed>  $camera
     * @return list<string>
     */
    private function cameraLines(string|array $camera): array
    {
        if (is_string($camera)) {
            return trim($camera) === '' ? [] : ['Camera: '.trim($camera)];
        }

        $lines = [];

        foreach (['position' => 'Position', 'elevation' => 'Height', 'framing' => 'Framing'] as $field => $label) {
            if (is_string($camera[$field] ?? null) && trim($camera[$field]) !== '') {
                $lines[] = $label.': '.trim($camera[$field]);
            }
        }

        if (is_string($camera['subject_side'] ?? null)) {
            $lines[] = 'Side of the subject facing the camera: '.$camera['subject_side'];
        }

        foreach (['shot_size' => 'Shot size', 'angle' => 'Angle', 'lens' => 'Lens'] as $field => $label) {
            if (is_string($camera[$field] ?? null)) {
                $lines[] = $label.': '.str_replace('_', ' ', $camera[$field]);
            }
        }

        if (is_string($camera['facing'] ?? null)) {
            $lines[] = 'Looking toward: '.$camera['facing'];
        }

        $composition = is_array($camera['composition'] ?? null) ? $camera['composition'] : [];

        foreach (['must_show' => 'The frame must show', 'foreground' => 'Foreground', 'background' => 'Background', 'motion_room' => 'Room left for the movement'] as $field => $label) {
            if (is_string($composition[$field] ?? null)) {
                $lines[] = $label.': '.$composition[$field];
            }
        }

        $movement = is_array($camera['movement'] ?? null) ? $camera['movement'] : [];

        if (($movement['type'] ?? ScenePlanAuthor::STATIC_MOVEMENT) !== ScenePlanAuthor::STATIC_MOVEMENT && is_string($movement['start'] ?? null)) {
            $lines[] = 'This frame is where the camera starts its '.str_replace('_', ' ', (string) $movement['type']).': '.$movement['start'];
        }

        return $lines;
    }

    /** @param  array<string, mixed>  $video */
    private function clipCamera(array $video): string
    {
        $movement = is_array($video['camera']['movement'] ?? null) ? $video['camera']['movement'] : [];

        if (($movement['type'] ?? ScenePlanAuthor::STATIC_MOVEMENT) === ScenePlanAuthor::STATIC_MOVEMENT
            || ! is_string($movement['start'] ?? null) || ! is_string($movement['end'] ?? null)) {
            return self::LOCKED_CAMERA;
        }

        return 'The camera makes one smooth '.str_replace('_', ' ', (string) $movement['type']).' from where the supplied frame was taken. Starts: '
            .$movement['start'].' Ends: '.$movement['end'];
    }

    /**
     * @return array{subject_id: ?string, name: string, layout: string, fixed_features: list<string>,
     *               connections: list<array{to: string, via: string}>, light_sources: list<string>}|null
     */
    private function spaceOfScene(VideoPlanningStage $screenplayStage, string $locationId): ?array
    {
        $locations = array_values((array) ($screenplayStage->output_json['locations'] ?? []));
        $location = collect($locations)->firstWhere('id', $locationId);

        return is_array($location) ? \App\Video\Screenplay\LocationProfile::spaceOf($location, $locations) : null;
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
