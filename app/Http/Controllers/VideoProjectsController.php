<?php

namespace App\Http\Controllers;

use App\Enums\AnchorStage;
use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\ImageVariations;
use App\Enums\SceneStep;
use App\Form\AdminCustomValidator;
use App\Models\Admin;
use App\Models\VideoProject;
use App\Models\VideoRender;
use App\Models\VideoShot;
use App\Services\VideoProjectService;
use App\Video\Concept\Viewpoint;
use App\Video\Render\Video\SceneClipDispatchService;
use App\Video\Render\Video\SceneShotFactory;
use App\Video\Render\Video\VideoRenderExecutionService;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class VideoProjectsController extends Controller
{
    private VideoProjectService $videoProjectService;

    private AdminCustomValidator $form;

    private SceneClipDispatchService $clips;

    private VideoRenderExecutionService $clipExecution;

    private SceneShotFactory $shots;

    public function __construct(
        VideoProjectService $videoProjectService,
        AdminCustomValidator $form,
        SceneClipDispatchService $clips,
        VideoRenderExecutionService $clipExecution,
        SceneShotFactory $shots,
    ) {
        $this->videoProjectService = $videoProjectService;
        $this->form = $form;
        $this->clips = $clips;
        $this->clipExecution = $clipExecution;
        $this->shots = $shots;
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
        $promptPreview = $this->videoProjectService->anchorPromptPreview($project->id);
        $selectedModel = ImageModel::tryFrom((string) old('model', $promptPreview['lineage']['model'] ?? ''));
        $selectedQuality = ImageQuality::tryFrom((string) old('quality', ''));
        $selectedStage = AnchorStage::FABRICATION_GEOMETRY_ANCHOR;
        // $selectedViewpoint = Viewpoint::tryFrom((string) old('viewpoint', $promptPreview['viewpoint'] ?? ''));
        $selectedSize = ImageSize::tryFrom((string) old('size', $promptPreview['size'] ?? ''));
        $selectedVariations = ImageVariations::tryFrom((int) old('variations', 0));

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
            'compiledPrompt' => $compiledPrompt,
            'compiledPromptHash' => $compiledPromptHash,
            'compileReason' => $compileReason,
            'nextImageCode' => $nextImageCode,
            'selectedModel' => $selectedModel,
            'selectedQuality' => $selectedQuality,
            'selectedStage' => $selectedStage,
            // 'selectedViewpoint' => $selectedViewpoint,
            // 'viewpointLabels' => [
            //     Viewpoint::FrontThreeQuarter->value => 'Front three-quarter',
            //     Viewpoint::Side->value => 'Side profile',
            //     Viewpoint::RearThreeQuarter->value => 'Rear three-quarter',
            // ],
            'selectedSize' => $selectedSize,
            'selectedVariations' => $selectedVariations,
            'previewPromptVersion' => $promptPreview['lineage']['prompt_version'] ?? null,
            'screenplay' => $this->videoProjectService->latestScreenplay($project->id),
            'screenplayFoundation' => $this->videoProjectService->latestScreenplayFoundation($project->id),
            'anchorPrompt' => $this->videoProjectService->latestAnchorPrompt($project->id),
            'anchorCells' => $this->videoProjectService->anchorCells($project->id),
            // 'prompt' => null,
            // 'reason' => 'chua sinh',
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

        [$compiled, $reason, $concept] = $this->videoProjectService->compiledAnchorPrompt($id);

        if ($compiled === null) {
            return back()->with('error', $this->anchorMessage($reason));
        }

        $this->videoProjectService->storeAnchorPromptPreview(
            $id,
            AnchorStage::FABRICATION_GEOMETRY_ANCHOR,
            Viewpoint::FrontThreeQuarter,
            ImageSize::LANDSCAPE,
            $compiled,
            $concept ?? [],
        );

        return back()->with('success', $reason === 'cached'
            ? 'Brief không đổi — dùng lại prompt cũ, không gọi model.'
            : 'Đã viết prompt bằng gpt-5.6-terra.');
    }

    public function screenplayFoundation(Request $request, string $id)
    {
        $this->ownedProject($id);

        $force = $request->boolean('force');

        [$foundation, $reason] = $this->videoProjectService->authorScreenplayFoundation($id, $force);

        if ($foundation === null) {
            return back()->with('error', $this->anchorMessage($reason));
        }

        return back()->with('success', $reason === 'cached'
            ? 'Brief không đổi — dùng lại nội dung đã có, không gọi model.'
            : ($force ? 'Đã tạo bản nội dung mới. Chưa phân cảnh.' : 'Đã viết nội dung kịch bản. Chưa phân cảnh.'));
    }

    public function resetScreenplayFoundation(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = $this->videoProjectService->resetScreenplayFoundation($id);

        return $done
            ? back()->with('success', 'Đã reset — bấm Viết nội dung kịch bản để chạy lại.')
            : back()->with('error', $reason);
    }

    public function screenplay(string $id)
    {
        $this->ownedProject($id);

        [$screenplay, $reason] = $this->videoProjectService->authorScreenplay($id);
        // dd([$screenplay, $reason]);

        if ($screenplay === null) {
            return back()->with('error', $this->anchorMessage($reason));
        }

        if ($reason === 'ok_needs_review') {
            return back()->with('warning', 'Đã viết kịch bản — có cảnh báo biên tập, đọc phần cảnh báo bên dưới.');
        }

        return back()->with('success', $reason === 'cached'
            ? 'Brief không đổi — dùng lại kịch bản cũ, không gọi model.'
            : 'Đã viết kịch bản bằng Claude Sonnet 5.');
    }

    public function resetScreenplay(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = $this->videoProjectService->resetScreenplay($id);

        return $done
            ? back()->with('success', 'Đã reset — bấm Viết kịch bản để chạy lại.')
            : back()->with('error', $reason);
    }

    public function resetConcept(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = $this->videoProjectService->resetConcept($id);

        return $done
            ? back()->with('success', 'Đã reset — bấm Creat Prompt để chạy lại.')
            : back()->with('error', $reason);
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
            $reason === 'rendered' ? 'success' : 'error',
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
            $reason === 'rendered' ? 'success' : 'error',
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
            'already_queued' => __('messages.anchor_render_already_queued', ['code' => $image->image_code]),
            'not_enqueueable' => __('messages.anchor_render_not_enqueueable', ['code' => $image->image_code]),
            'already_exists' => __('messages.reference_already_exists', ['code' => $image->image_code]),
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
            'no_screenplay_profile' => 'Chủ đề này chưa có profile kịch bản.',
            'screenplay_profile_contract_mismatch' => __('messages.screenplay_profile_contract_mismatch'),
            'screenplay_profile_invalid' => __('messages.screenplay_profile_invalid'),
            'screenplay_contract_unsupported' => __('messages.screenplay_contract_unsupported'),
            'screenplay_schema_invalid' => __('messages.screenplay_schema_invalid'),
            'screenplay_call_failed' => __('messages.screenplay_call_failed'),
            'screenplay_timeout' => __('messages.screenplay_timeout'),
            'screenplay_connection_failed' => __('messages.screenplay_connection_failed'),
            'screenplay_author_failed' => __('messages.screenplay_author_failed'),
            'screenplay_after_response_failed' => __('messages.screenplay_after_response_failed'),
            'screenplay_result_not_stored' => __('messages.screenplay_result_not_stored'),
            'inspiration_carries_source_facts' => 'Brief còn dữ kiện nguồn (số đo, ngày, tên) — chưa gọi model, xem log để biết trường nào.',
            'screenplay_invalid' => 'Kịch bản vi phạm hợp đồng cấu trúc — nguyên văn và chi phí đã được lưu, xem log.',
            'screenplay_running' => 'Đang có một lượt viết kịch bản chạy cho dự án này.',
            'screenplay_claim_lost' => 'Mất claim khi lưu — kết quả đã trả tiền không được ghi.',
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

    /**
     * Man hinh render video: mot hang moi scene, khong lan voi luoi anh cua trang
     * Scenes. Kem mot khoi xem truoc DAN NHAN dung la du lieu gia, de nhin duoc bo
     * cuc khi DB chua co shot nao.
     */
    public function clips(string $id)
    {
        $project = $this->ownedProject($id);

        $plan = $this->videoProjectService->latestScenePlan($id);
        $revision = (int) $plan['revision'];
        $scenes = $plan['scenes'] ?? [];
        $clips = $this->videoProjectService->sceneClipCells($id, $revision);
        $keyframes = $this->videoProjectService->sceneKeyframeCells($id, $revision);

        return view('video-projects.show', $this->chrome() + [
            'id' => $id,
            'project' => $project,
            'scenes' => $scenes,
            'clips' => $clips,
            'keyframes' => $keyframes,
            'clipModels' => $this->clipModels(),
            'summary' => $this->clipSummary($scenes, $clips, $keyframes),
        ]);
    }

    /**
     * Composition screen. It reads real clips and real finals, but still has no
     * render or persistence action while the final-composition contract is being
     * built — every control on it is inert on purpose.
     */
    public function finalCompositionPreview(string $id)
    {
        $project = $this->ownedProject($id);

        return view('video-projects.final-composition-preview', $this->chrome() + [
            'id' => $id,
            'project' => $project,
            'composition' => $this->videoProjectService->finalCompositionCells($id),
        ]);
    }

    /**
     * Ghep ban final. CHAY DONG BO ngay trong request.
     *
     * Khong dung queue o buoc nay, nen thoi gian chay phai co tran: ngan sach nam o
     * `video.veo.compose_budget_seconds`, va no phai thap hon gioi han cua may chu
     * web. Mot vong polling tren trinh duyet KHONG giu cho tien trinh PHP song.
     */
    public function renderFinalComposition(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate([
            'size' => ['required', 'string', Rule::in(\App\Video\FinalComposition\CompositionPlanBuilder::SIZES)],
            'fps' => 'required|integer|in:24,25,30',
            'crf' => 'required|integer|between:16,28',
            'crossfade' => 'nullable|boolean',
        ]);

        [$width, $height] = array_map('intval', explode('x', $data['size']));
        $fps = (int) $data['fps'];

        $result = $this->videoProjectService->renderFinalComposition($id, [
            'width' => $width,
            'height' => $height,
            'fps' => $fps,
            'crf' => (int) $data['crf'],
            // 0,5 giay quy ra KHUNG o dung fps da chon: chong lan phai roi vao bien
            // khung, va mot con so mili giay se phai lam tron o dau do.
            'crossfade_frames' => $request->boolean('crossfade') ? (int) round($fps / 2) : 0,
        ]);

        if ($result['ok']) {
            return back()->with('success', 'Ghep xong ban final.');
        }

        // Ly do tu verifier rat cu the (thieu khung, sai profile, lech tieng) — dua
        // ca ra thay vi rut gon thanh "that bai", vi do la thu noi duoc phai sua gi.
        return back()->with('error', trim(
            $result['error'].(isset($result['reasons']) ? ': '.implode(' | ', $result['reasons']) : ''),
        ));
    }

    /**
     * Phat/tai file final. Di qua day chu khong qua `public/`: file nam ngoai thu muc
     * cong khai, nen quyen doc no phai la quyen doc DU AN.
     */
    public function finalCompositionFile(string $id, string $finalId)
    {
        $this->ownedProject($id);

        $final = \App\Models\VideoFinal::query()
            ->whereKey($finalId)
            ->whereHas('session', fn ($scope) => $scope->where('project_id', $id))
            ->firstOrFail();

        // `realpath` CA HAI dau truoc khi so. `storage_path()` tra ve dau phan cach
        // lan (`storage\app/video-compose-final`) con `realpath` chuan hoa het ve
        // `\` — so mot ben da chuan hoa voi mot ben chua thi luon truot, va ket qua
        // la 404 cho mot file co that.
        $root = realpath((string) config('video.veo.compose_final_dir'));

        abort_if($root === false, 404);

        $root = rtrim($root, '/\\').DIRECTORY_SEPARATOR;
        $path = realpath($root.(string) $final->video_path);

        // So tien to KEM dau phan cach: mot `video_path` doc hai khong duoc dan ra
        // ngoai thu muc ket qua.
        abort_if($path === false || ! str_starts_with($path, $root) || ! is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'video/mp4',
            // Trinh duyet doi seek duoc moi ve duoc khung hinh dau.
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $scenes
     * @param  array<string, array<string, mixed>>  $clips
     * @param  array<string, array<string, mixed>>  $keyframes
     * @return array{with_keyframe: int, running: int, done: int, failed: int}
     */
    private function clipSummary(array $scenes, array $clips, array $keyframes): array
    {
        $summary = ['with_keyframe' => 0, 'running' => 0, 'done' => 0, 'failed' => 0];

        foreach ($scenes as $scene) {
            $key = (string) ($scene['scene_id'] ?? '');

            if (($keyframes[$key]['approved'] ?? null) !== null) {
                $summary['with_keyframe']++;
            }

            $status = $clips[$key]['status'] ?? null;

            $summary['running'] += in_array($status, ['submitting', 'submitted', 'provider_running', 'polling'], true) ? 1 : 0;
            $summary['done'] += $status === 'succeeded' ? 1 : 0;
            $summary['failed'] += $status === 'failed' ? 1 : 0;
        }

        return $summary;
    }


    /**
     * Mot luot: sinh shot cho scene (neu chua co), dong bang anh da duyet lam nguon,
     * tao hang render roi submit ngay.
     *
     * Khong cho san hang `queued` chua ai dinh gui: mot o nhu the la mot o treo.
     */
    public function renderSceneClip(Request $request, string $id, string $sceneId)
    {
        $this->ownedProject($id);

        // MOI thu nam trong try, ke ca validate va tra cuu scene. Mot ngoai le lot ra
        // ngoai se thanh trang loi HTML, ma man hinh lai doc JSON — luc do no that bai
        // IM LANG: hang tu reset, khong ai biet vi sao.
        try {
            $data = $this->form->validate($request, 'SceneClipRenderForm');

            $plan = $this->videoProjectService->latestScenePlan($id);
            $revision = (int) $plan['revision'];

            $scene = \App\Models\VideoRenderScene::query()
                ->whereKey($sceneId)
                ->where('project_id', $id)
                ->where('revision', $revision)
                ->first();

            if ($scene === null) {
                throw new RuntimeException('Scene khong thuoc ban ke hoach dang hien cua du an nay.');
            }

            $row = collect($plan['scenes'] ?? [])->firstWhere('scene_id', $sceneId);
            $approved = $this->videoProjectService->sceneKeyframeCells($id, $revision)[$sceneId]['approved'] ?? null;

            if ($row === null) {
                throw new RuntimeException('Scene nay khong nam trong ban ke hoach dang hien.');
            }

            if ($approved === null) {
                throw new RuntimeException('Scene chua co anh duyet — duyet o man hinh Scenes truoc.');
            }

            $source = \App\Models\VideoArtifact::query()
                ->whereKey($approved['selected_artifact_id'])
                ->where('design_image_id', $approved['id'])
                ->first();

            if ($source === null) {
                throw new RuntimeException('Khong tim thay file anh da duyet cua scene nay.');
            }

            $shot = $this->shots->forScene($scene, $row);
            $render = $this->clips->create($shot, $source, (string) $data['model_id'], Arr::except($data, 'model_id'));
        } catch (ValidationException $e) {
            return $this->clipRefused($request, implode(' ', $e->validator->errors()->all()), $id, $sceneId);
        } catch (Throwable $e) {
            return $this->clipRefused($request, $e->getMessage(), $id, $sceneId);
        }

        [$ok, $reason] = $this->clipExecution->submit($render->id);

        if ($request->expectsJson()) {
            return response()->json($this->clipState($id, $render->refresh(), $reason));
        }

        return back()->with($ok ? 'status' : 'error', 'Clip: '.$reason);
    }

    /**
     * Mot lan bam bi tu choi van phai NOI RA ly do — va de lai dau vet doc duoc.
     * Khong co dong log nay thi lan hong nao cung chi con mot o tu reset.
     */
    private function clipRefused(Request $request, string $reason, string $id, string $sceneId)
    {
        Log::warning('Clip bi tu choi truoc khi goi provider', [
            'project_id' => $id,
            'scene_id' => $sceneId,
            'reason' => $reason,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'state' => 'idle',
                'reason' => $reason,
                'note' => $reason,
                'poll_url' => null,
                'file_url' => null,
                'meta' => '',
            ], 422);
        }

        return back()->with('error', 'Clip: '.$reason);
    }

    /**
     * Mot dong trang thai du de man hinh ve lai dung o do, khong phai tai lai trang.
     *
     * @return array<string, mixed>
     */
    private function clipState(string $id, VideoRender $render, string $reason): array
    {
        $status = $render->execution_status?->value;
        $running = in_array($status, ['submitting', 'submitted', 'provider_running', 'polling'], true);

        $state = match (true) {
            $status === 'succeeded' => 'succeeded',
            $running => 'running',
            $status === 'failed' => 'failed',
            default => 'idle',
        };

        $note = match ($state) {
            'running' => 'đang dựng · đã hỏi '.$render->provider_poll_count.' lần',
            'failed' => (string) ($render->failure_message ?? $reason),
            'succeeded' => '',
            default => $reason,
        };

        return [
            'state' => $state,
            'reason' => $reason,
            'note' => $note,
            'poll_url' => route('video-projects.scene-clip-poll', [$id, $render->id]),
            'file_url' => $state === 'succeeded'
                ? route('video-projects.scene-clip-file', [$id, $render->id])
                : null,
            'meta' => $render->width && $render->height
                ? $render->width.'×'.$render->height
                    .($render->duration_ms ? ' · '.round($render->duration_ms / 1000, 1).'s' : '')
                : '',
        ];
    }
    /**
     * Clip nam tren disk rieng chu khong phai public, nen no chi ra khoi may qua
     * day — sau khi da kiem du an va kiem hang render thuoc du an do.
     */
    public function sceneClipFile(string $id, string $render)
    {
        $this->ownedProject($id);

        $owned = VideoRender::query()
            ->whereKey($render)
            ->where('render_kind', 'video')
            ->where(fn ($query) => $query
                ->whereHas('shot.session', fn ($scope) => $scope->where('project_id', $id))
                ->orWhereHas('session', fn ($scope) => $scope->where('project_id', $id)))
            ->firstOrFail();

        $disk = app(\Illuminate\Contracts\Filesystem\Factory::class)->disk((string) config('video.veo.disk'));
        $path = (string) $owned->artifact_path;

        abort_if($path === '' || ! $disk->exists($path), 404);

        return $disk->response($path, basename($path), [
            'Content-Type' => 'video/mp4',
            // Trinh duyet doi seek duoc moi ve duoc khung hinh dau; khong noi minh
            // chap nhan range thi the <video> chi hien mot o den.
            'Accept-Ranges' => 'bytes',
        ]);
    }

    public function pollSceneClip(string $id, string $render)
    {
        $this->ownedProject($id);

        // Clip thuoc ve shot (CHECK video_renders_one_owner cho dung mot chu), nen
        // duong so huu di qua shot. Nhanh `session` giu cho hang cu.
        $owned = VideoRender::query()
            ->whereKey($render)
            ->where('render_kind', 'video')
            ->where(fn ($query) => $query
                ->whereHas('shot.session', fn ($scope) => $scope->where('project_id', $id))
                ->orWhereHas('session', fn ($scope) => $scope->where('project_id', $id)))
            ->firstOrFail();

        [$ok, $reason] = $this->clipExecution->poll($owned->id);

        if (request()->expectsJson()) {
            return response()->json($this->clipState($id, $owned->refresh(), $reason));
        }

        return back()->with($ok ? 'status' : 'error', 'Clip: '.$reason);
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

    /**
     * Danh sach model clip duoc phep chon. Rong nghia la chua model nao duoc chung
     * minh bang `video:list-gemini-models` — man hinh phai noi that, khong duoc bay
     * ra mot nut bam vao thi hong.
     *
     * @return list<array{id: string, label: string, default: bool}>
     */
    private function clipModels(): array
    {
        $registry = app(\App\Video\Media\VideoModelRegistry::class);

        if (! $registry->available(SceneClipDispatchService::TASK)) {
            return [];
        }

        try {
            $entries = $registry->forTask(SceneClipDispatchService::TASK);
        } catch (InvalidArgumentException $e) {
            Log::warning('Registry clip hong: '.$e->getMessage());

            return [];
        }

        return array_map(static fn (array $entry): array => [
            'id' => (string) $entry['id'],
            'label' => (string) $entry['label'],
            'default' => $entry['default'] === true,
            'controls' => $entry['controls'],
        ], $entries);
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
