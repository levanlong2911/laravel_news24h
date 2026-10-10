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
use App\Services\Video\CreativeProfileResolver;
use App\Services\Video\DesignImageDirectRenderer;
use App\Services\Video\DesignImageQueue;
use App\Services\Video\DesignImageStore;
use App\Services\Video\FinalCompositionReconciler;
use App\Services\Video\InspirationStageRunner;
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
use App\Video\Concept\Canonical\Enums\ProvenanceOrigin;
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
use App\Video\Inspiration\CategoryCreativeProfile as InspirationProfile;
use App\Video\Profiles\CategoryCreativeProfileResolver as CanonicalProfileResolver;
use App\Video\Reference\ReferenceEnvironment;
use App\Video\Reference\ReferenceView;
use App\Video\Render\Video\SceneClipDispatchService;
use App\Video\Scene\ScenePlanAuthor;
use App\Video\Scene\ScenePlanException;
use App\Video\Scene\ScenePreservationPrompt;
use App\Video\Scene\SceneProfile;
use App\Video\Scene\Services\ShotSelectionReconciler;
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

    private const SCENE_IMAGE_MODEL = ImageModel::GPT_IMAGE_2;

    private const SCENE_IMAGE_SIZE = ImageSize::VERTICAL_2K;

    private const SCENE_IMAGE_QUALITY = ImageQuality::LOW;

    private const SCENE_SPEC_VERSION = 'scene-keyframe-v2';

    private const EXTERIOR_SOURCE = 'exterior';

    private const SETTING_LEAD = 'SCENE SETTING (from the screenplay; this light and weather override the light of any reference image):';

    private const SPACE_LEAD = 'THIS SPACE IS PART OF THE SUBJECT. Its structure comes from the subject image confirmed as its geometry source; '
        .'this text only says which part of the subject the frame shows and never adds structure that image contradicts:';

    private const NO_PLATE = 'subject_part_without_plate';

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
        'reference_requirements',
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

    private DesignImageStore $designImageStore;

    private DesignImageQueue $designImageQueue;

    private DesignImageDirectRenderer $designImageDirectRenderer;

    private VideoRenderPlanService $renderPlanService;

    private VisualIdentityStore $identityStore;

    private CanonicalConceptInputBuilder $canonicalConceptInputBuilder;

    private CreativeProfileResolver $creativeProfileResolver;

    private CanonicalProfileResolver $canonicalProfileResolver;

    private GeometryPromptAuthor $geometryPromptAuthor;

    private ScreenplayApprovalService $screenplayApprovalService;

    private ProductionSelectionService $productionSelectionService;

    private ScreenplayExpansionService $screenplayExpansion;

    public function __construct(
        VideoProjectRepositoryInterface $videoProjectRepository,
        ArticleService $articleService,
        PlanningStageStore $stageStore,
        InspirationStageRunner $inspirationRunner,
        VideoRenderPlanService $renderPlanService,
        DesignImageStore $designImageStore,
        DesignImageQueue $designImageQueue,
        DesignImageDirectRenderer $designImageDirectRenderer,
        VisualIdentityStore $identityStore,
        CanonicalConceptInputBuilder $canonicalConceptInputBuilder,
        CreativeProfileResolver $creativeProfileResolver,
        CanonicalProfileResolver $canonicalProfileResolver,
        GeometryPromptAuthor $geometryPromptAuthor,
        ScreenplayApprovalService $screenplayApprovalService,
        ProductionSelectionService $productionSelectionService,
        ScreenplayExpansionService $screenplayExpansion,
    ) {
        $this->videoProjectRepository = $videoProjectRepository;
        $this->articleService = $articleService;
        $this->stageStore = $stageStore;
        $this->inspirationRunner = $inspirationRunner;
        $this->renderPlanService = $renderPlanService;
        $this->designImageStore = $designImageStore;
        $this->designImageQueue = $designImageQueue;
        $this->designImageDirectRenderer = $designImageDirectRenderer;
        $this->identityStore = $identityStore;
        $this->canonicalConceptInputBuilder = $canonicalConceptInputBuilder;
        $this->creativeProfileResolver = $creativeProfileResolver;
        $this->canonicalProfileResolver = $canonicalProfileResolver;
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

        $category = (string) ($project->article->category?->slug ?? '');

        try {
            $profile = $this->inspirationProfile($project);

            if ($profile === null) {
                return [null, "Category {$category} chua co creative profile"];
            }

            $input = $this->inspirationInput($project, $profile);

            [$stage, $token, $reason] = $this->stageStore->claimProjectStage(
                $project->id,
                PlanningStageName::INSPIRATION,
                $input,
            );
        } catch (\Throwable $e) {
            Log::error('canonical-inspiration: khong mo duoc luot', [
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
            $post = $this->rawArticleFromModel($project->article);
            $result = $this->canonicalConceptInputBuilder->buildInspiration($post, $profile);


            $output = $this->renderPlanService->briefForStorage($result->brief, $project->article);
            $empty = $this->renderPlanService->briefEmptiness($output);

            if ($empty !== null) {
                return $this->failInspirationStage(
                    $stage->id,
                    $token,
                    $empty,
                    $result->rawResponse,
                    $result->usage,
                );
            }

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
            Log::error('canonical-inspiration: that bai', [
                'stage_id' => $stage->id,
                'article_id' => $project->article->id,
                'exception' => $e,
            ]);

            $fromBrief = $e instanceof \App\Video\Inspiration\InvalidInspirationBrief;

            return $this->failInspirationStage(
                $stage->id,
                $token,
                $e->getMessage(),
                $result?->rawResponse ?? ($fromBrief ? $e->rawResponse : ''),
                $result?->usage ?? ($fromBrief ? $e->usage : []),
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

        try {
            $input = $this->inspirationInput($project, $this->inspirationProfile($project));
        } catch (\Throwable $e) {
            $this->quietLog('canonical-inspiration: profile unreadable', $e, ['project_id' => $project->id]);

            return $this->emptyInspiration('Creative profile cua category nay cau hinh loi — xem log.');
        }

        [$latest, $matchesInput] = $this->stageStore->latestStageForProject(
            $project->id,
            PlanningStageName::INSPIRATION,
            $input,
            skipOrphans: true,
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
        \App\Video\Inspiration\InspirationResult $result,
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

    // /** @return array{0: ?array<string, mixed>, 1: string} */
    // public function runConcept(string $projectId, bool $force = false): array
    // {
    //     // dd($this->runCanonicalConcept($projectId, $force));
    //     return $this->runCanonicalConcept($projectId, $force);
    // }

    // /** @return array{0: ?array<string, mixed>, 1: string} */
    // private function runCanonicalConcept(string $projectId, bool $force): array
    // {
    //     $project = $this->videoProjectRepository->getById($projectId);

    //     if ($project?->article === null) {
    //         return [null, 'Khong tim thay bai viet cua du an'];
    //     }

    //     $category = (string) ($project->article->category?->slug ?? '');

    //     if ($category === '') {
    //         return [null, 'Bai viet chua co category'];
    //     }

    //     $inspirationProfile = $this->creativeProfileResolver->resolve($category);

    //     if ($inspirationProfile === null) {
    //         return [null, "Category {$category} chua co creative profile"];
    //     }

    //     $dataInput = $this->canonicalConceptStageInput($project, $category);

    //     [$stage, $token, $reason] = $this->stageStore->claimProjectStage(
    //         $project->id,
    //         PlanningStageName::CONCEPT,
    //         $dataInput,
    //         $force,
    //     );
    //     if ($reason === 'already_succeeded') {
    //         return [$stage->output_json ?? [], 'cached'];
    //     }

    //     if ($token === null) {
    //         return [null, 'Dang co mot luot dung concept chay cho du an nay'];
    //     }

    //     try {
    //         $storedInspiration = $this->stageStore->latestOutputForProject(
    //             $project->id,
    //             PlanningStageName::INSPIRATION,
    //         );

    //         if ($storedInspiration === null) {
    //             throw new \RuntimeException(
    //                 'Chua co inspiration trong DB. Hay bam Goi Haiku truoc.'
    //             );
    //         }

    //         $input = $this->canonicalConceptInputBuilder->fromBrief(
    //             objectType: $category,
    //             brief: $this->renderPlanService->briefFromStorage($storedInspiration),
    //             canonicalProfile: $this->canonicalProfileResolver->resolve($category),
    //         );

    //         $revision = $this->canonicalConceptExecutionService->create(
    //             projectId: $project->id,
    //             sessionId: null,
    //             input: $input,
    //         );

    //         $persisted = $this->canonicalConceptExecutionService->execute(
    //             revision: $revision,
    //             input: $input,
    //         );

    //         $output = $persisted->frozen->spec->toArray();
    //         $rawOutput = $persisted->frozen->canonicalJson;

    //         $recorded = $this->stageStore->finishSucceeded(
    //             $stage->id,
    //             $token,
    //             $rawOutput,
    //             $output,
    //             [
    //                 'model' => $this->conceptProvider(),
    //                 'provider_model' => $this->conceptModel(),
    //                 'instruction_version' => (string) config('canonical_concept.prompt_version', 'concept-v1'),
    //                 'tokens_in' => 0,
    //                 'tokens_out' => 0,
    //                 'thinking_tokens' => 0,
    //                 'cost_usd' => 0,
    //             ],
    //         );

    //         if (! $recorded) {
    //             Log::warning('canonical-concept: claim lost, paid result not recorded', [
    //                 'project_id' => $project->id,
    //                 'stage_id' => $stage->id,
    //                 'provider_model' => $this->conceptModel(),
    //             ]);
    //         }

    //         if ($this->identityStore->freezeFromConcept($project->id, $output) === null) {
    //             Log::warning('canonical-concept: visual identity freeze skipped', [
    //                 'project_id' => $project->id,
    //                 'stage_id' => $stage->id,
    //             ]);
    //         }

    //         return [$output, 'ok'];
    //     } catch (\Throwable $e) {
    //         $this->stageStore->finishFailed(
    //             $stage->id,
    //             $token,
    //             $e->getMessage(),
    //         );

    //         Log::error('canonical-concept: concept stage failed', [
    //             'project_id' => $project->id,
    //             'exception' => $e,
    //         ]);

    //         return [null, $e->getMessage()];
    //     }
    // }

    // /** @return array<string, mixed> */
    // public function latestConcept(string $projectId): array
    // {
    //     $project = $this->videoProjectRepository->getById($projectId);

    //     if ($project === null) {
    //         return $this->emptyConcept('Khong tim thay du an');
    //     }

    //     if ($project->article === null) {
    //         return $this->emptyConcept('Du an nay khong gan voi bai viet nao');
    //     }

    //     $category = (string) ($project->article->category?->slug ?? '');

    //     // Duong canonical dung tu BAI VIET chu khong tu brief da luu — nen dieu
    //     // kien can la category, khong phai inspiration da chay xong.
    //     if ($category === '') {
    //         return $this->emptyConcept('Bai viet chua co category');
    //     }

    //     $conceptInput = $this->canonicalConceptStageInput($project, $category);

    //     [$latest, $matchesInput] = $this->stageStore->latestStageForProject(
    //         $project->id,
    //         PlanningStageName::CONCEPT,
    //         $conceptInput,
    //     );

    //     if ($latest === null) {
    //         return $this->emptyConcept();
    //     }

    //     $hasCachedSuccess = $this->stageStore->hasSucceededForProject(
    //         $project->id,
    //         PlanningStageName::CONCEPT,
    //         $conceptInput,
    //     );

    //     $succeeded = $latest->status === VideoPlanningStageStatus::SUCCEEDED->value;
    //     $claimed = $latest->status === VideoPlanningStageStatus::RUNNING->value;

    //     $output = $succeeded ? ($latest->output_json ?? []) : [];
    //     $canonical = $this->isCanonicalDesignSpec($output);
    //     $decisions = $canonical
    //         ? $this->legacyDecisions($output['provenance'] ?? [])
    //         : ($output['decisions'] ?? []);

    //     return [
    //         'analysed' => $succeeded,
    //         'status' => $latest->status,
    //         'running' => $claimed && $latest->lease_expires_at?->isFuture() === true,
    //         'stuck' => $claimed && $latest->lease_expires_at?->isFuture() !== true,
    //         'error' => $latest->error_message,
    //         'can_run' => ! $matchesInput || ! $hasCachedSuccess,
    //         'thesis' => $canonical
    //             ? ($output['design_thesis']['text'] ?? null)
    //             : ($output['design_thesis'] ?? null),
    //         'identity' => $canonical
    //             ? $this->displayIdentityFromCanonical($output)
    //             : ($output['design_identity'] ?? []),
    //         'relationships' => $output['form_relationships'] ?? [],
    //         'features' => $output['signature_features'] ?? [],
    //         'decisions' => $decisions,
    //         'json' => $output,
    //         // Duoi Phan 1, concept CHINH LA DesignSpec — khong con buoc xuat.
    //         // Ban ghi cu (truoc canonical) khong dung lai duoc nua: tra rong
    //         // chu khong doan.
    //         'design_spec' => $canonical ? $output : [],
    //         'meta' => $succeeded ? [
    //             'model' => $latest->model,
    //             'instruction_version' => $latest->instruction_version,
    //             'tokens_in' => $latest->tokens_in,
    //             'tokens_out' => $latest->tokens_out,
    //             'cost_usd' => $latest->cost_usd,
    //             'finished_at' => $latest->finished_at,
    //         ] : [],
    //         'provenance_summary' => $this->provenanceSummary($decisions),
    //         'frozen_at' => $succeeded ? $latest->finished_at : null,
    //     ];
    // }

    // /**
    //  * @param  list<array<string, mixed>>  $decisions
    //  * @return array<string, int>|null
    //  */
    // private function provenanceSummary(array $decisions): ?array
    // {
    //     if ($decisions === []) {
    //         return null;
    //     }

    //     $counts = [ProvenanceOrigin::INSPIRED->value => 0, ProvenanceOrigin::INVENTED->value => 0];

    //     foreach ($decisions as $decision) {
    //         $value = (string) ($decision['provenance'] ?? '');

    //         if (array_key_exists($value, $counts)) {
    //             $counts[$value]++;
    //         }
    //     }

    //     return [
    //         'total' => count($decisions),
    //         'inspired' => $counts[ProvenanceOrigin::INSPIRED->value],
    //         'invented' => $counts[ProvenanceOrigin::INVENTED->value],
    //     ];
    // }

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
        ?string $characterId = null,
    ): array {
        $preview = $this->anchorPromptPreview($projectId, $characterId);

        if ($preview === null) {
            return [null, 'anchor_prompt_missing'];
        }

        if ($preview['prompt_sha256'] !== $promptHash) {
            return [null, 'anchor_prompt_stale'];
        }

        $subjectKey = null;
        $screenplayStageId = null;
        $source = [];
        $designFirst = VesselDesign::isDesignFirst(VideoProject::query()->find($projectId));

        if ($characterId !== null && $designFirst) {
            [$designStage, $designReason] = app(CharacterAnchorPromptService::class)->designPromptCheck(
                $projectId, $preview + ['prompt_size' => $preview['size']], $size,
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
                'prompt_sha256' => $preview['prompt_sha256'],
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

        $clipCells = $revision === 0 ? [] : $this->sceneClipCells($projectId, $revision);
        $keyframeCells = $revision === 0 ? [] : $this->sceneKeyframeCells($projectId, $revision);

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

            $artifact = $keyframeCells[$key]['approved']['artifacts'][0] ?? null;

            $clips[] = [
                'ordinal' => count($clips) + 1,
                'scene_code' => (string) ($scene['id'] ?? ''),
                'title' => (string) ($scene['title'] ?? ''),
                'render_id' => (string) $cell['selected_render_id'],
                'duration_ms' => (int) ($cell['selected_duration_ms'] ?? 0),
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

        return [
            'revision' => $revision,
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
            $entry = [
                // Danh tinh clip nam TRONG manifest, tuc la nam trong `manifest_hash`.
                // De no o mot cho khac — receipt chang han — thi doi phuc hoi chi con
                // mot danh sach `render_id` khong doi chieu lai duoc voi ban da chot.
                'render_id' => (string) $clip['render_id'],
                'sequence_no' => $i + 1,
                'path' => $clip['artifact_path'],
                'sha256' => $clip['sha256'],
                'trim_start_ms' => 0,
                'duration_ms' => (int) $clip['duration_ms'],
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
            return [
                'slots' => [],
                'prompt' => null,
                'blocked_reason' => $why,
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
        ?array $spaceSource = null,
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

        if ($spaceSource !== null) {
            $required[] = [
                'artifact_id' => $spaceSource['artifact_id'],
                'candidate_id' => $spaceSource['candidate_id'],
                'sha256' => $spaceSource['sha256'],
                'role' => 'space_geometry',
                'title' => 'Nguồn hình học: '.$spaceSource['title'],
            ];
        }

        if (! $continues) {
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
     * @return array{primary: ?array<string, mixed>, why: string, continues: bool, place: bool, anchor: ?array<string, mixed>,
     *               identity: ?array<string, mixed>, views: list<array<string, mixed>>}
     */
    private function manifestBasis(string $projectId, VideoRenderScene $scene, ?VideoPlanningStage $stage, array $views): array
    {
        [$primary, $why] = $this->resolveSource($scene, $stage, null, false);
        $continues = ($primary['role'] ?? null) === 'source_keyframe';
        $place = $this->isPlaceShot($scene);
        [$anchor] = $primary !== null && $continues && ! $place ? $this->anchorForDisplay($projectId, $scene, $stage) : [null];
        $identity = $place ? null : ($continues ? $anchor : $primary);

        return [
            'primary' => $primary,
            'why' => $why,
            'continues' => $continues,
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
            'options' => $basis['primary'] === null ? [] : array_map(static fn (array $option): array => [
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
                    default => $this->pickView($views, $used),
                };

                if ($entry === null || array_key_exists($entry['artifact_id'], $used) || count($auto) >= $room) {
                    continue;
                }

                $used[$entry['artifact_id']] = true;
                $auto[] = array_replace($entry, ['role' => $role, 'title' => $entry['title'] ?? 'Ảnh neo']);
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

    private function spaceSha(VideoRenderScene $scene): ?string
    {
        $state = is_array($scene->state_json) ? $scene->state_json : [];

        if (! is_array($state['space'] ?? null)) {
            return null;
        }

        return $this->digest((array) $this->canonical([
            'project_id' => (string) $scene->project_id,
            'location_id' => (string) ($state['location_id'] ?? ''),
            'subject_id' => $state['space']['subject_id'] ?? null,
            'space' => $state['space'],
        ]));
    }

    /**
     * @return array{space_sha256: string, name: string, options: list<array<string, mixed>>,
     *               chosen: ?array<string, mixed>, unknown: bool, missing: bool}|null
     */
    private function spaceSourceState(VideoRenderScene $scene, ?VideoPlanningStage $stage, ?string $chosenId): ?array
    {
        $sha = $this->spaceSha($scene);

        if ($sha === null) {
            return null;
        }

        $state = is_array($scene->state_json) ? $scene->state_json : [];
        $interior = ($state['space'][LocationProfile::ENCLOSURE_KEY] ?? null) === LocationProfile::INTERIOR;
        $projectId = (string) $scene->project_id;
        [$primary] = $this->resolveSource($scene, $stage, null, false);
        $continues = ($primary['role'] ?? null) === 'source_keyframe';
        $identity = $continues ? $this->anchorForDisplay($projectId, $scene, $stage)[0] : $primary;
        $options = [];

        if ($identity !== null) {
            $options[] = [
                'artifact_id' => (string) $identity['artifact_id'],
                'candidate_id' => (string) ($identity['candidate_id'] ?? ''),
                'sha256' => (string) $identity['sha256'],
                'title' => 'Ảnh neo',
                'kind' => 'anchor',
                'shows' => self::EXTERIOR_SOURCE,
            ];

            foreach ($this->approvedReferenceViews($projectId) as $view) {
                if (($view['anchor_artifact_id'] ?? null) === (string) $identity['artifact_id']
                    && ($view['anchor_sha256'] ?? null) === (string) $identity['sha256']) {
                    $options[] = [
                        'artifact_id' => (string) $view['artifact_id'],
                        'candidate_id' => (string) $view['candidate_id'],
                        'sha256' => (string) $view['sha256'],
                        'title' => (string) $view['title'],
                        'kind' => 'reference',
                        'shows' => self::EXTERIOR_SOURCE,
                    ];
                }
            }
        }

        $requirement = $this->screenplayEnvironmentRequirement($scene);
        $roomPlate = ($requirement['kind'] ?? null) === self::ENVIRONMENT_ROOM
            ? ($this->approvedPlates($projectId)[$requirement['key']] ?? null)
            : null;

        if ($roomPlate !== null) {
            $options[] = [
                'artifact_id' => (string) $roomPlate['artifact_id'],
                'candidate_id' => (string) $roomPlate['candidate_id'],
                'sha256' => (string) $roomPlate['sha256'],
                'title' => 'Tấm nền phòng: '.$requirement['label'],
                'kind' => 'environment',
                'shows' => LocationProfile::INTERIOR,
            ];
        }

        if ($interior) {
            $options = array_values(array_filter(
                $options,
                static fn (array $option): bool => $option['shows'] === LocationProfile::INTERIOR,
            ));
        }

        $latest = VideoDesignImage::query()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
            ->where('prompt_spec_json->space_source->space_sha256', $sha)
            ->orderByDesc('created_at')
            ->first(['prompt_spec_json']);
        $locked = is_array($latest?->prompt_spec_json) ? ($latest->prompt_spec_json['space_source'] ?? null) : null;
        $previous = is_array($locked) && ($locked['space_sha256'] ?? null) === $sha
            ? (string) ($locked['artifact_id'] ?? '')
            : null;
        $chosen = null;

        foreach ($options as $index => $option) {
            $options[$index]['suggested'] = $previous !== null && $option['artifact_id'] === (string) $previous;

            if ($chosenId !== null && hash_equals($option['artifact_id'], $chosenId)) {
                $chosen = $options[$index];
            }
        }

        return [
            'space_sha256' => $sha,
            'name' => (string) ($state['space']['name'] ?? ''),
            'options' => $options,
            'chosen' => $chosen,
            'unknown' => $chosenId !== null && $chosen === null,
            'missing' => $interior && $options === [],
        ];
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

        $memoKey = 'screenplay_environment:'.$scene->screenplay_stage_id.':'.$scene->screenplay_scene_code;

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

        return $this->rememberSource($memoKey, $this->sceneEnvironmentRequirement($screenplay, $screenplayScene, $this->environmentContext((string) $scene->project_id)));
    }

    /**
     * @return array{stages: list<string>, fitted_from: ?string, spaces: array<string, array<string, mixed>>, furnished: bool}
     */
    private function environmentContext(string $projectId): array
    {
        $memoKey = 'environment_context:'.$projectId;

        if ($this->sourceMemo !== null && array_key_exists($memoKey, $this->sourceMemo)) {
            return $this->sourceMemo[$memoKey];
        }

        $project = VideoProject::query()->with('article.category')->find($projectId);
        $category = (string) ($project?->article?->category?->slug ?? '');
        $profile = $category === '' ? null : $this->screenplayExpansion->screenplayProfile($category);
        $stages = array_values(array_filter((array) ($profile['arc_stages'] ?? []), 'is_string'));
        $fittedFrom = config("video.environment.fitted_from_stage.{$category}");
        $design = $project === null ? null : app(VesselDesignService::class)->currentStage($projectId);
        $spaces = [];

        foreach ((array) ($design?->output_json[\App\Video\Screenplay\ProtagonistProfile::OUTPUT_KEY][\App\Video\Screenplay\ProtagonistProfile::INTERIOR_KEY] ?? []) as $space) {
            if (is_array($space) && is_string($space['space'] ?? null)) {
                $spaces[$space['space']] = $space;
            }
        }

        return $this->rememberSource($memoKey, [
            'stages' => $stages,
            'fitted_from' => is_string($fittedFrom) && in_array($fittedFrom, $stages, true) ? $fittedFrom : null,
            'spaces' => $spaces,
            'furnished' => $design !== null
                && (PlanningStageStore::metadataOf($design->input_json)[VesselDesign::INTERIOR_POLICY_KEY] ?? null) === VesselDesign::FURNISHED_ROOMS_POLICY,
        ]);
    }

    /**
     * @param  array{stages: list<string>, fitted_from: ?string, spaces: array<string, array<string, mixed>>, furnished: bool}  $context
     * @param  array<string, mixed>  $screenplayScene
     */
    private function roomPhase(array $context, array $screenplayScene): ?string
    {
        $stage = array_search((string) ($screenplayScene['stage'] ?? ''), $context['stages'], true);
        $fittedFrom = $context['fitted_from'] === null ? false : array_search($context['fitted_from'], $context['stages'], true);

        if ($stage === false || $fittedFrom === false) {
            return null;
        }

        return $stage < $fittedFrom ? EnvironmentPlatePrompt::ROOM_UNFITTED : EnvironmentPlatePrompt::ROOM_FITTED;
    }

    /**
     * @param  array<string, mixed>  $screenplay
     * @param  array<string, mixed>  $screenplayScene
     * @param  array{stages: list<string>, fitted_from: ?string, spaces: array<string, array<string, mixed>>, furnished: bool}  $context
     * @return array<string, mixed>|null
     */
    private function sceneEnvironmentRequirement(array $screenplay, array $screenplayScene, array $context): ?array
    {
        $locationId = (string) ($screenplayScene['location_id'] ?? '');
        $location = collect((array) ($screenplay['locations'] ?? []))->firstWhere('id', $locationId);

        if (! is_array($location)
            || trim((string) ($location['name'] ?? '')) === ''
            || trim((string) ($location['description'] ?? '')) === '') {
            return null;
        }

        if (\App\Video\Screenplay\LocationProfile::isProfiled($location)) {
            return $this->profiledEnvironmentRequirement(
                $screenplay, $location, $this->roomPhase($context, $screenplayScene), $context['spaces'], $context['furnished'],
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
     * @return array<string, mixed>
     */
    private function profiledEnvironmentRequirement(array $screenplay, array $location, ?string $phase, array $spaces, bool $furnished = false): array
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

        $identity = [
            'contract' => $contract,
            'place' => $place,
            'subject_name' => $subjectName,
        ] + ($room ? ['phase' => $phase, 'fitted' => $fitted] : []);
        $sha = $this->digest($identity);

        return [
            'plate' => true,
            'kind' => $room ? self::ENVIRONMENT_ROOM : self::ENVIRONMENT_EXTERNAL,
            'location_id' => $place['id'],
            'phase' => $room ? $phase : null,
            'key' => 'story_'.substr($sha, 0, 24),
            'label' => $place['name'].match ($room ? $phase : null) {
                EnvironmentPlatePrompt::ROOM_UNFITTED => ' · trước hoàn thiện',
                EnvironmentPlatePrompt::ROOM_FITTED => ' · đã hoàn thiện',
                default => '',
            },
            'place' => EnvironmentPlatePrompt::placeBlock($place, $room),
            'prompt' => $room
                ? EnvironmentPlatePrompt::forRoom($place, $subjectName, (string) $phase, $fitted)
                : EnvironmentPlatePrompt::forLocation(
                    $place,
                    $subjectName,
                    ($location[\App\Video\Screenplay\LocationProfile::ENCLOSURE_KEY] ?? null) === \App\Video\Screenplay\LocationProfile::INTERIOR,
                ),
            'plate_version' => $room ? EnvironmentPlatePrompt::ROOM_VERSION : EnvironmentPlatePrompt::LOCATION_VERSION,
            'spatial_relation' => $place['spatial_relation'],
            'version' => $contract,
            'sha256' => $sha,
        ];
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

            if ($row->artifact === null) {
                continue;
            }

            $anchorKeys = match (true) {
                ($spec['derivation_version'] ?? null) === ReferencePromptWriter::DERIVATION_VERSION => ['identity_anchor_artifact_id', 'identity_anchor_sha256'],
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
            'referenceSource' => app(ReferencePromptWriter::class)->source($projectId, $anchor),
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
        $entries = [];

        foreach ((array) ($screenplay['scenes'] ?? []) as $screenplayScene) {
            if (is_array($screenplayScene)) {
                $entries[] = [(string) ($screenplayScene['id'] ?? ''), $this->sceneEnvironmentRequirement($screenplay, $screenplayScene, $this->environmentContext((string) $project->id))];
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
            $setting = $location.'|'.(string) ($requirement['phase'] ?? '');

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
     * Brief Haiku -> ke hoach san xuat phim. Di qua `PlanningStageStore` nhu moi
     * chang khac, nen thua huong claim/lease, cache theo hash va cot chi phi.
     *
     * Validator chay TRUOC khi ghi thanh cong: mot kich ban vi pham van la mot
     * luot da tra tien, nen no duoc ghi that bai KEM raw va usage.
     *
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    public function authorScreenplay(string $projectId): array
    {
        $project = $this->videoProjectRepository->getById($projectId);

        if ($project === null || $project->article === null) {
            return [null, 'project_not_found'];
        }

        $brief = $this->stageStore->latestOutputForProject(
            $projectId,
            PlanningStageName::INSPIRATION,
        );

        if (! is_array($brief) || $brief === []) {
            return [null, 'no_inspiration_brief'];
        }

        $profile = $this->screenplayExpansion->screenplayProfile((string) ($project->article->category?->slug ?? ''));

        if ($profile === null) {
            return [null, 'no_screenplay_profile'];
        }

        $profile = FilmBrief::applyToProfile($profile, null);

        try {
            $author = app(\App\Video\Screenplay\ScreenplayAuthor::class);
        } catch (\App\Video\Prompt\Exceptions\TextCompletionException $e) {
            Log::error('screenplay: configured contract is not supported, no model call made', [
                'project_id' => $projectId,
                'reason' => $e->getMessage(),
            ]);

            return [null, 'screenplay_contract_unsupported'];
        }
        // dd($author);

        $contract = $author->contractVersion();

        if (($profile['contract_version'] ?? null) !== $contract) {
            Log::error('screenplay: profile does not match the author contract, no model call made', [
                'project_id' => $projectId,
                'author_contract' => $contract,
                'profile_contract' => $profile['contract_version'] ?? null,
            ]);

            return [null, 'screenplay_profile_contract_mismatch'];
        }

        $profileErrors = (new \App\Video\Screenplay\ScreenplayValidator)
            ->profileViolations($profile, $contract);

        if ($profileErrors !== []) {
            Log::error('screenplay: invalid profile, no model call made', [
                'project_id' => $projectId,
                'violations' => $profileErrors,
            ]);

            return [null, 'screenplay_profile_invalid'];
        }

        try {
            $author->assertSchemaMatchesContract();
        } catch (\App\Video\Prompt\Exceptions\TextCompletionException $e) {
            Log::error('screenplay: invalid schema configuration, no model call made', [
                'project_id' => $projectId,
                'reason' => $e->getMessage(),
            ]);

            return [null, 'screenplay_schema_invalid'];
        }

        [$inspiration, $unclean] = (new \App\Video\Screenplay\CreativeInspirationBuilder)
            ->build($brief);

        if ($inspiration === null) {
            Log::warning('screenplay: inspiration refused, no model call made', [
                'project_id' => $projectId,
                'violations' => $unclean,
            ]);

            return [null, 'inspiration_carries_source_facts'];
        }

        $requirements = [
            'aspect_ratio' => (string) config('video.screenplay.aspect_ratio', '9:16'),
        ];

        $input = [
            'fingerprint' => $author->fingerprint($inspiration, $profile, $requirements),
        ];

        [$claimed, $token, $reason] = $this->stageStore->claimProjectStage(
            $projectId,
            PlanningStageName::SCREENPLAY,
            $input,
        );

        if ($reason === 'already_succeeded') {
            return [$claimed->output_json ?? [], 'cached'];
        }

        if ($token === null) {
            return [null, 'screenplay_running'];
        }

        $startedAt = microtime(true);

        Log::info('screenplay: calling the model', [
            'project_id' => $projectId,
            'stage_id' => $claimed->id,
            'contract' => $contract,
            'profile' => $profile['profile_version'] ?? null,
            'model' => (string) config('video.screenplay.model'),
            'max_tokens' => (int) config('video.screenplay.max_tokens'),
            'timeout_seconds' => (int) config('video.screenplay.timeout_seconds'),
            'attempts' => (int) config('video.screenplay.retry_times'),
            'prompt_dir' => (string) config('video.screenplay.prompt_dir'),
        ]);

        try {
            $result = $author->author($inspiration, $profile, $requirements);
        } catch (\App\Video\Screenplay\ScreenplayFailure $e) {
            Log::error('screenplay: author failed after a paid response', [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
                'waited_seconds' => round(microtime(true) - $startedAt, 1),
                'exception' => $e,
            ]);

            return [null, $this->screenplayExpansion->recordFailure(
                $projectId,
                $claimed->id,
                $token,
                $e->getMessage(),
                'screenplay_author_failed',
                $e->usage,
                $e->rawResponse,
            )];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $errno = $this->screenplayExpansion->curlErrorNumber($e);
            $timedOut = $errno === ScreenplayExpansionService::CURL_OPERATION_TIMEDOUT;

            Log::error('screenplay: the request never reached a response', [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
                'waited_seconds' => round(microtime(true) - $startedAt, 1),
                'timeout_seconds' => (int) config('video.screenplay.timeout_seconds'),
                'curl_errno' => $errno,
                'exception' => $e,
            ]);

            return [null, $this->screenplayExpansion->recordFailure(
                $projectId,
                $claimed->id,
                $token,
                $e->getMessage(),
                $timedOut ? 'screenplay_timeout' : 'screenplay_connection_failed',
            )];
        } catch (\Throwable $e) {
            Log::error('screenplay: author failed before any response', [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
                'waited_seconds' => round(microtime(true) - $startedAt, 1),
                'exception' => $e,
            ]);

            return [null, $this->screenplayExpansion->recordFailure(
                $projectId,
                $claimed->id,
                $token,
                $e->getMessage(),
                'screenplay_call_failed',
            )];
        }

        try {
            $excludedNames = array_values(array_map(
                static fn (array $item): string => (string) ($item['value'] ?? ''),
                $brief['excluded_context'] ?? [],
            ));

            $validator = new \App\Video\Screenplay\ScreenplayValidator;
            $structural = $validator->structural($result->screenplay, $profile, $contract, $excludedNames);

            if ($structural !== []) {
                return [null, $this->screenplayExpansion->recordFailure(
                    $projectId,
                    $claimed->id,
                    $token,
                    'Screenplay failed validation: '.implode('; ', $structural),
                    'screenplay_invalid',
                    $result->usage,
                    $result->rawResponse,
                )];
            }

            $warnings = $validator->editorial($result->screenplay, $profile);
        } catch (\Throwable $e) {
            Log::error('screenplay: failed after a paid response', [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
                'exception' => $e,
            ]);

            return [null, $this->screenplayExpansion->recordFailure(
                $projectId,
                $claimed->id,
                $token,
                $e->getMessage(),
                'screenplay_after_response_failed',
                $result->usage,
                $result->rawResponse,
            )];
        }

        try {
            $recorded = $this->stageStore->finishSucceeded(
                $claimed->id,
                $token,
                $result->rawResponse,
                $result->toStorage()
                    + ['schema_version' => $contract]
                    + ['warnings' => $warnings],
                $result->usage,
            );
        } catch (\Throwable $e) {
            Log::error('screenplay: writing the paid result threw, stored state unknown', [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
                'exception' => $e,
            ]);

            return [null, 'screenplay_result_not_stored'];
        }

        if (! $recorded) {
            Log::warning('screenplay: claim lost, paid result not recorded', [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
            ]);

            return [null, 'screenplay_claim_lost'];
        }

        return [$result->screenplay, $warnings === [] ? 'ok' : 'ok_needs_review'];
    }

    /**
     * Viet phan nen tang cua kich ban: khong scene, khong coverage, khong shot.
     *
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    public function authorScreenplayFoundation(string $projectId, bool $force = false): array
    {
        $fullProfile = [];
        [$author, $profile, $inspiration, $brief, $reason] = $this->prepareScreenplayCall(
            $projectId,
            'video.screenplay.foundation_author',
            static function (array $loaded) use (&$fullProfile): array {
                $fullProfile = $loaded;

                return ScreenplayExpansionService::foundationShape($loaded);
            },
        );
        if ($author === null) {
            return [null, $reason];
        }

        [$filmBrief, $briefErrors] = FilmBrief::ofProfile($fullProfile);

        if ($filmBrief !== null) {
            $briefErrors = FilmBrief::profileViolations($fullProfile, $filmBrief);
        }

        if ($briefErrors !== []) {
            Log::error('screenplay foundation: the profile film brief is invalid, no model call made', [
                'project_id' => $projectId,
                'violations' => $briefErrors,
            ]);

            return [null, 'screenplay_film_brief_invalid'];
        }

        $contract = $author->contractVersion();
        $profile = FilmBrief::applyToProfile($profile, $filmBrief);
        $requirements = ['aspect_ratio' => (string) config('video.screenplay.aspect_ratio', '9:16')]
            + ($filmBrief === null ? [] : [FilmBrief::REQUIREMENT_KEY => $filmBrief]);
        $schema = $author->contractSchema();

        if (isset($schema['properties']['space_plan']['items']['properties']['space'])) {
            $schema['properties']['space_plan']['items']['properties']['space'] = FilmBrief::spaceField(
                $schema['properties']['space_plan']['items']['properties']['space'],
                $filmBrief,
            );
        }

        $input = [
            'fingerprint' => $author->fingerprint($inspiration, $profile, $requirements),
            'screenplay_profile_sha256' => ScreenplayExpansionService::contentHash($fullProfile),
        ];

        [$claimed, $token, $claimReason] = $this->stageStore->claimProjectStage(
            $projectId,
            PlanningStageName::SCREENPLAY_FOUNDATION,
            $input,
            $force,
            [
                'profile' => $profile,
                'requirements' => $requirements,
                ScreenplayExpansionService::PROFILE_SNAPSHOT_KEY => $fullProfile,
                'prompt' => $author->promptLineage(),
            ],
        );

        if ($claimReason === 'already_succeeded') {
            return [$claimed->output_json ?? [], 'cached'];
        }

        if ($token === null) {
            return [null, 'screenplay_running'];
        }

        $startedAt = microtime(true);

        try {
            $result = $author->author($inspiration, $profile, $requirements, [], $schema);
        } catch (\App\Video\Screenplay\ScreenplayFailure $e) {
            Log::error('screenplay foundation: author failed after a paid response', [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
                'waited_seconds' => round(microtime(true) - $startedAt, 1),
                'exception' => $e,
            ]);

            return [null, $this->screenplayExpansion->recordFailure(
                $projectId, $claimed->id, $token, $e->getMessage(),
                'screenplay_author_failed', $e->usage, $e->rawResponse,
            )];
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $errno = $this->screenplayExpansion->curlErrorNumber($e);

            Log::error('screenplay foundation: the request never reached a response', [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
                'waited_seconds' => round(microtime(true) - $startedAt, 1),
                'timeout_seconds' => (int) config('video.screenplay.foundation.timeout_seconds'),
                'curl_errno' => $errno,
                'exception' => $e,
            ]);

            return [null, $this->screenplayExpansion->recordFailure(
                $projectId, $claimed->id, $token, $e->getMessage(),
                $errno === ScreenplayExpansionService::CURL_OPERATION_TIMEDOUT ? 'screenplay_timeout' : 'screenplay_connection_failed',
            )];
        } catch (\Throwable $e) {
            Log::error('screenplay foundation: author failed before any response', [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
                'waited_seconds' => round(microtime(true) - $startedAt, 1),
                'exception' => $e,
            ]);

            return [null, $this->screenplayExpansion->recordFailure(
                $projectId, $claimed->id, $token, $e->getMessage(), 'screenplay_call_failed',
            )];
        }

        try {
            $excludedNames = array_values(array_map(
                static fn (array $item): string => (string) ($item['value'] ?? ''),
                $brief['excluded_context'] ?? [],
            ));

            $violations = (new \App\Video\Screenplay\ScreenplayValidator)
                ->structural($result->screenplay, $profile, $contract, $excludedNames);

            if ($violations !== []) {
                return [null, $this->screenplayExpansion->recordFailure(
                    $projectId, $claimed->id, $token,
                    'Foundation failed validation: '.implode('; ', $violations),
                    'screenplay_invalid', $result->usage, $result->rawResponse,
                )];
            }
        } catch (\Throwable $e) {
            Log::error('screenplay foundation: failed after a paid response', [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
                'exception' => $e,
            ]);

            return [null, $this->screenplayExpansion->recordFailure(
                $projectId, $claimed->id, $token, $e->getMessage(),
                'screenplay_after_response_failed', $result->usage, $result->rawResponse,
            )];
        }

        try {
            $recorded = $this->stageStore->finishSucceeded(
                $claimed->id,
                $token,
                $result->rawResponse,
                $result->toStorage() + ['schema_version' => $contract],
                $result->usage,
            );
        } catch (\Throwable $e) {
            Log::error('screenplay foundation: writing the paid result threw, stored state unknown', [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
                'exception' => $e,
            ]);

            return [null, 'screenplay_result_not_stored'];
        }

        if (! $recorded) {
            Log::warning('screenplay foundation: claim lost, paid result not recorded', [
                'project_id' => $projectId,
                'stage_id' => $claimed->id,
            ]);

            return [null, 'screenplay_claim_lost'];
        }

        return [$result->screenplay, 'ok'];
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
            return app(VesselDesignService::class)->anchorBySource($projectId, $locked);
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


    /**
     * @param  \Closure(array<string, mixed>): array<string, mixed>|null  $shapeProfile
     * @return array{0: ?\App\Video\Screenplay\ScreenplayAuthor, 1: array<string, mixed>,
     *               2: array<string, mixed>, 3: array<string, mixed>, 4: string}
     */
    private function prepareScreenplayCall(
        string $projectId,
        string $authorKey,
        ?\Closure $shapeProfile = null,
    ): array {
        $project = $this->videoProjectRepository->getById($projectId);

        if ($project === null || $project->article === null) {
            return [null, [], [], [], 'project_not_found'];
        }

        $brief = $this->stageStore->latestOutputForProject($projectId, PlanningStageName::INSPIRATION);

        if (! is_array($brief) || $brief === []) {
            return [null, [], [], [], 'no_inspiration_brief'];
        }

        $profile = $this->screenplayExpansion->screenplayProfile((string) ($project->article->category?->slug ?? ''));

        if ($profile === null) {
            return [null, [], [], [], 'no_screenplay_profile'];
        }

        if ($shapeProfile !== null) {
            $profile = $shapeProfile($profile);
        }

        try {
            $author = app($authorKey);
        } catch (\App\Video\Prompt\Exceptions\TextCompletionException $e) {
            Log::error('screenplay: configured contract is not supported, no model call made', [
                'project_id' => $projectId,
                'author' => $authorKey,
                'reason' => $e->getMessage(),
            ]);

            return [null, [], [], [], 'screenplay_contract_unsupported'];
        }

        $contract = $author->contractVersion();

        if (($profile['contract_version'] ?? null) !== $contract) {
            Log::error('screenplay: profile does not match the author contract, no model call made', [
                'project_id' => $projectId,
                'author_contract' => $contract,
                'profile_contract' => $profile['contract_version'] ?? null,
            ]);

            return [null, [], [], [], 'screenplay_profile_contract_mismatch'];
        }

        $profileErrors = (new \App\Video\Screenplay\ScreenplayValidator)
            ->profileViolations($profile, $contract);

        if ($profileErrors !== []) {
            Log::error('screenplay: invalid profile, no model call made', [
                'project_id' => $projectId,
                'author' => $authorKey,
                'violations' => $profileErrors,
            ]);

            return [null, [], [], [], 'screenplay_profile_invalid'];
        }

        try {
            $author->assertSchemaMatchesContract();
        } catch (\App\Video\Prompt\Exceptions\TextCompletionException $e) {
            Log::error('screenplay: invalid schema configuration, no model call made', [
                'project_id' => $projectId,
                'reason' => $e->getMessage(),
            ]);

            return [null, [], [], [], 'screenplay_schema_invalid'];
        }

        [$inspiration, $unclean] = (new \App\Video\Screenplay\CreativeInspirationBuilder)->build($brief);

        if ($inspiration === null) {
            Log::warning('screenplay: inspiration refused, no model call made', [
                'project_id' => $projectId,
                'violations' => $unclean,
            ]);

            return [null, [], [], [], 'inspiration_carries_source_facts'];
        }

        return [$author, $profile, $inspiration, $brief, 'ok'];
    }

    /**
     * Trang thai chang SCREENPLAY cho man hinh: ban da luu, canh bao bien tap,
     * va co dang chay hay khong.
     *
     * @return array{screenplay: ?array<string, mixed>, warnings: list<string>,
     *               running: bool, error: ?string, written_at: ?string}
     */
    public function latestScreenplay(string $projectId): array
    {
        [$latest] = $this->stageStore->latestStageForProject(
            $projectId,
            PlanningStageName::SCREENPLAY,
            [],
        );

        $stored = $latest?->status === VideoPlanningStageStatus::SUCCEEDED->value
            ? ($latest->output_json ?? null)
            : null;

        $warnings = is_array($stored) ? ($stored['warnings'] ?? []) : [];

        if (is_array($stored)) {
            unset($stored['warnings']);
        }

        return [
            'screenplay' => is_array($stored) && $stored !== [] ? $stored : null,
            'warnings' => is_array($warnings) ? array_values($warnings) : [],
            'running' => $latest?->status === VideoPlanningStageStatus::RUNNING->value
                && $latest->lease_expires_at?->isFuture() === true,
            'error' => $latest?->status === VideoPlanningStageStatus::FAILED->value
                ? $latest->error_message
                : null,
            'written_at' => $latest?->finished_at?->format('d/m/Y H:i'),
        ];
    }

    /** @return array{0: bool, 1: string} */
    public function resetScreenplay(string $projectId): array
    {
        [$latest] = $this->stageStore->latestStageForProject(
            $projectId,
            PlanningStageName::SCREENPLAY,
            [],
        );

        if ($latest === null) {
            return [false, 'Chua co luot viet kich ban nao'];
        }

        return $this->stageStore->releaseClaim($latest->id, 'Nguoi dung reset thu cong')
            ? [true, 'ok']
            : [false, 'Luot nay khong con giu claim — khong co gi de reset'];
    }

    // /**
    //  * Python image_prompt still compiles the `creative_concept` branch. Store the
    //  * canonical concept in Laravel, then project it at this boundary only.
    //  *
    //  * @param  array<string, mixed>  $concept
    //  * @return array<string, mixed>
    //  */
    // private function pythonConceptFromStored(array $concept): array
    // {
    //     if (! $this->isCanonicalDesignSpec($concept)) {
    //         return $concept;
    //     }

    //     $dimensions = is_array($concept['dimensions'] ?? null) ? $concept['dimensions'] : [];
    //     $geometry = is_array($concept['permanent_geometry'] ?? null) ? $concept['permanent_geometry'] : [];
    //     $materials = is_array($concept['finished_materials'] ?? null) ? $concept['finished_materials'] : [];

    //     $identity = [
    //         'design_length_m' => $dimensions['length_m'] ?? null,
    //         'design_beam_m' => $dimensions['beam_m'] ?? null,
    //         'length_to_beam_ratio' => $dimensions['length_to_beam_ratio'] ?? null,
    //         'design_draft_m' => $dimensions['draft_m'] ?? null,
    //         'visible_freeboard_at_midships_m' => $dimensions['freeboard_midships_m'] ?? null,
    //         'typical_deck_to_deck_height_m' => $dimensions['deck_to_deck_height_m'] ?? null,
    //         'visible_deck_tiers' => $geometry['superstructure']['enclosed_deck_levels']
    //             ?? ($geometry['superstructure']['primary_tier_count'] ?? null),
    //         'bow' => $geometry['bow'] ?? null,
    //         'hull' => $geometry['hull'] ?? null,
    //         'stern' => $this->legacyStern($geometry['stern'] ?? null),
    //         'superstructure' => $geometry['superstructure'] ?? null,
    //         'openings' => $this->legacyOpenings($geometry['openings'] ?? null),
    //         'hull_material' => $materials['hull_material'] ?? $this->materialText($materials['hull'] ?? null, 'material'),
    //         'superstructure_material' => $materials['superstructure_material'] ?? $this->materialText($materials['superstructure'] ?? null, 'material'),
    //         'hull_colour' => $materials['hull_colour'] ?? $this->materialText($materials['hull'] ?? null, 'colour'),
    //         'boot_stripe_colour' => $materials['boot_stripe_colour'] ?? null,
    //         'superstructure_colour' => $materials['superstructure_colour'] ?? $this->materialText($materials['superstructure'] ?? null, 'colour'),
    //         'glazing_type' => $materials['glazing_type'] ?? $this->materialText($materials['glazing'] ?? null, 'type'),
    //     ];

    //     $missing = array_keys(array_filter(
    //         $identity,
    //         static fn (mixed $value): bool => $value === null || $value === [],
    //     ));

    //     if ($missing !== []) {
    //         throw new \RuntimeException(
    //             'Canonical concept cannot compile to Python prompt; missing identity slots: '
    //             .implode(', ', $missing)
    //         );
    //     }

    //     $relationships = $this->legacyFormRelationships($concept['form_relationships'] ?? []);
    //     $missingRelationships = array_keys(array_filter(
    //         $relationships,
    //         static fn (mixed $value): bool => ! is_string($value) || trim($value) === '',
    //     ));

    //     if ($missingRelationships !== []) {
    //         throw new \RuntimeException(
    //             'Canonical concept cannot compile to Python prompt; missing form_relationships: '
    //             .implode(', ', $missingRelationships)
    //         );
    //     }

    //     return [
    //         'design_thesis' => (string) ($concept['design_thesis']['text'] ?? ''),
    //         'design_identity' => $identity,
    //         'form_relationships' => $relationships,
    //         'signature_features' => [],
    //         'decisions' => $this->legacyDecisions($concept['provenance'] ?? []),
    //     ];
    // }

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

    // /**
    //  * @return list<array<string, mixed>>
    //  */
    // private function legacyDecisions(mixed $provenance): array
    // {
    //     if (! is_array($provenance)) {
    //         return [];
    //     }

    //     return array_values(array_filter(array_map(
    //         static function (mixed $item): ?array {
    //             if (! is_array($item)) {
    //                 return null;
    //             }

    //             $target = trim((string) ($item['target_path'] ?? ''));

    //             if ($target === '') {
    //                 return null;
    //             }

    //             return [
    //                 'aspect' => $target,
    //                 'area' => $target,
    //                 'decision' => $target,
    //                 'provenance' => (string) ($item['origin'] ?? ''),
    //             ];
    //         },
    //         $provenance,
    //     )));
    // }

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

    // /** @param  array<string, mixed>  $output */
    // private function isCanonicalDesignSpec(array $output): bool
    // {
    //     return is_string($output['schema_version'] ?? null)
    //         && is_string($output['object_type'] ?? null)
    //         && is_array($output['identity'] ?? null)
    //         && is_array($output['dimensions'] ?? null)
    //         && is_array($output['permanent_geometry'] ?? null)
    //         && is_array($output['invariants'] ?? null);
    // }

    // private function emptyConcept(?string $reason = null): array
    // {
    //     return [
    //         'analysed' => false,
    //         'status' => null,
    //         'running' => false,
    //         'stuck' => false,
    //         'error' => $reason,
    //         'can_run' => true,
    //         'thesis' => null,
    //         'identity' => [],
    //         'relationships' => [],
    //         'features' => [],
    //         'decisions' => [],
    //         'json' => [],
    //         'design_spec' => [],
    //         'meta' => [],
    //         'provenance_summary' => null,
    //         'frozen_at' => null,
    //     ];
    // }

    // /**
    //  * @param  array<string, mixed>  $concept
    //  * @return array<string, mixed>
    //  */
    // private function displayIdentityFromCanonical(array $concept): array
    // {
    //     return array_filter([
    //         'object_type' => $concept['object_type'] ?? null,
    //         'subject_class' => $concept['identity']['subject_class'] ?? null,
    //         'length_m' => $concept['dimensions']['length_m'] ?? null,
    //         'beam_m' => $concept['dimensions']['beam_m'] ?? null,
    //         'length_to_beam_ratio' => $concept['dimensions']['length_to_beam_ratio'] ?? null,
    //         'draft_m' => $concept['dimensions']['draft_m'] ?? null,
    //         'bow' => $concept['permanent_geometry']['bow'] ?? null,
    //         'hull' => $concept['permanent_geometry']['hull'] ?? null,
    //         'stern' => $concept['permanent_geometry']['stern'] ?? null,
    //         'superstructure' => $concept['permanent_geometry']['superstructure'] ?? null,
    //         'openings' => $concept['permanent_geometry']['openings'] ?? null,
    //     ], static fn (mixed $value): bool => $value !== null && $value !== []);
    // }

    // /** @return array<string, mixed> */
    // private function canonicalConceptStageInput(VideoProject $project, string $objectType): array
    // {
    //     return [
    //         'article_id' => $project->article->id,
    //         'object_type' => $objectType,
    //         'content_hash' => (string) ($project->article->content_hash ?? ''),
    //         'article_sha256' => hash('sha256', json_encode([
    //             'title' => (string) $project->article->title,
    //             'content' => (string) $project->article->content,
    //             'source_url' => (string) ($project->article->source_url ?? ''),
    //         ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
    //         'concept_flow' => 'canonical_parts_1_8',
    //         'canonical_prompt_version' => (string) config('canonical_concept.prompt_version', 'concept-v1'),
    //         'canonical_schema_version' => (string) config('canonical_concept.schema.version', '1.0'),
    //         'canonical_model' => $this->conceptModel(),
    //     ];
    // }

    // private function conceptProvider(): string
    // {
    //     return (string) config('canonical_concept.provider', 'anthropic');
    // }

    // private function conceptModel(): string
    // {
    //     return (string) config(
    //         'canonical_concept.'.$this->conceptProvider().'.model'
    //     );
    // }

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
    private function inspirationInput(VideoProject $project, ?InspirationProfile $profile): array
    {
        $result = $this->canonicalConceptInputBuilder->inspirationFingerprint($profile);
        return [
            'article_id' => $project->article->id,
            'category' => (string) ($project->article->category?->slug ?? ''),
            'title' => $project->article->title,
            'content' => (string) $project->article->content,
            'inspiration' => $profile === null
                ? null
                : $result,
        ];
    }

    private function inspirationProfile(VideoProject $project): ?InspirationProfile
    {
        return $this->creativeProfileResolver->resolve((string) ($project->article->category?->slug ?? ''));
    }

    /** @return array{0: ?int, 1: string} [$shotCount, $reason] */
    public function generateStoryboard(string $projectId, ?string $actorId, bool $force = false): array
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
        $stageName = PlanningStageName::SCENE_PLAN;

        [$subjectKey, $subjectReason] = $this->productionSubjectKey($projectId);

        if ($subjectKey === null) {
            return [null, $subjectReason];
        }

        $anchor = $this->productionAnchor($projectId);

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

        try {
            $skillHash = $author->skillHash();
            $schemaHash = $author->schemaHash();
        } catch (ScenePlanException $e) {
            $this->quietLog('storyboard: author skill unreadable', $e, ['project_id' => $projectId]);

            return [null, 'scene_plan_misconfigured'];
        }

        $packet = $this->storyboardPacket($screenplay, $summary);
        $provider = (string) config('canonical_concept.provider');

        $claimInput = [
            'screenplay_stage_id' => $screenplayStage->id,
            'screenplay_revision' => $screenplayStage->planning_revision,
            'screenplay_contract_version' => $screenplay['schema_version'],
            'screenplay_hash' => $screenplayHash,
            'identity_summary_hash' => $this->digest($summary),
            'anchor_identity_sha256' => (string) $anchor->prompt_sha256,
            'profile_version' => $profile->version,
            'profile_sha256' => $profile->sha256,
            'packet_hash' => $this->digest($packet),
            'skill_hash' => $skillHash,
            'schema_hash' => $schemaHash,
            'prompt_version' => $author->promptVersion(),
            'scene_contract_version' => $author->contractVersion(),
            'preservation_version' => ScenePreservationPrompt::VERSION,
            'provider' => $provider,
            'model' => $author->model(),
        ];

        $claimMeta = ['pricing' => 'unpriced'];

        [$claimed, $token, $reason] = $this->stageStore->claimProjectStage(
            $projectId, $stageName, $claimInput, $force, $claimMeta,
        );

        if ($token === null) {
            return [null, $reason === 'already_succeeded' ? 'storyboard_unchanged' : 'storyboard_running'];
        }

        $raw = '';
        $totals = [];
        $authorUsage = [];
        $authorModel = null;
        $shots = [];
        $warnings = [];

        try {
            $authorAttempted = false;

            try {
                $result = $author->plan($packet, static function () use (&$authorAttempted): void {
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

            [$shots, $warnings] = $this->storyboardShots($result->scenes, $screenplay);

            $usageColumn = $this->usageColumn($totals, $provider, $author->promptVersion(), $authorModel);
            $committed = $this->commitScenePlan(
                $projectId, $shots, $warnings, $authorUsage, $totals,
                $author->promptVersion(), $claimed->id, $token, $raw, $usageColumn,
                $screenplayStage, $screenplayHash,
            );

            if ($committed === null) {
                return $this->orphanScenePlan(
                    $projectId, $claimInput, $claimMeta, $claimed->id, $token,
                    $shots, $warnings, $authorUsage, $usageColumn, $totals, $raw, $stageName,
                );
            }
        } catch (\Throwable $e) {
            if ($e instanceof ScenePlanException && $raw === '') {
                $raw = $e->raw;
            }

            $usageColumn = $this->usageColumn($totals, $provider, $author->promptVersion(), $authorModel);
            $output = $this->safeOutput(fn (): array => $this->storyboardOutput(
                $shots, $warnings, $authorUsage, $totals, $raw,
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

            return [null, $wrote === true || $orphaned !== null
                ? 'storyboard_failed'
                : 'storyboard_failed_unrecorded'];
        }

        Log::info('storyboard: written', [
            'project_id' => $projectId,
            'revision' => $committed,
            'shots' => count($shots),
            'warnings' => count($warnings),
            'calls' => $totals['calls'] ?? 0,
        ]);

        return [count($shots), 'storyboard_draft'];
    }

    /**
     * @return array{revision: int, shot_board: bool, approved: bool, selected_revision: int}
     */
    public function storyboardApproval(string $projectId, int $revision): array
    {
        $project = VideoProject::query()->whereKey($projectId)->first();
        $selected = $project?->selectedScenePlanStage()->first();
        $selectedRevision = is_array($selected?->output_json) ? (int) ($selected->output_json['revision'] ?? 0) : 0;
        $stage = $revision < 1 ? null : $this->stageStore->stageForProjectRevision($projectId, PlanningStageName::SCENE_PLAN, $revision);

        return [
            'revision' => $revision,
            'shot_board' => $stage !== null
                && ($stage->input_json['scene_contract_version'] ?? null) === ScenePlanAuthor::STORYBOARD_CONTRACT_VERSION,
            'approved' => $stage !== null && (string) $project?->selected_scene_plan_stage_id === (string) $stage->id,
            'selected_revision' => $selectedRevision,
        ];
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
                || ($stage->input_json['scene_contract_version'] ?? null) !== ScenePlanAuthor::STORYBOARD_CONTRACT_VERSION) {
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

            $live = $rows->map(fn (VideoRenderScene $row): array => $this->planShapeOf($row))->all();

            if (! hash_equals((string) $review['reviewed_plan_sha256'], $this->planHash($live))) {
                return [null, 'scene_plan_changed_since_review'];
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
            ];
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
     * @param  list<array<string, mixed>>  $scenes
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $authorUsage
     * @param  array<string, mixed>  $totals
     * @param  array<string, mixed>  $usageColumn
     * @return int|null the draft revision; null when the claim was lost
     */
    private function commitScenePlan(
        string $projectId,
        array $scenes,
        array $warnings,
        array $authorUsage,
        array $totals,
        string $promptVersion,
        string $stageId,
        string $claimToken,
        string $raw,
        array $usageColumn,
        VideoPlanningStage $screenplayStage,
        string $screenplayHash,
    ): ?int {
        $lost = false;
        $screenplayScenes = collect((array) ($screenplayStage->output_json['scenes'] ?? []))
            ->filter(static fn ($scene) => is_array($scene) && is_string($scene['id'] ?? null))
            ->keyBy('id')
            ->all();

        try {
            return DB::transaction(function () use (
                $projectId, $scenes, $warnings, $authorUsage, $totals,
                $promptVersion, $stageId, $claimToken, $raw, $usageColumn,
                $screenplayStage, $screenplayHash, $screenplayScenes, &$lost
            ) {
                VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();

                $revision = ((int) VideoRenderScene::query()
                    ->where('project_id', $projectId)
                    ->max('revision')) + 1;

                foreach ($scenes as $index => $scene) {
                    $source = $screenplayScenes[$scene['screenplay_scene_code']] ?? [];

                    VideoRenderScene::create([
                        'project_id' => $projectId,
                        'screenplay_stage_id' => $screenplayStage->id,
                        'screenplay_scene_code' => $scene['screenplay_scene_code'],
                        'screenplay_hash' => $screenplayHash,
                        'revision' => $revision,
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
                        ]),
                        'delta_prompt' => $scene['delta'],
                        'prompt_version' => $promptVersion,
                        'transition_mode' => $scene['transition_mode'],
                        'continuity_group' => $scene['continuity_group'],
                        'source_scene_code' => $scene['source_scene_code'] !== ''
                            ? $scene['source_scene_code']
                            : null,
                        'camera_change_reason' => null,
                        'video_plan_json' => $scene['video'] + [
                            'camera_mode' => 'locked',
                            'camera' => $scene['camera'],
                        ],
                    ]);
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
                    $this->storyboardOutput(
                        $scenes, $warnings, $authorUsage, $totals, $raw, $revision, $this->planHash($stored),
                    ),
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
     * @param  array<string, mixed>  $claimInput
     * @param  array<string, mixed>  $claimMeta
     * @param  list<array<string, mixed>>  $scenes
     * @param  list<string>  $warnings
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
        array $authorUsage,
        array $usageColumn,
        array $totals,
        string $raw,
        PlanningStageName $stageName = PlanningStageName::SCENE_PLAN,
    ): array {
        $orphaned = null;

        try {
            $orphaned = $this->stageStore->recordOrphanAttempt(
                $projectId, $stageName, $claimInput, $claimMeta,
                $stageId, $claimToken, 'Storyboard claim was lost.',
                $usageColumn, $raw,
                $this->safeOutput(fn (): array => $this->storyboardOutput(
                    $scenes, $warnings, $authorUsage, $totals, $raw,
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
     * @param  array<string, string>  $summary
     * @return array<string, mixed>
     */
    private function storyboardPacket(array $screenplay, array $summary): array
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
            ]) + [
                'beats' => array_map(
                    static fn (array $beat): array => $pick($beat, ['id', 'action', 'visible_result', 'time_jump']),
                    array_values(array_filter((array) ($source['beats'] ?? []), 'is_array')),
                ),
                'subject_state' => is_array($source['subject_state'] ?? null) ? $source['subject_state'] : null,
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

        return [
            'identity' => array_intersect_key($summary, array_flip(['subject_class', 'identity', 'proportion'])),
            'characters' => $listed('characters', $characterIds, ['id', 'name', 'description']),
            'locations' => $listed('locations', $locationIds, [
                'id', 'name', 'description', 'spatial_relation', 'subject_id', 'enclosure',
                'layout', 'fixed_features', 'light_sources', 'connections',
            ]),
            'coverage' => $coverage,
            'clip_durations_ms' => $this->clipDurationsMs(),
            'scenes' => $scenes,
        ];
    }

    /**
     * @param  list<mixed>  $answer
     * @param  array<string, mixed>  $screenplay
     * @return array{0: list<array<string, mixed>>, 1: list<string>}
     */
    private function storyboardShots(array $answer, array $screenplay): array
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

        $durations = $this->clipDurationsMs();

        if ($durations === []) {
            throw new ScenePlanException('No clip duration is configured for video.media_models.video.scene_clip.');
        }

        $empty = ['progress' => null, 'configuration' => []];
        $shots = [];
        $warnings = [];
        $previous = null;
        $carried = [];

        foreach ($sources as $index => $source) {
            $sceneId = (string) $source['id'];
            $answered = $answer[$index] ?? null;

            if (! is_array($answered) || ($answered['scene_id'] ?? null) !== $sceneId) {
                throw new ScenePlanException('storyboard scene '.($index + 1).': expected '.$sceneId.'.');
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

                $purpose = is_string($item['visual_purpose'] ?? null) ? trim($item['visual_purpose']) : '';

                if (mb_strlen($purpose) < 3 || mb_strlen($purpose) > 300) {
                    throw new ScenePlanException($at.': visual_purpose must be 3 to 300 characters.');
                }

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
                    'delta' => $keyframe,
                    'video' => [
                        'action' => $this->storyboardText($at, 'action', $item['action'] ?? null),
                        'preserve' => self::STORYBOARD_PRESERVE,
                        'end_state' => $code.'_end',
                        'camera_relation' => $relation,
                        'duration_ms' => $duration,
                    ],
                    'beat_ids' => array_values(array_unique(array_column($coverageItems, 'beat_id'))),
                    'beat_coverage' => $coverageItems,
                    'keyframe_state' => $state,
                    'end_state' => $endState,
                    'objects_start' => array_values($objectsStart),
                    'objects_end' => array_values($objects),
                    'objects_first_frame' => $firstFrame,
                    'objects_last_frame' => $lastFrame,
                    'reference_requirements' => $this->storyboardReferenceNeeds($at, $item['reference_requirements'] ?? null),
                ];

                $this->checkShotStates($at, $shot, $source);

                if ($shot['camera'] !== null && preg_match('/^(wide|medium|close-up)\b/i', $shot['camera']['framing']) !== 1) {
                    $warnings[] = $at.': camera.framing does not begin with a shot size (wide, medium, close-up).';
                }

                $warnings = array_merge($warnings, $this->sceneWarnings($at, $keyframe));
                $shots[] = $shot;
                $previous = $shot;
                $state = $endState;
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

        return [$shots, $warnings];
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

            $items[] = ['beat_id' => $beatId, 'part' => $part];
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

    /** @return list<array{kind: string, purpose: string}> */
    private function storyboardReferenceNeeds(string $at, mixed $needs): array
    {
        if (! is_array($needs) || ! array_is_list($needs)) {
            throw new ScenePlanException($at.': reference_requirements must be a list.');
        }

        $out = [];

        foreach ($needs as $need) {
            $kind = is_array($need) ? (string) ($need['kind'] ?? '') : '';
            $purpose = is_array($need) && is_string($need['purpose'] ?? null) ? trim($need['purpose']) : '';

            if (! in_array($kind, ScenePlanAuthor::REFERENCE_KINDS, true) || $purpose === '') {
                throw new ScenePlanException($at.': reference requirement '.var_export($kind, true).' is unknown or has no purpose.');
            }

            $out[] = ['kind' => $kind, 'purpose' => $purpose];
        }

        return $out;
    }

    /** @return array{position: string, elevation: string, framing: string, subject_side: ?string}|null */
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

        return $out + ['subject_side' => $side];
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
    ): array {
        return [
            'revision' => $revision,
            'scenes' => $shots,
            'warnings' => $warnings,
            'raw' => ['author' => $this->storableText($authorRaw)],
            'usage' => ['author' => $authorUsage, 'total' => $totals],
            'validation' => $planHash === null ? null : ['status' => 'passed', 'plan_sha256' => $planHash],
        ];
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
        ] + array_intersect_key($state, array_flip(self::SHOT_SHAPE_STATE_KEYS)) : []);
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
        ?string $spaceSourceId = null,
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

        $space = $this->spaceSourceState($scene, $stage, $spaceSourceId);

        if ($space !== null && $space['missing']) {
            return [null, 'space_source_missing|'.$space['name']];
        }

        if ($space !== null && $space['unknown']) {
            return [null, 'space_source_stale|'.$space['name']];
        }

        if ($space !== null && $requireConfirmedAnchor && $space['chosen'] === null) {
            return [null, 'space_source_unconfirmed|'.$space['name']];
        }

        [$slots, $why] = $this->sceneManifestSlots(
            $projectId, $scene, $stage, $this->approvedReferenceViews($projectId), $space['chosen'] ?? null,
        );

        if ($slots === null) {
            return [null, $why];
        }

        $manifest = array_map(fn (array $slot) => $this->manifestEntry($slot), $slots);
        $spec = $this->sceneImageSpec($scene, $preservation, $manifest, $chosenMedia);

        if (($space['chosen'] ?? null) !== null) {
            $position = array_search($space['chosen']['artifact_id'], array_column($manifest, 'artifact_id'), true);

            if ($position === false) {
                return [null, 'space_source_stale|'.$space['name']];
            }

            $spec['space_source'] = [
                'space_sha256' => $space['space_sha256'],
                'artifact_id' => $space['chosen']['artifact_id'],
                'sha256' => $space['chosen']['sha256'],
                'position' => $position,
            ];
        }

        return [[
            'spec' => $spec,
            'hash' => $this->designImageStore->identityHash($spec, $this->sceneIdentityKeys($spec)),
            'manifest_hash' => $spec['reference_manifest_hash'],
            'space' => $space,
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

        $spaceSha = $this->spaceSha($scene);
        $source = $spec['space_source'] ?? null;

        if ($spaceSha === null && $source !== null) {
            return [null, 'candidate_space_source_mismatch'];
        }

        if ($spaceSha !== null) {
            $placed = is_array($source) && is_int($source['position'] ?? null) ? ($manifest[$source['position']] ?? null) : null;

            if (! is_array($source)
                || ! hash_equals($spaceSha, (string) ($source['space_sha256'] ?? ''))
                || $placed === null
                || $placed['artifact_id'] !== ($source['artifact_id'] ?? null)
                || $placed['sha256'] !== ($source['sha256'] ?? null)
                || ! in_array($placed['role'], ['anchor', 'space_geometry'], true)) {
                return [null, 'candidate_space_source_mismatch'];
            }
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

    public function clipSourcesNeedReview(VideoRenderScene $scene): ?string
    {
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
            'space_source' => $this->spaceSourceView($built, $spec),
            'render_ready' => $blocked === null
                && (($built['space'] ?? null) === null || ($built['space']['chosen'] ?? null) !== null),
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
     * @param  array<string, mixed>  $built
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>|null
     */
    private function spaceSourceView(array $built, array $spec): ?array
    {
        $space = $built['space'] ?? null;

        if ($space === null) {
            return is_array($spec['space_source'] ?? null) ? [
                'name' => null,
                'chosen' => (string) ($spec['space_source']['artifact_id'] ?? ''),
                'options' => [],
            ] : null;
        }

        return [
            'name' => $space['name'],
            'chosen' => $space['chosen']['artifact_id'] ?? null,
            'options' => array_map(static fn (array $option): array => [
                'artifact_id' => $option['artifact_id'],
                'title' => $option['title'],
                'kind' => $option['kind'],
                'sha' => substr($option['sha256'], 0, 12),
                'url' => route('video-artifacts.show', $option['artifact_id']),
                'suggested' => $option['suggested'],
            ], $space['options']),
        ];
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    public function sceneImagePreview(
        string $projectId,
        ?string $actorId,
        string $sceneId,
        ?string $spaceSourceId = null,
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

        [$built, $why] = $this->buildSceneImageRequest($verified, $stage, null, false, $spaceSourceId, $media);

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
        ?string $spaceSourceId = null,
        ?array $media = null,
        ?int $choiceVersion = null,
    ): array {
        try {
            return DB::transaction(function () use (
                $projectId, $actorId, $sceneId, $previewHash,
                $confirmAnchorArtifactId, $verifiedManifestHash, $spaceSourceId, $media, $choiceVersion
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
                    $verified, $stage, $confirmAnchorArtifactId, true, $spaceSourceId, $media, $choiceVersion,
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
        ?string $spaceSourceId = null,
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
            $verified, $stage, $confirmAnchorArtifactId, true, $spaceSourceId, $media, $choiceVersion,
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
            $confirmAnchorArtifactId, $built['manifest_hash'], $spaceSourceId, $media, $choiceVersion,
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
            'video_prompt' => $video === null ? null : $this->videoPrompt($video, $this->sceneSetting($scene), $this->shotMoments($scene)),
            'screenplay_scene_code' => is_string($scene->screenplay_scene_code) ? $scene->screenplay_scene_code : null,
            'shot_index' => (int) $scene->shot_index,
            'beat_ids' => array_values(array_filter((array) ($state['beat_ids'] ?? []), 'is_string')),
            'keyframe_state' => is_array($state['keyframe_state'] ?? null) ? $state['keyframe_state'] : null,
            'shot_end_state' => is_array($state['end_state'] ?? null) ? $state['end_state'] : null,
            'camera' => $this->cameraLines(is_array($video['camera'] ?? null) ? $video['camera'] : []),
            'storyboard' => is_array($video) && array_key_exists('camera', $video),
            'shot_board' => ($state['storyboard_shape'] ?? null) === ScenePlanAuthor::SHOT_SHAPE,
            'camera_relation' => is_string($video['camera_relation'] ?? null) ? $video['camera_relation'] : null,
            'duration_seconds' => is_int($video['duration_ms'] ?? null) && $video['duration_ms'] % 1000 === 0
                ? intdiv($video['duration_ms'], 1000)
                : null,
            'beat_coverage' => array_values(array_filter((array) ($state['beat_coverage'] ?? []), 'is_array')),
            'objects_start' => $this->visibleObjects($state, 'objects_start', ['objects_first_frame']),
            'objects_end' => $this->visibleObjects($state, 'objects_end', ['objects_last_frame']),
            'object_moves' => $this->framedObjectMoves($state),
            'reference_requirements' => array_values(array_filter((array) ($state['reference_requirements'] ?? []), 'is_array')),
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

        if ((is_array($stage->input_json) ? $stage->input_json['scene_contract_version'] ?? null : null)
            === ScenePlanAuthor::STORYBOARD_CONTRACT_VERSION) {
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
            'CAMERA: '.self::LOCKED_CAMERA,
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
        $camera = $this->cameraLines(is_array($video['camera'] ?? null) ? $video['camera'] : []);
        $objects = $this->objectLines($this->visibleObjects($state, 'objects_start', ['objects_first_frame']));

        return implode("\n\n", [
            (string) $scene->delta_prompt,
            ...($camera === [] ? [] : [self::CAMERA_LEAD."\n".implode("\n", $camera)]),
            ...($frame === [] ? [] : [self::FRAME_LEAD."\n".implode("\n", $frame)]),
            ...($objects === [] ? [] : [self::OBJECTS_LEAD."\n".implode("\n", $objects)]),
            ...($space === [] ? [] : [self::SPACE_LEAD."\n".implode("\n", $space)]),
            ...($setting === [] ? [] : [self::SETTING_LEAD."\n".implode("\n", $setting)]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $camera
     * @return list<string>
     */
    private function cameraLines(array $camera): array
    {
        $lines = [];

        foreach (['position' => 'Position', 'elevation' => 'Height', 'framing' => 'Framing'] as $field => $label) {
            if (is_string($camera[$field] ?? null) && trim($camera[$field]) !== '') {
                $lines[] = $label.': '.trim($camera[$field]);
            }
        }

        if (is_string($camera['subject_side'] ?? null)) {
            $lines[] = 'Side of the subject facing the camera: '.$camera['subject_side'];
        }

        return $lines;
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
