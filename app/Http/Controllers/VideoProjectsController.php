<?php

namespace App\Http\Controllers;

use App\Enums\AnchorStage;
use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\ImageVariations;
use App\Enums\PromptProducer;
use App\Enums\SceneStep;
use App\Form\AdminCustomValidator;
use App\Models\Admin;
use App\Models\VideoProject;
use App\Services\VideoProjectService;
use App\Video\Concept\Viewpoint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class VideoProjectsController extends Controller
{
    private VideoProjectService $videoProjectService;

    private AdminCustomValidator $form;

    public function __construct(VideoProjectService $videoProjectService, AdminCustomValidator $form)
    {
        $this->videoProjectService = $videoProjectService;
        $this->form = $form;
    }

    public function store(string $articleId)
    {
        [$project, $reason] = $this->videoProjectService->getdataByArticleId($articleId, $this->actor());

        if ($project === null) {
            return redirect()->route('article.index')->with('error', $reason);
        }

        return redirect()
            ->route('video-projects.anchor', $project->id)
            ->with('success', __('messages.add_success'));
    }

    public function index()
    {
        $projects = $this->videoProjectService->listAll($this->actor());

        return view('video-projects.index', [
            'route' => 'video-projects',
            'action' => 'video-projects-index',
            'menu' => 'menu-open',
            'active' => 'active',
            'projects' => $projects,
        ]);
    }

    public function anchor(string $id)
    {
        $project = $this->videoProjectService->getdataByprojectId($id);

        if ($project !== null) {
            $this->authorizeProject($project);
        }

        if ($project === null) {
            return redirect()->route('video-projects.index')
                ->with('error', __('messages.project_not_found'));
        }
        $brief = $this->videoProjectService->latestInspiration($project->id);
        $concept = $this->videoProjectService->latestConcept($project->id);
        $promptPreview = $this->videoProjectService->anchorPromptPreview($project->id);
        $selectedModel = ImageModel::tryFrom((string) old('model', $promptPreview['lineage']['model'] ?? ''));
        $selectedQuality = ImageQuality::tryFrom((string) old('quality', ''));
        $selectedStage = AnchorStage::FABRICATION_GEOMETRY_ANCHOR;
        // $selectedViewpoint = Viewpoint::tryFrom((string) old('viewpoint', $promptPreview['viewpoint'] ?? ''));
        $selectedSize = ImageSize::tryFrom((string) old('size', $promptPreview['size'] ?? ''));
        $selectedVariations = ImageVariations::tryFrom((int) old('variations', 0));
        $selectedProducer = PromptProducer::tryFrom((string) old('producer', ''))
            ?? $this->producerOfPreview($promptPreview);

        $compiledPrompt = null;
        $compiledPromptHash = null;
        $compileReason = 'choose_prompt_settings';

        if ($promptPreview !== null) {
            $compiledPrompt = $promptPreview['prompt'];
            $compiledPromptHash = hash('sha256', $promptPreview['prompt']);
            $compileReason = 'ok';
        }

        $compileReason = $this->anchorMessage($compileReason);
        $nextImageCode = $this->videoProjectService->nextImageCode($project->id, (string) auth()->user()?->name);

        return view('video-projects.anchor', [
            'route' => 'video-projects',
            'action' => 'video-projects-index',
            'menu' => 'menu-open',
            'active' => 'active',
            'project' => $project,
            'brief' => $brief,
            'concept' => $concept,
            'compiledPrompt' => $compiledPrompt,
            'compiledPromptHash' => $compiledPromptHash,
            'compileReason' => $compileReason,
            'nextImageCode' => $nextImageCode,
            'selectedModel' => $selectedModel,
            'selectedQuality' => $selectedQuality,
            'selectedStage' => $selectedStage,
            // 'selectedViewpoint' => $selectedViewpoint,
            'viewpointLabels' => [
                Viewpoint::FrontThreeQuarter->value => 'Front three-quarter',
                Viewpoint::Side->value => 'Side profile',
                Viewpoint::RearThreeQuarter->value => 'Rear three-quarter',
            ],
            'selectedSize' => $selectedSize,
            'selectedVariations' => $selectedVariations,
            'selectedProducer' => $selectedProducer,
            'previewProducer' => $this->producerOfPreview($promptPreview),
            'previewPromptVersion' => $promptPreview['lineage']['prompt_version'] ?? null,
            'anchorPrompt' => $this->videoProjectService->latestAnchorPrompt($project->id),
            'anchorCells' => $this->videoProjectService->anchorCells($project->id),
            'prompt' => null,
            'reason' => 'chua sinh',
        ]);
    }

    public function inspiration(string $id)
    {
        $this->ownedProject($id);

        [$brief, $reason] = $this->videoProjectService->runInspiration($id);
        if ($brief === null) {
            return back()->with('error', $reason);
        }

        return back()->with('success', $reason === 'cached'
            ? 'Nội dung bài không đổi — dùng lại kết quả cũ, không gọi Claude.'
            : __('messages.inspiration_done'));
    }

    public function resetInspiration(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = $this->videoProjectService->resetInspiration($id);

        return $done
            ? back()->with('success', 'Đã reset — bấm Gọi Haiku để chạy lại.')
            : back()->with('error', $reason);
    }

    public function concept(string $id)
    {
        $this->ownedProject($id);

        $stage = AnchorStage::FABRICATION_GEOMETRY_ANCHOR;
        $viewpoint = Viewpoint::FrontThreeQuarter;
        $size = ImageSize::LANDSCAPE;

        [$compiled, $reason, $concept] = $this->videoProjectService->compiledAnchorPrompt(
            $id, $stage, $viewpoint, $size, ImageModel::GPT_IMAGE_2, PromptProducer::SKILL,
        );

        if ($compiled === null) {
            return back()->with('error', $this->anchorMessage($reason));
        }

        $this->videoProjectService->storeAnchorPromptPreview(
            $id, $stage, $viewpoint, $size, $compiled, $concept ?? [],
        );

        return back()->with('success', $reason === 'cached'
            ? 'Brief không đổi — dùng lại prompt cũ, không gọi model.'
            : 'Đã viết prompt bằng gpt-5.6-terra.');
    }

    /**
     * Nut RIENG chu khong phai mot co trong body: mot duong dan tieu tien phai
     * nhin thay duoc tu bang route, khong an trong tham so.
     */
    public function rerunConcept(string $id)
    {
        $this->ownedProject($id);

        [$concept, $reason] = $this->videoProjectService->runConcept($id, true);

        if ($concept === null) {
            return back()->with('error', $reason);
        }

        return back()->with('success', __('messages.concept_rebuilt'));
    }

    public function resetConcept(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = $this->videoProjectService->resetConcept($id);

        return $done
            ? back()->with('success', 'Đã reset — bấm Creat Prompt để chạy lại.')
            : back()->with('error', $reason);
    }

    public function compileAnchorPrompt(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'AnchorPromptForm');

        $stage = AnchorStage::FABRICATION_GEOMETRY_ANCHOR;
        $viewpoint = Viewpoint::from($data['viewpoint']);
        $size = ImageSize::from($data['size']);
        $model = ImageModel::from($data['model']);
        $producer = PromptProducer::from($data['producer']);

        [$compiled, $reason, $concept] = $this->videoProjectService->compiledAnchorPrompt(
            $id, $stage, $viewpoint, $size, $model, $producer,
        );

        if ($compiled === null || $concept === null) {
            return back()->withInput()->with('error', $this->anchorMessage($reason));
        }

        $this->videoProjectService->storeAnchorPromptPreview($id, $stage, $viewpoint, $size, $compiled, $concept);

        return back();
    }

    public function createAnchorImage(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'AnchorImageForm');

        [$image, $reason] = $this->videoProjectService->renderAnchorFromPreview(
            $id,
            (string) auth()->user()?->name,
            (string) $data['prompt_sha256'],
            ImageSize::from($data['size']),
            ImageModel::from($data['model']),
            ImageQuality::from($data['quality']),
            ImageVariations::from((int) $data['variations']),
        );

        if ($image === null) {
            return back()->with('error', $this->anchorMessage($reason));
        }

        return back()->with(
            in_array($reason, ['rendered', 'queued'], true) ? 'success' : 'error',
            $this->renderOutcome($reason, $image),
        );
    }

    public function renderDesignImage(string $id, string $imageId)
    {
        $this->ownedProject($id);

        [$image, $reason] = $this->videoProjectService->renderDesignImage($id, $imageId);

        if ($image === null) {
            return back()->with('error', $this->anchorMessage($reason));
        }

        return back()->with(
            in_array($reason, ['rendered', 'queued'], true) ? 'success' : 'error',
            $this->renderOutcome($reason, $image),
        );
    }

    public function approveAnchor(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'AnchorApproveForm');

        [$done, $reason] = $this->videoProjectService->approveAnchor(
            $id, (string) $data['artifact_id'], auth()->id(),
        );

        return $done
            ? redirect()
                ->route('video-projects.reference', $id)
                ->with('success', __('messages.anchor_approved'))
            : back()->with('error', $this->anchorMessage($reason));
    }

    /**
     * Mot bang cau chu dung chung cho ca hai nut. Nut khoi 1 tao o roi render,
     * nut khoi 2 render mot o da co — nhung ket qua tra ve la cung mot tap ma,
     * va hai bang cau chu song song thi som muon cung lech nhau.
     */
    private function renderOutcome(string $reason, \App\Models\VideoDesignImage $image): string
    {
        return match ($reason) {
            'rendered' => __('messages.anchor_image_rendered', ['code' => $image->image_code]),
            'failed' => __('messages.anchor_image_failed', [
                'code' => $image->image_code,
                'reason' => (string) $image->render_error,
            ]),
            'timed_out' => __('messages.anchor_image_timed_out', ['code' => $image->image_code]),
            'queued' => __('messages.anchor_render_queued', ['code' => $image->image_code]),
            'already_queued' => __('messages.anchor_render_already_queued', ['code' => $image->image_code]),
            'not_enqueueable' => __('messages.anchor_render_not_enqueueable', ['code' => $image->image_code]),
            'already_exists' => __('messages.reference_already_exists', ['code' => $image->image_code]),
            'edit_not_supported_by_worker' => __('messages.edit_not_supported_by_worker', ['code' => $image->image_code]),
            default => $reason,
        };
    }

    private function anchorMessage(string $reason): string
    {
        return match ($reason) {
            'project_not_found' => __('messages.project_not_found'),
            'image_not_found' => __('messages.anchor_image_not_found'),
            'no_category' => __('messages.anchor_no_category'),
            'no_concept' => __('messages.anchor_no_concept'),
            'choose_prompt_settings' => __('messages.anchor_choose_prompt_settings'),
            'anchor_prompt_missing' => __('messages.anchor_prompt_missing'),
            'anchor_prompt_stale' => __('messages.anchor_prompt_stale'),
            'no_inspiration_brief' => __('messages.anchor_no_inspiration_brief'),
            'anchor_setting_required' => __('messages.anchor_setting_required', ['field' => 'Model']),
            'artifact_not_found' => __('messages.anchor_artifact_not_found'),
            'not_approvable' => __('messages.anchor_not_approvable'),
            'no_approved_anchor' => __('messages.no_approved_anchor'),
            'no_scene_profile' => __('messages.scene_no_profile'),
            'identity_summary_unreadable' => __('messages.scene_identity_unreadable'),
            'scene_plan_running' => __('messages.scene_plan_running'),
            'scene_plan_unchanged' => __('messages.scene_plan_unchanged'),
            'scene_plan_failed' => __('messages.scene_plan_failed'),
            'scene_plan_failed_unrecorded' => __('messages.scene_plan_failed_unrecorded'),
            'scene_plan_claim_lost' => __('messages.scene_plan_claim_lost'),
            'scene_plan_misconfigured' => __('messages.scene_plan_misconfigured'),
            'scene_review_misconfigured' => __('messages.scene_review_misconfigured'),
            'artifact_file_not_found' => __('messages.artifact_file_not_found'),
            'artifact_checksum_mismatch' => __('messages.artifact_checksum_mismatch'),
            'approved' => __('messages.reference_approved'),
            'image_type_mismatch' => __('messages.reference_wrong_image_type'),
            'scene_keyframe_needs_scene_flow' => __('messages.scene_keyframe_needs_scene_flow'),
            'no_environment_profile' => __('messages.environment_no_profile'),
            'unknown_environment_key' => __('messages.environment_unknown_key'),
            'environment_media_models_broken' => __('messages.environment_media_models_broken'),
            'environment_unknown_media_model' => __('messages.environment_unknown_media_model'),
            'environment_media_setting_invalid' => __('messages.environment_media_setting_invalid'),
            default => $reason,
        };
    }

    public function approveReference(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'AnchorApproveForm');

        [$done, $reason] = $this->videoProjectService->approveReference(
            $id, (string) $data['artifact_id'], auth()->id(),
        );

        return back()->with($done ? 'success' : 'error', $this->anchorMessage($reason));
    }

    public function reference(Request $request, string $id)
    {
        $this->ownedProject($id);

        if ($request->isMethod('post')) {
            return $this->createReference($request, $id);
        }

        $data = $this->videoProjectService->referencePageData($id);

        if ($data === null) {
            return redirect()->route('video-projects.index')
                ->with('error', __('messages.project_not_found'));
        }

        return view('video-projects.reference', $this->chrome() + $data);
    }

    private function createReference(Request $request, string $id)
    {
        $data = $this->form->validate($request, 'ReferenceImageForm');

        [$image, $reason] = $this->videoProjectService->renderReferenceDirect(
            $id, (string) auth()->user()?->name, $data,
        );

        if ($image === null) {
            return back()->with('error', $this->anchorMessage($reason));
        }

        $level = in_array($reason, ['rendered', 'already_exists'], true) ? 'success' : 'error';

        return redirect()
            ->route('video-projects.reference', $id)
            ->with($level, $this->renderOutcome($reason, $image));
    }

    public function approveEnvironment(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'AnchorApproveForm');

        [$done, $reason] = $this->videoProjectService->approveEnvironmentReference(
            $id, (string) $data['artifact_id'], auth()->id(),
        );

        return back()->with($done ? 'success' : 'error', $this->anchorMessage($reason));
    }

    public function environment(Request $request, string $id)
    {
        $this->ownedProject($id);

        if ($request->isMethod('post')) {
            return $this->createEnvironment($request, $id);
        }

        $data = $this->videoProjectService->environmentPageData($id);

        if ($data === null) {
            return redirect()->route('video-projects.index')
                ->with('error', __('messages.project_not_found'));
        }

        return view('video-projects.environment', $this->chrome() + $data);
    }

    private function createEnvironment(Request $request, string $id)
    {
        try {
            $data = $this->form->validate($request, 'EnvironmentImageForm');
        } catch (InvalidArgumentException $e) {
            Log::error('environment: registry model hong khi validate', ['error' => $e->getMessage()]);

            return back()->with('error', $this->anchorMessage('environment_media_models_broken'));
        }

        [$image, $reason] = $this->videoProjectService->renderEnvironmentDirect(
            $id, (string) auth()->user()?->name, $data,
        );

        if ($image === null) {
            return back()->with('error', $this->anchorMessage($reason));
        }

        $level = in_array($reason, ['rendered', 'already_exists'], true) ? 'success' : 'error';

        return redirect()
            ->route('video-projects.environment', $id)
            ->with($level, $this->renderOutcome($reason, $image));
    }

    /**
     * Prompt do bo nao sinh: doc tu lineage da luu, khong doan.
     *
     * @param  array<string, mixed>|null  $preview
     */
    private function producerOfPreview(?array $preview): PromptProducer
    {
        $version = (string) ($preview['lineage']['prompt_version'] ?? '');
        $canonicalHash = (string) ($preview['lineage']['canonical_hash'] ?? '');

        return $version !== '' && $canonicalHash === ''
            ? PromptProducer::SKILL
            : PromptProducer::CANONICAL;
    }

    public function scene(string $id, ?string $scene = null, ?string $step = null)
    {
        $project = $this->ownedProject($id);

        $stage = SceneStep::tryFrom((string) ($step ?? SceneStep::PLANNING->value));

        abort_if($stage === null, 404);

        $plan = $this->videoProjectService->latestScenePlan($id);
        $scenes = $plan['scenes'];
        $current = $scene === null
            ? ($scenes[0] ?? null)
            : collect($scenes)->firstWhere('id', $scene);

        abort_if($scenes !== [] && $current === null, 404);

        $keyframes = $this->videoProjectService->sceneKeyframeCells($id, $plan['revision']);

        return view('video-projects.scene', $this->chrome() + [
            'id' => $id,
            'project' => $project,
            'step' => $stage,
            'scenes' => $scenes,
            'scene' => $current,
            'revision' => $plan['revision'],
            'warnings' => $plan['warnings'],
            'records' => $plan['records'],
            'unpriced' => $plan['unpriced'],
            'profileNotice' => $plan['profile_notice'],
            'preservationNotice' => $plan['preservation_notice'],
            'review' => $plan['review'],
            'keyframes' => $keyframes,
            'referenceRoles' => [
                'identity' => 'Identity view',
                'environment' => 'Environment',
                'geometry' => 'Supporting view',
            ],
            'sources' => collect($this->videoProjectService->sceneSourceCells($id, $plan['revision']))
                ->map(fn (array $cell): array => array_replace($cell, [
                    'blocked_code' => $cell['blocked_reason'] === null
                        ? null
                        : explode('|', (string) $cell['blocked_reason'], 2)[0],
                    'blocked_reason' => $cell['blocked_reason'] === null
                        ? null
                        : $this->sceneKeyframeMessage((string) $cell['blocked_reason']),
                ]))
                ->all(),
            'summary' => [
                'approved' => collect($keyframes)
                    ->filter(static fn (array $cell): bool => $cell['approved'] !== null)
                    ->count(),
            ],
        ]);
    }

    public function planScenes(Request $request, string $id)
    {
        $this->ownedProject($id);

        [$count, $reason] = $this->videoProjectService->planScenes(
            $id, auth()->id(), $request->boolean('force'),
        );

        if ($count === null) {
            return back()->with('error', $this->anchorMessage($reason));
        }

        return $reason === 'ok_needs_review'
            ? back()->with('warning', __('messages.scene_plan_needs_review', ['count' => $count]))
            : back()->with('success', __('messages.scene_plan_done', ['count' => $count]));
    }

    private function actor(): ?Admin
    {
        $user = auth()->user();

        return $user instanceof Admin ? $user : null;
    }

    private function authorizeProject(VideoProject $project): void
    {
        $actor = $this->actor();

        if ($actor === null || Gate::forUser($actor)->denies('update', $project)) {
            abort(403);
        }
    }

    public function sceneKeyframePreview(Request $request, string $id, string $sceneId)
    {
        $this->ownedProject($id);

        [$preview, $reason] = $this->videoProjectService->sceneImagePreview(
            $id, $this->actorId(), $sceneId,
        );

        return $this->keyframeJson($preview, $reason);
    }

    public function sceneKeyframeState(string $id, string $image)
    {
        $this->ownedProject($id);

        [$preview, $reason] = $this->videoProjectService->previewFromCandidate(
            $id, $this->actorId(), $image,
        );

        return $this->keyframeJson($preview, $reason);
    }

    public function renderSceneKeyframe(Request $request, string $id, string $sceneId)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'SceneKeyframeRenderForm');

        [$image, $reason] = $this->videoProjectService->renderSceneImage(
            $id,
            $this->actorId(),
            $sceneId,
            (string) $data['prompt_sha256'],
            $data['anchor_artifact_id'] ?? null,
        );

        return back()->with(
            $this->keyframeOutcome($image, $reason),
            $this->sceneKeyframeMessage($reason),
        );
    }

    public function retrySceneKeyframe(Request $request, string $id, string $image)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'SceneKeyframeRenderForm');

        [$done, $reason] = $this->videoProjectService->resumeSceneCandidate(
            $id, $this->actorId(), $image, (string) $data['prompt_sha256'],
        );

        return back()->with(
            $this->keyframeOutcome($done, $reason),
            $this->sceneKeyframeMessage($reason),
        );
    }

    public function approveSceneKeyframe(Request $request, string $id, string $image)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'SceneKeyframeApproveForm');

        [$done, $reason] = $this->videoProjectService->approveSceneKeyframe(
            $id, $this->actorId(), $image, (string) $data['artifact_id'],
        );

        return back()->with($done ? 'success' : 'error', $this->sceneKeyframeMessage($reason));
    }

    private function actorId(): ?string
    {
        $actor = auth()->id();

        return $actor === null ? null : (string) $actor;
    }

    /** @param array<string, mixed>|null $preview */
    private function keyframeJson(?array $preview, string $reason)
    {
        return $preview === null
            ? response()->json([
                'ok' => false,
                'reason' => $reason,
                'message' => $this->sceneKeyframeMessage($reason),
            ], 422)
            : response()->json(['ok' => true, 'reason' => $reason, 'preview' => $preview]);
    }

    private function keyframeOutcome(?object $image, string $reason): string
    {
        return $image !== null && in_array($reason, ['rendered', 'already_exists'], true)
            ? 'success'
            : 'error';
    }

    /**
     * Reason co the mang mot chi tiet sau dau `|` — ten tam nen chang han —
     * de man hinh noi duoc "Chua duyet Paint shed" thay vi mot ma chung chung.
     */
    private function sceneKeyframeMessage(string $reason): string
    {
        [$code, $detail] = array_pad(explode('|', $reason, 2), 2, null);

        $key = 'messages.scene_keyframe_'.$code;
        $text = __($key, $detail === null ? [] : ['name' => $detail]);

        return $text === $key ? $reason : $text;
    }

    private function ownedProject(string $id): VideoProject
    {
        $project = $this->videoProjectService->getdataByprojectId($id);

        abort_if($project === null, 404);

        $this->authorizeProject($project);

        return $project;
    }

    /** @return array<string, string> */
    private function chrome(): array
    {
        return [
            'route' => 'video-projects',
            'action' => 'admin-video-projects',
            'menu' => 'menu-open',
            'active' => 'active',
        ];
    }
}
