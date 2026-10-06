<?php

namespace App\Http\Controllers;

use App\Enums\AnchorStage;
use App\Enums\DesignImageStatus;
use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\ImageVariations;
use App\Enums\SceneStep;
use App\Form\AdminCustomValidator;
use App\Models\Admin;
use App\Models\VideoProject;
use App\Models\VideoRender;
use App\Services\VideoProjectService;
use App\Video\Concept\Viewpoint;
use App\Video\Render\Video\SceneClipDispatchService;
use App\Video\Render\Video\SceneShotFactory;
use App\Video\Render\Video\VideoRenderExecutionService;
use App\Video\Scene\Services\ShotIntentService;
use App\Video\Scene\Services\ShotSelectionReconciler;
use App\Services\Video\CharacterAnchorPromptService;
use App\Services\Video\ScreenplayExpansionService;
use App\Services\Video\StoryFoundationService;
use App\Services\Video\VesselDesignService;
use App\Video\Screenplay\VesselDesign;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
    private ShotIntentService $shotIntents;
    private ShotSelectionReconciler $shotSelections;
    public CharacterAnchorPromptService $characterAnchorPromptService;
    private ScreenplayExpansionService $screenplayExpansion;

    public function __construct(
        VideoProjectService $videoProjectService,
        AdminCustomValidator $form,
        SceneClipDispatchService $clips,
        VideoRenderExecutionService $clipExecution,
        SceneShotFactory $shots,
        ShotIntentService $shotIntents,
        ShotSelectionReconciler $shotSelections,
        CharacterAnchorPromptService $characterAnchorPromptService,
        ScreenplayExpansionService $screenplayExpansion
    ) {
        $this->screenplayExpansion = $screenplayExpansion;
        $this->videoProjectService = $videoProjectService;
        $this->form = $form;
        $this->clips = $clips;
        $this->clipExecution = $clipExecution;
        $this->shots = $shots;
        $this->shotIntents = $shotIntents;
        $this->shotSelections = $shotSelections;
        $this->characterAnchorPromptService = $characterAnchorPromptService;
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

    public function anchor(Request $request, string $id)
    {
        $project = $this->videoProjectService->getdataByprojectId($id);

        if ($project === null) {
            return redirect()->route('video-projects.index')
                ->with('error', __('messages.project_not_found'));
        }

        $this->authorizeProject($project);
        //Hiển thị data màn hình anchior
        $characters = $this->characterAnchorPromptService->characters($project->id);
        // view image
        $cells = $this->videoProjectService->anchorCells($project->id);
        $primaryObjectId = collect($characters)->firstWhere('kind', 'object')['id'] ?? null;
        $submittedFor = old('size') === null ? null : (string) old('character_id', '');
        $anchorRows = [];

        foreach ($characters === [] ? [null] : $characters as $character) {
            $characterId = $character['id'] ?? null;
            $preview = $this->videoProjectService->anchorPromptPreview($project->id, $characterId);
            $submitted = $submittedFor === (string) $characterId;
            $designed = is_string($character['design_stage_id'] ?? null);
            $stalePrompt = $designed && $preview !== null && ! VesselDesign::stampedFor($preview, $character);
            $preview = $stalePrompt ? null : $preview;
            $rowCells = array_values(array_filter($cells, static fn(array $cell): bool => $character === null
                || ($cell['character_id'] ?? null) === $characterId
                || (($cell['character_id'] ?? null) === null && $characterId === $primaryObjectId)));

            $anchorRows[] = [
                'character' => $character,
                'stale_prompt' => $stalePrompt,
                'conflicts' => $designed && $preview !== null
                    ? $this->characterAnchorPromptService->promptConflicts($preview['anchor_prompt_stage_id'] ?? null)
                    : [],
                'blockers' => array_map(fn (string $reason): string => $this->anchorMessage($reason), $character['anchor_blockers'] ?? []),
                'approved' => collect($rowCells)->contains(static fn(array $cell): bool => $cell['status'] === DesignImageStatus::APPROVED->value
                    && (! $designed || VesselDesign::stampedFor($cell, $character))),
                'prompt' => $preview['prompt'] ?? null,
                'prompt_hash' => $preview['prompt_sha256'] ?? null,
                'prompt_version' => $preview['lineage']['prompt_version'] ?? null,
                'size' => ImageSize::tryFrom((string) ($submitted ? old('size') : ($preview['size'] ?? ''))),
                'model' => ImageModel::tryFrom((string) ($submitted ? old('model') : ($preview['lineage']['model'] ?? ''))),
                'quality' => $submitted ? ImageQuality::tryFrom((string) old('quality', '')) : null,
                'variations' => ImageVariations::tryFrom((int) ($submitted ? old('variations', 1) : 1)),
                'cells' => $rowCells,
            ];
        }

        $screenplayFoundation = $this->videoProjectService->latestScreenplayFoundation($project->id);

        if (VesselDesign::isDesignFirst($project)) {
            $stories = app(StoryFoundationService::class);
            $story = $stories->latestStoryFoundation($project->id);

            if ($story !== null && $story->id === $screenplayFoundation['stage_id']) {
                $stories->ensureCast($project->id, $story);
            }
        }

        $screenplayCharacters = $this->screenplayExpansion->latestCast(
            $project->id, 'characters', $screenplayFoundation['stage_id'],
        );

        return view('video-projects.anchor', [
            'route' => 'video-projects',
            'action' => 'video-projects-index',
            'menu' => 'menu-open',
            'active' => 'active',
            'project' => $project,
            'brief' => $this->videoProjectService->latestInspiration($project->id),
            'anchorRows' => $anchorRows,
            'compileReason' => $this->anchorMessage('choose_prompt_settings'),
            'nextImageCode' => $this->videoProjectService->nextImageCode($project->id, (string) auth()->user()?->name),
            'screenplay' => $this->videoProjectService->latestScreenplayScenes($project->id),
            'screenplayFoundation' => $screenplayFoundation,
            'screenplayCharacters' => $screenplayCharacters,
            'screenplayLocations' => $this->screenplayExpansion->latestCast(
                $project->id, 'locations', $screenplayFoundation['stage_id'], $screenplayCharacters['stage_id'],
            ),
            'anchorPrompt' => $this->videoProjectService->latestAnchorPrompt($project->id),
            'designFirst' => VesselDesign::isDesignFirst($project),
            'vesselDesign' => VesselDesign::isDesignFirst($project)
                ? app(VesselDesignService::class)->panel($project->id)
                : null,
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

    public function concept(Request $request, string $id)
    {
        $project = $this->ownedProject($id);

        $characterId = $request->string('character_id')->toString();

        if ($characterId === '' && VesselDesign::isDesignFirst($project)) {
            return back()->with('error', $this->anchorMessage('character_prompt_no_design'));
        }
        if ($characterId !== '') {
            [$compiled, $reason, $character] = $this->characterAnchorPromptService->author(
                $id,
                $characterId,
                AnchorStage::FABRICATION_GEOMETRY_ANCHOR,
                ImageSize::LANDSCAPE,
                $this->characterAnchorPromptService->anchorModel(),
                $request->boolean('force'),
            );

            if ($compiled === null) {
                return back()->with('error', $this->anchorMessage($reason));
            }

            $this->videoProjectService->storeAnchorPromptPreview(
                $id,
                AnchorStage::FABRICATION_GEOMETRY_ANCHOR,
                Viewpoint::FrontThreeQuarter,
                ImageSize::LANDSCAPE,
                $compiled,
                [],
                $character,
            );

            $name = (string) ($character['name'] ?? $characterId);

            return back()->with('success', $reason === 'cached'
                ? "Nhân vật {$name} không đổi — dùng lại prompt đã có, không gọi model."
                : "Đã viết prompt cho {$name}.");
        }

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

    public function vesselDesign(Request $request, string $id)
    {
        $this->ownedProject($id);

        [$design, $reason] = app(VesselDesignService::class)->author($id, $request->boolean('force'));

        if ($design === null) {
            return back()->with('error', $this->anchorMessage($reason));
        }

        return back()->with('success', $reason === 'cached'
            ? 'Đầu vào không đổi — dùng lại bản thiết kế đã có, không gọi model.'
            : 'Đã thiết kế tàu. Bước tiếp theo: viết prompt anchor.');
    }

    public function resetVesselDesign(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = app(VesselDesignService::class)->reset($id);

        return $done
            ? back()->with('success', 'Đã reset — bấm Thiết kế tàu để chạy lại.')
            : back()->with('error', $reason);
    }

    public function screenplayFoundation(Request $request, string $id)
    {
        $project = $this->ownedProject($id);

        $force = $request->boolean('force');

        [$foundation, $reason] = VesselDesign::isDesignFirst($project)
            ? app(StoryFoundationService::class)->author($id, $force)
            : $this->videoProjectService->authorScreenplayFoundation($id, $force);

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

    public function screenplayCharacters(Request $request, string $id)
    {
        return $this->screenplayCast($request, $id, 'characters');
    }

    public function screenplayLocations(Request $request, string $id)
    {
        return $this->screenplayCast($request, $id, 'locations');
    }

    public function resetScreenplayCharacters(string $id)
    {
        return $this->resetScreenplayCast($id, 'characters');
    }

    public function resetScreenplayLocations(string $id)
    {
        return $this->resetScreenplayCast($id, 'locations');
    }

    private function screenplayCast(Request $request, string $id, string $part)
    {
        $this->ownedProject($id);

        $foundationStageId = $request->string('foundation_stage_id')->toString();
        $force = $request->boolean('force');

        [$cast, $reason] = $part === 'characters'
            ? $this->screenplayExpansion->authorCharacters($id, $foundationStageId, $force)
            : $this->screenplayExpansion->authorLocations(
                $id, $foundationStageId, $request->string('characters_stage_id')->toString(), $force,
            );

        if ($cast === null) {
            return back()->with('error', $this->anchorMessage($reason));
        }

        $label = $part === 'characters' ? 'nhân vật' : 'địa điểm';

        return back()->with('success', $reason === 'cached'
            ? "Nội dung kịch bản không đổi — dùng lại danh sách {$label} đã có, không gọi model."
            : "Đã tạo danh sách {$label}.");
    }

    private function resetScreenplayCast(string $id, string $part)
    {
        $this->ownedProject($id);

        [$done, $reason] = $this->screenplayExpansion->resetCast($id, $part);

        return $done
            ? back()->with('success', 'Đã reset — bấm tạo lại để chạy lại.')
            : back()->with('error', $reason);
    }

    public function screenplay(Request $request, string $id)
    {
        $this->ownedProject($id);

        [$screenplay, $reason] = $this->screenplayExpansion->authorScenes(
            $id,
            $request->string('foundation_stage_id')->toString(),
            $request->string('characters_stage_id')->toString(),
            $request->string('locations_stage_id')->toString(),
            $request->boolean('force'),
        );

        if ($screenplay === null) {
            return back()->with('error', $this->anchorMessage($reason));
        }

        if ($reason === 'ok_needs_review') {
            return back()->with('warning', 'Đã tạo phân cảnh — có cảnh báo biên tập, đọc phần cảnh báo bên dưới.');
        }

        return back()->with('success', $reason === 'cached'
            ? 'Nội dung kịch bản không đổi — dùng lại phân cảnh đã có, không gọi model.'
            : 'Đã tạo phân cảnh bằng Claude Sonnet 5.');
    }

    public function resetScreenplay(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = $this->videoProjectService->resetScreenplayScenes($id);

        return $done
            ? back()->with('success', 'Đã reset — bấm Tạo phân cảnh để chạy lại.')
            : back()->with('error', $reason);
    }

    public function approveScreenplay(Request $request, string $id)
    {
        $this->ownedProject($id);
        $data = $request->validate([
            'stage_id' => ['required', 'uuid'],
            'operation_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        [$done, $reason] = $this->videoProjectService->approveScreenplay(
            $id,
            $data['stage_id'],
            $this->actorId(),
            $data['operation_id'],
            $data['reason'] ?? null,
        );

        return $done
            ? back()->with('success', $reason === 'replayed'
                ? 'Bản phân cảnh này đã được duyệt trước đó.'
                : 'Đã duyệt bản phân cảnh theo đúng nội dung hiện tại.')
            : back()->with('error', $this->anchorMessage($reason));
    }

    public function selectScreenplay(Request $request, string $id)
    {
        $this->ownedProject($id);
        $data = $request->validate([
            'stage_id' => ['required', 'uuid'],
            'expected_selection_version' => ['required', 'integer', 'min:0'],
        ]);

        [$done, $reason] = $this->videoProjectService->selectScreenplayForProduction(
            $id,
            $data['stage_id'],
            (int) $data['expected_selection_version'],
        );

        return $done
            ? back()->with('success', $reason === 'already_selected'
                ? 'Bản phân cảnh này đang được dùng cho production.'
                : 'Đã chọn bản phân cảnh làm nguồn production.')
            : back()->with('error', $this->anchorMessage($reason));
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
            ($data['character_id'] ?? null) ?: null,
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
            return back()->with('error', $this->referenceMessage($reason));
        }

        return back()->with(
            $reason === 'rendered' ? 'success' : 'error',
            $this->renderOutcome($reason, $image),
        );
    }

    public function approveAnchor(Request $request, string $id)
    {
        $project = $this->ownedProject($id);

        $data = $this->form->validate($request, 'AnchorApproveForm');

        [$done, $reason] = $this->videoProjectService->approveAnchor(
            $id,
            (string) $data['artifact_id'],
            auth()->id(),
        );

        if ($done && VesselDesign::isDesignFirst($project)) {
            return redirect()
                ->route('video-projects.reference', $id)
                ->with('success', 'Đã duyệt ảnh anchor cho bản thiết kế. Bước tiếp theo: tạo, duyệt và chốt bộ ảnh Reference trước khi viết kịch bản.');
        }

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
            'character_prompt_no_screenplay' => 'Chưa có bản phân cảnh được duyệt và chọn cho production — duyệt, chọn bản phân cảnh rồi mới viết prompt cho từng nhân vật.',
            'character_prompt_unknown_character' => 'Nhân vật này không có trong bản phân cảnh production.',
            'character_not_main' => 'Chỉ nhân vật chính (protagonist) mới có anchor — nhân vật phụ được mô tả bằng chữ khi render. Không gọi model, không render, không duyệt.',
            'anchor_prompt_other_screenplay' => 'Prompt hoặc ảnh này thuộc một bản phân cảnh khác bản production đang chọn — viết lại prompt trước.',
            'screenplay_has_no_main_object' => 'Bản phân cảnh production không có nhân vật chính loại object để làm anchor chính.',
            'character_prompt_running' => 'Đang có một lượt viết prompt chạy cho dự án này.',
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
            'environment_requirement_unreadable' => __('messages.environment_requirement_unreadable'),
            'environment_media_models_broken' => __('messages.environment_media_models_broken'),
            'environment_unknown_media_model' => __('messages.environment_unknown_media_model'),
            'environment_media_setting_invalid' => __('messages.environment_media_setting_invalid'),
            'no_screenplay_profile' => 'Chủ đề này chưa có profile kịch bản.',
            'screenplay_foundation_not_selectable' => __('messages.screenplay_foundation_not_selectable'),
            'screenplay_not_approvable' => 'Bản phân cảnh không thuộc dự án, chưa hoàn tất hoặc không còn hợp lệ để duyệt.',
            'approval_operation_invalid' => 'Mã thao tác duyệt không hợp lệ.',
            'approval_operation_conflict' => 'Mã thao tác duyệt đã được dùng cho một nội dung khác.',
            'screenplay_not_approved' => 'Bản phân cảnh chưa được duyệt hoặc quyết định duyệt không còn khớp nội dung.',
            'screenplay_not_selected' => __('messages.screenplay_not_selected'),
            'production_selection_conflict' => 'Bản production đã được thay đổi ở thao tác khác. Tải lại trang trước khi chọn.',
            'subject_mapping_invalid' => 'Nhân vật hoặc khóa chủ thể không hợp lệ.',
            'subject_mapping_foreign' => 'Chủ thể được chọn không thuộc dự án này.',
            'subject_mapping_operation_conflict' => 'Mã thao tác xác nhận chủ thể đã được dùng cho một lựa chọn khác.',
            'screenplay_profile_contract_mismatch' => __('messages.screenplay_profile_contract_mismatch'),
            'screenplay_profile_invalid' => __('messages.screenplay_profile_invalid'),
            'screenplay_profile_changed' => 'Nội dung kịch bản đang chọn được viết theo profile kịch bản khác với profile hiện hành — tạo lại nội dung kịch bản. Không gọi model.',
            'screenplay_profile_unknown' => 'Không xác định được profile kịch bản mà bản nội dung này đã dùng — tạo lại nội dung kịch bản. Không gọi model.',
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
            'screenplay_truncated' => 'Model bị cắt ở giới hạn token đầu ra — kết quả dở dang không được dùng; nguyên văn và chi phí đã được lưu.',
            'screenplay_characters_not_selectable' => 'Chưa có danh sách nhân vật tạo từ đúng bản nội dung kịch bản này — tạo nhân vật trước.',
            'screenplay_locations_not_selectable' => 'Chưa có danh sách địa điểm tạo từ đúng bản nội dung kịch bản này — tạo địa điểm trước.',
            'screenplay_characters_outdated' => 'Danh sách nhân vật này tạo theo bộ luật cũ, không khớp hồ sơ nhân vật chính hiện hành — tạo lại nhân vật.',
            'screenplay_locations_outdated' => 'Danh sách địa điểm này không tạo từ danh sách nhân vật đang dùng — tạo lại địa điểm.',
            'screenplay_locations_without_brief_spaces' => 'Bộ địa điểm đang chọn chưa gắn đủ không gian của yêu cầu project (brief_space) — tạo lại địa điểm theo nội dung kịch bản hiện tại. Không gọi model.',
            'screenplay_film_brief_invalid' => 'Khối film_brief trong profile kịch bản không hợp lệ hoặc không khớp coverage của profile. Không gọi model — xem log để biết mục nào.',
            'screenplay_locations_unprofiled' =>'Danh sách địa điểm này tạo theo mẫu cũ (chỉ có tên và mô tả), thiếu bố cục, lối nối, thiết bị cố định và nguồn sáng — tạo lại địa điểm.',
            'workflow_not_design_first' => 'Dự án này chạy luồng cũ (kịch bản trước, thiết kế sau). Luồng thiết kế trước chỉ áp dụng cho dự án mới.',
            'character_prompt_no_design' => 'Chưa có bản thiết kế tàu — bấm Thiết kế tàu trước khi viết prompt anchor.',
            'anchor_prompt_other_design' => 'Prompt hoặc ảnh này thuộc một bản thiết kế khác bản thiết kế hiện hành — viết lại prompt anchor từ bản thiết kế mới.',
            'anchor_design_unresolved' => 'Bản thiết kế hiện hành còn quyết định thiết kế chưa chốt — chốt các quyết định đó trước khi viết prompt hoặc render anchor. Không gọi model.',
            'anchor_prompt_unstamped' => 'Prompt anchor này viết trước khi có dấu nguồn (bước viết, nguồn, skill, khung hình) — viết lại prompt anchor. Không render.',
            'anchor_prompt_size_mismatch' => 'Size đang chọn khác khung hình prompt đã viết — chọn đúng Size của prompt hoặc viết lại prompt. Không render.',
            'anchor_design_incomplete_text' => 'Bản thiết kế hiện hành có đoạn văn bị cắt giữa câu (xem log để biết mục nào) — hiệu chỉnh hoặc tạo lại thiết kế trước khi viết prompt hay render anchor. Không gọi model.',
            'anchor_design_placement_ambiguous' => 'Bản thiết kế có hai sàn cùng tầng, cùng loại mà lời văn không phân biệt được (mốc là khối/khoảng trống không có tên) — thiếu dữ kiện định vị, cần bổ sung trước khi viết prompt hay render anchor (xem log). Không gọi model.',
            'anchor_source_conflict' => 'AI viết prompt đã báo nguồn thiết kế tự mâu thuẫn ở phần P0, vùng đặc trưng hoặc quan hệ hình học bắt buộc (xem log) — sửa nguồn thiết kế rồi viết lại prompt. Không render.',
            'anchor_prompt_malformed' => 'AI trả kết quả sai định dạng (thiếu geometry_prompt hoặc conflicts) — nguyên văn và chi phí đã lưu. Không render.',
            'anchor_prompt_source_changed' => 'Nguồn thiết kế hoặc skill đã đổi so với lúc viết prompt — viết lại prompt anchor. Không render.',
            'design_anchor_not_approved' => 'Chưa có ảnh anchor được duyệt cho bản thiết kế — duyệt ảnh anchor trước khi viết kịch bản. Không gọi model.',
            'design_references_not_approved' => 'Chưa chốt bộ ảnh Reference cho ảnh anchor và bản thiết kế hiện hành (hoặc anchor/thiết kế đã đổi sau khi chốt) — duyệt ít nhất một ảnh Reference và bấm "Chốt bộ Reference" trước khi viết kịch bản. Không gọi model.',
            'design_references_none_approved' => 'Chưa có ảnh Reference nào được duyệt từ ảnh anchor hiện hành — duyệt ít nhất một ảnh Reference rồi mới chốt.',
            'design_references_locked' => 'Đã chốt bộ ảnh Reference. Bước tiếp theo: viết nội dung kịch bản.',
            'design_changed_since_anchor' => 'Bản thiết kế đã đổi so với lúc duyệt ảnh anchor — viết prompt, render và duyệt lại ảnh. Không gọi model.',
            'anchor_design_incomplete' => 'Phần hình học đã chốt của bản thiết kế không toàn vẹn (có tham chiếu tới phần chưa chốt hoặc thiếu P0) — thiết kế lại tàu. Không gọi model, xem log để biết mục nào.',
            'scene_design_reference_missing' => 'Bản trích thiết kế cho phân cảnh tham chiếu tới bộ phận không có hoặc chưa chốt trong thiết kế (xem log) — sửa hoặc tạo lại thiết kế. Không gọi model.',
            'location_design_reference_missing' => 'Bản trích thiết kế cho địa điểm tham chiếu tới bộ phận không có hoặc chưa chốt trong thiết kế (xem log) — sửa hoặc tạo lại thiết kế. Không gọi model.',
            'story_design_reference_missing' => 'Bản trích thiết kế cho kịch bản tham chiếu tới bộ phận không có hoặc chưa chốt trong thiết kế (xem log) — sửa hoặc tạo lại thiết kế. Không gọi model.',
            'design_geometry_incomplete' => 'Phần hình học đã chốt của bản thiết kế không toàn vẹn — không thể giao cho bước địa điểm/phân cảnh. Không gọi model, xem log.',
            'artifact_not_verified' =>'Ảnh này chưa có mã kiểm tra (sha256) — không thể khoá làm nguồn cho kịch bản.',
            'foundation_not_design_first' => 'Bản nội dung này không dựng trên bản thiết kế tàu.',
            default => $reason,
        };
    }

    public function approveReference(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'AnchorApproveForm');

        [$done, $reason] = $this->videoProjectService->approveReference(
            $id,
            (string) $data['artifact_id'],
            auth()->id(),
        );

        return back()->with($done ? 'success' : 'error', $this->anchorMessage($reason));
    }

    public function lockReferences(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = app(VesselDesignService::class)->lockReferences($id, auth()->id());

        return $done
            ? redirect()->to(route('video-projects.anchor', $id).'#screenplay-panel')->with('success', $this->anchorMessage($reason))
            : back()->with('error', $this->anchorMessage($reason));
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

    public function writeReferencePrompt(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'ReferenceImageForm', 'prompt');


        [$stage, $reason, $violations] = $this->videoProjectService->writeReferencePrompt($id, $data);


        $message = $this->referenceMessage($reason);

        if ($violations !== []) {
            $message .= ' Chi tiết: '.implode(' | ', $violations);
        }

        return redirect()
            ->route('video-projects.reference', $id)
            ->withInput(['view' => $data['view']])
            ->with($stage === null ? 'error' : 'success', $message);
    }

    private function createReference(Request $request, string $id)
    {
        $data = $this->form->validate($request, 'ReferenceImageForm');

        [$image, $reason] = $this->videoProjectService->renderReferenceDirect(
            $id,
            (string) auth()->user()?->name,
            $data,
        );

        if ($image === null) {
            return back()->with('error', $this->referenceMessage($reason));
        }

        $level = in_array($reason, ['rendered', 'already_exists'], true) ? 'success' : 'error';

        return redirect()
            ->route('video-projects.reference', $id)
            ->with($level, $this->renderOutcome($reason, $image));
    }

    private function referenceMessage(string $reason): string
    {
        return match ($reason) {
            'written' => 'AI đã viết prompt cho góc này — xem trước trong ô prompt rồi mới render.',
            'reference_prompt_cached' => 'Ảnh, nguồn và cấu hình không đổi — dùng lại prompt AI đã viết, không gọi model.',
            'reference_prompt_running' => 'Đang có một lượt AI viết prompt chạy cho dự án này.',
            'reference_prompt_call_failed' => 'Gọi model viết prompt thất bại — xem log.',
            'reference_prompt_invalid' => 'Kết quả AI vi phạm hợp đồng — nguyên văn, usage và chi phí đã được lưu, không render được.',
            'reference_prompt_claim_lost' => 'Mất claim khi lưu — kết quả đã trả tiền được ghi thành lượt mồ côi.',
            'reference_prompt_not_found' => 'Không tìm thấy prompt AI đã viết cho góc này.',
            'reference_prompt_stale' => 'Prompt AI không còn khớp ảnh anchor, gói nguồn hoặc góc đang chọn — viết lại prompt.',
            'reference_needs_ai_prompt' => 'Ô reference này tạo bằng prompt PHP cũ hoặc lật ngang — không render lại được. Hãy bấm "AI viết prompt" rồi Generate Reference.',
            'reference_design_reference_missing' => 'Bản trích thiết kế cho góc này tham chiếu tới bộ phận không có hoặc chưa chốt (xem log) — sửa hoặc tạo lại thiết kế. Không gọi model.',
            'reference_anchor_foreign' => 'Ảnh anchor hoặc artifact không thuộc dự án này. Không gọi model.',
            'reference_anchor_design_changed' => 'Bản thiết kế đã đổi so với lúc tạo ảnh anchor — tạo và duyệt lại anchor. Không gọi model.',
            'reference_anchor_other_design' => 'Ảnh anchor đang dùng thuộc bản thiết kế khác bản thiết kế đang khoá — chọn lại bộ nguồn khớp (anchor của đúng bản thiết kế). Không gọi model.',
            'reference_design_extract_invalid' => 'Phần hình học đã chốt của bản thiết kế không toàn vẹn — không viết được prompt Reference. Không gọi model, xem log.',
            'reference_preview_stale' => 'Prompt đã khác bản đang xem trước — tải lại trang.',
            'reference_anchor_source_unknown' => 'Ảnh anchor không ghi kịch bản và nhân vật nguồn — không ghép với kịch bản đang chọn.',
            'reference_screenplay_unavailable' => 'Bản kịch bản mà ảnh anchor được tạo từ đó không còn đọc được.',
            'reference_anchor_not_object' => 'Nhân vật của ảnh anchor không phải loại object trong kịch bản nguồn.',
            default => $this->anchorMessage($reason),
        };
    }

    public function approveEnvironment(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'AnchorApproveForm');

        [$done, $reason] = $this->videoProjectService->approveEnvironmentReference(
            $id,
            (string) $data['artifact_id'],
            auth()->id(),
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
            $id,
            (string) auth()->user()?->name,
            $data,
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
        $trial = $this->videoProjectService->latestScenePlanTrial($id);
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
            'trial' => $trial,
            'productionScreenplayScenes' => $this->videoProjectService->productionScreenplayScenes($id),
            'keyframes' => $keyframes,
            'referenceRoles' => [
                'identity' => 'Identity view',
                'environment' => 'Environment',
                'geometry' => 'Supporting view',
            ],
            'sources' => collect($this->videoProjectService->sceneSourceCells($id, $plan['revision']))
                ->map(fn(array $cell): array => array_replace($cell, [
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
                    ->filter(static fn(array $cell): bool => $cell['approved'] !== null)
                    ->count(),
            ],
        ]);
    }

    public function planScenes(Request $request, string $id)
    {
        $this->ownedProject($id);

        [$count, $reason] = $this->videoProjectService->planScenes(
            $id,
            auth()->id(),
            $request->boolean('force'),
        );

        if ($count === null) {
            return back()->with('error', $this->anchorMessage($reason));
        }

        return $reason === 'ok_needs_review'
            ? back()->with('warning', __('messages.scene_plan_needs_review', ['count' => $count]))
            : back()->with('success', __('messages.scene_plan_done', ['count' => $count]));
    }

    public function planSceneTrial(Request $request, string $id)
    {
        $this->ownedProject($id);
        $from = trim((string) $request->input('from'));
        $to = trim((string) $request->input('to'));
        $scope = $this->videoProjectService->trialScopeBetween($id, $from, $to);

        if ($scope === null) {
            return back()->with('error', 'Chon mot nhom lien tiep tu 2 den 4 scene.');
        }

        [$count, $reason] = $this->videoProjectService->planScenes(
            $id,
            auth()->id(),
            false,
            $scope,
        );

        if ($count === null) {
            return back()->with('error', $this->anchorMessage($reason));
        }

        return $reason === 'ok_needs_review'
            ? back()->with('warning', 'Ban thu da luu nhung can kiem tra lai.')
            : back()->with('success', 'Da tao ban thu cho ' . implode(', ', $scope) . '.');
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

        $source = $request->query('space_source');

        [$preview, $reason] = $this->videoProjectService->sceneImagePreview(
            $id,
            $this->actorId(),
            $sceneId,
            is_string($source) && Str::isUuid($source) ? $source : null,
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

        $plan = $this->videoProjectService->selectedScenePlan($id);
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
            $result['error'] . (isset($result['reasons']) ? ': ' . implode(' | ', $result['reasons']) : ''),
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
            ->whereHas('session', fn($scope) => $scope->where('project_id', $id))
            ->firstOrFail();

        // `realpath` CA HAI dau truoc khi so. `storage_path()` tra ve dau phan cach
        // lan (`storage\app/video-compose-final`) con `realpath` chuan hoa het ve
        // `\` — so mot ben da chuan hoa voi mot ben chua thi luon truot, va ket qua
        // la 404 cho mot file co that.
        $root = realpath((string) config('video.veo.compose_final_dir'));

        abort_if($root === false, 404);

        $root = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        $path = realpath($root . (string) $final->video_path);

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
            $summary['done'] += ($clips[$key]['selected_status'] ?? null) === 'succeeded'
                && ($clips[$key]['selected_validity'] ?? null) === 'valid' ? 1 : 0;
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

            $plan = $this->videoProjectService->selectedScenePlan($id);
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
            $render = $this->clips->create(
                $shot,
                $source,
                (string) $data['model_id'],
                Arr::except($data, ['model_id', 'expected_intent_version', 'operation_id']),
                (int) $data['expected_intent_version'],
                (string) $data['operation_id'],
            );
        } catch (ValidationException $e) {
            return $this->clipRefused($request, implode(' ', $e->validator->errors()->all()), $id, $sceneId);
        } catch (Throwable $e) {
            $reason = match ($e->getMessage()) {
                'shot_dispatch_conflict' => 'Clip đã thay đổi ở thao tác khác. Tải lại trang trước khi render.',
                'shot_dispatch_operation_conflict' => 'Mã thao tác render đã được dùng cho một yêu cầu khác.',
                'shot_dispatch_replay_missing' => 'Không còn tìm thấy lượt render của thao tác trước.',
                default => $e->getMessage(),
            };

            return $this->clipRefused($request, $reason, $id, $sceneId);
        }

        [$ok, $reason] = $this->clipExecution->submit($render->id);

        if ($request->expectsJson()) {
            return response()->json($this->clipState($id, $render->refresh(), $reason));
        }

        return back()->with($ok ? 'status' : 'error', 'Clip: ' . $reason);
    }

    public function selectSceneClip(Request $request, string $id, string $shotId)
    {
        $this->ownedProject($id);
        $data = $request->validate([
            'render_id' => ['required', 'uuid'],
            'expected_intent_version' => ['required', 'integer', 'min:0'],
            'operation_id' => ['required', 'uuid'],
        ]);
        $shot = \App\Models\VideoShot::query()
            ->whereKey($shotId)
            ->whereIn('scene_id', \App\Models\VideoRenderScene::query()
                ->where('project_id', $id)
                ->select('id'))
            ->firstOrFail();
        $render = VideoRender::query()
            ->whereKey($data['render_id'])
            ->where('shot_id', $shot->id)
            ->where('execution_purpose', SceneClipDispatchService::PURPOSE_PRODUCTION)
            ->firstOrFail();
        [$selected, $reason] = $this->shotIntents->manualSelect(
            $shot,
            $render,
            (int) $data['expected_intent_version'],
            $data['operation_id'],
            $this->actorId(),
        );

        if ($selected === null) {
            return back()->with('error', match ($reason) {
                'shot_selection_conflict' => 'Clip đã thay đổi ở một thao tác khác. Tải lại trang trước khi chọn.',
                'shot_selection_incompatible' => 'Clip này không còn khớp đầu vào hiện tại của shot.',
                'shot_selection_operation_conflict' => 'Mã thao tác đã được dùng cho một lựa chọn khác.',
                default => 'Không thể chọn clip này.',
            });
        }

        return back()->with('success', $reason === 'replayed'
            ? 'Thao tác chọn clip này đã được ghi trước đó; lựa chọn hiện tại không bị thay đổi.'
            : 'Đã chọn clip dùng cho timeline.');
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

        return back()->with('error', 'Clip: ' . $reason);
    }

    /**
     * Mot dong trang thai du de man hinh ve lai dung o do, khong phai tai lai trang.
     *
     * @return array<string, mixed>
     */
    private function clipState(string $id, VideoRender $render, string $reason): array
    {
        $shot = $render->shot()->first();
        $currentIsSelected = $shot !== null
            && (string) $shot->video_render_id === (string) $render->id;
        $currentSelectionValid = $currentIsSelected
            && $this->shotSelections->verdict($shot, $render)['status'] === 'valid';
        $status = $render->execution_status?->value;
        $running = in_array($status, ['submitting', 'submitted', 'provider_running', 'polling'], true);

        $state = match (true) {
            $status === 'succeeded' => 'succeeded',
            $running => 'running',
            $status === 'failed' => 'failed',
            default => 'idle',
        };

        $note = match ($state) {
            'running' => 'đang dựng · đã hỏi ' . $render->provider_poll_count . ' lần',
            'failed' => (string) ($render->failure_message ?? $reason)
                . ($shot?->video_render_id !== null ? ' Clip đã chọn vẫn được giữ.' : ''),
            'succeeded' => match (true) {
                $currentSelectionValid => '',
                $shot?->video_render_id !== null => 'Lượt mới đã xong; clip đã chọn vẫn được giữ.',
                default => 'Lượt render đã xong nhưng không đủ điều kiện tự chọn.',
            },
            default => $reason,
        };

        return [
            'state' => $state,
            'reason' => $reason,
            'note' => $note,
            'poll_url' => route('video-projects.scene-clip-poll', [$id, $render->id]),
            'file_url' => $state === 'succeeded' && $currentSelectionValid
                ? route('video-projects.scene-clip-file', [$id, $render->id])
                : null,
            'meta' => $render->width && $render->height
                ? $render->width . '×' . $render->height
                . ($render->duration_ms ? ' · ' . round($render->duration_ms / 1000, 1) . 's' : '')
                : '',
            'intent_version' => $shot?->intent_version,
            'selected_render_id' => $shot?->video_render_id,
            'current_is_selected' => $currentSelectionValid,
            'next_operation_id' => (string) \Illuminate\Support\Str::uuid(),
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
            ->where(fn($query) => $query
                ->whereHas('shot.session', fn($scope) => $scope->where('project_id', $id))
                ->orWhereHas('session', fn($scope) => $scope->where('project_id', $id)))
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
            ->where(fn($query) => $query
                ->whereHas('shot.session', fn($scope) => $scope->where('project_id', $id))
                ->orWhereHas('session', fn($scope) => $scope->where('project_id', $id)))
            ->firstOrFail();

        [$ok, $reason] = $this->clipExecution->poll($owned->id);

        if (request()->expectsJson()) {
            return response()->json($this->clipState($id, $owned->refresh(), $reason));
        }

        return back()->with($ok ? 'status' : 'error', 'Clip: ' . $reason);
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
            $data['space_source_artifact_id'] ?? null,
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
            $id,
            $this->actorId(),
            $image,
            (string) $data['prompt_sha256'],
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
            $id,
            $this->actorId(),
            $image,
            (string) $data['artifact_id'],
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

        $key = 'messages.scene_keyframe_' . $code;
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
            Log::warning('Registry clip hong: ' . $e->getMessage());

            return [];
        }

        return array_map(static fn(array $entry): array => [
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
