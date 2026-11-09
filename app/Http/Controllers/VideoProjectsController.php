<?php

namespace App\Http\Controllers;

use App\Enums\DesignImageStatus;
use App\Enums\PlanningStageName;
use App\Enums\VideoPlanningStageStatus;
use App\Enums\SceneStep;
use App\Form\AdminCustomValidator;
use App\Models\Admin;
use App\Models\VideoDesignImage;
use App\Models\VideoPlanningStage;
use App\Models\VideoProject;
use App\Models\VideoRender;
use App\Services\VideoProjectService;
use App\Video\Render\Video\SceneClipDispatchService;
use App\Video\Render\Video\SceneShotFactory;
use App\Video\Render\Video\VideoRenderExecutionService;
use App\Video\Scene\Services\ShotIntentService;
use App\Video\Scene\Services\ShotSelectionReconciler;
use App\Services\Video\CharacterAnchorPromptService;
use App\Services\Video\DesignDnaService;
use App\Services\Video\DesignImageStore;
use App\Services\Video\ImageRenderQueue;
use App\Services\Video\ModelSheetQaService;
use App\Services\Video\ModelSheetService;
use App\Services\Video\PlanningStageStore;
use App\Services\Video\LocationReferenceService;
use App\Services\Video\LocationSourceService;
use App\Services\Video\LocationWriterService;
use App\Services\Video\SceneSourceService;
use App\Services\Video\SceneWriterService;
use App\Services\Video\ShotSourceService;
use App\Services\Video\ShotWriterService;
use App\Services\Video\ScriptSourceService;
use App\Services\Video\ScriptWriterService;
use App\Services\Video\YachtDesignService;
use App\Services\Video\ScreenplayExpansionService;
use App\Services\Video\VesselDesignService;
use App\Video\Screenplay\VesselDesign;
use App\Video\YachtDesign\YachtDesignCheck;
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
    private const CANCELLED = 'Đã huỷ lượt đang chờ trong hàng đợi — job sẽ bỏ qua lượt này, không gọi model.';

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

        $designFirst = VesselDesign::isDesignFirst($project);

        return view('video-projects.anchor', [
            'route' => 'video-projects',
            'action' => 'video-projects-index',
            'menu' => 'menu-open',
            'active' => 'active',
            'project' => $project,
            'brief' => $brief = $this->videoProjectService->latestInspiration($project->id),
            'dna' => $dna = app(DesignDnaService::class)->panel($project->id, $brief),
            'yacht' => $yacht = $designFirst ? app(YachtDesignService::class)->panel($project->id, $dna) : null,
            'modelSheet' => $designFirst && $brief['phase_one'] ? app(ModelSheetService::class)->panel($project->id, (string) auth()->user()?->name, $yacht) : null,
            'scriptStep' => $designFirst && $brief['phase_one'] ? app(ScriptWriterService::class)->panel(
                $project->id,
                is_string($request->query('script')) && Str::isUuid($request->query('script')) ? $request->query('script') : null,
            ) : null,
            'designFirst' => $designFirst,
        ]);
    }

    public function inspiration(Request $request, string $id)
    {
        $this->ownedProject($id);
        $data = $request->validate(['operation_id' => ['required', 'uuid']], ['operation_id.*' => 'Thiếu mã thao tác — tải lại trang.']);
        [$stage, $reason] = $this->videoProjectService->runInspiration($id, (string) $data['operation_id'], $this->requester());

        if ($stage === null) {
            return back()->with('error', $reason);
        }

        if ($reason === 'queued') {
            return $this->queued($request, $id, $stage, 'phân tích', route('video-projects.anchor', $id));
        }

        return back()->with('success', match ($reason) {
            'cached' => 'Prompt Phase 1 và nội dung bài không đổi — dùng lại phản hồi đã lưu, không gọi Haiku.',
            'replayed' => 'Thao tác này đã chạy trước đó — không gọi model lần nữa.',
            default => 'Đã lưu phản hồi Haiku theo prompt Phase 1 — chưa kiểm nội dung.',
        });
    }

    public function designDna(Request $request, string $id)
    {
        $this->ownedProject($id);
        $data = $request->validate(['operation_id' => ['required', 'uuid']], ['operation_id.*' => 'Thiếu mã thao tác — tải lại trang.']);
        [$stage, $reason] = app(DesignDnaService::class)->run($id, $request->boolean('regenerate'), (string) $data['operation_id'], $this->requester());

        if ($stage === null) {
            return back()->with('error', $reason);
        }

        if ($reason === 'queued') {
            return $this->queued($request, $id, $stage, 'tạo DNA', route('video-projects.anchor', $id));
        }

        return back()->with('success', match ($reason) {
            'cached' => 'Prompt Phase 2 và kết quả Phase 1 không đổi — dùng lại DNA đã lưu, không gọi model.',
            'replayed' => 'Thao tác này đã chạy trước đó — không gọi model lần nữa.',
            default => 'Đã lưu Design DNA theo prompt Phase 2.',
        });
    }

    public function resetDesignDna(string $id)
    {
        $this->ownedProject($id);
        [$done, $reason] = app(DesignDnaService::class)->reset($id);

        return $done
            ? back()->with('success', $reason === 'pending' ? self::CANCELLED : 'Đã reset lượt tạo DNA bị kẹt.')
            : back()->with('error', $reason);
    }

    public function resetInspiration(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = $this->videoProjectService->resetInspiration($id);

        return $done
            ? back()->with('success', $reason === 'pending' ? self::CANCELLED : 'Đã reset — bấm Gọi Haiku để chạy lại.')
            : back()->with('error', $reason);
    }

    public function vesselDesign(Request $request, string $id)
    {
        $this->ownedProject($id);

        return $this->phaseDesign($request, $id);
    }

    private function phaseDesign(Request $request, string $id)
    {
        $data = $request->validate(['operation_id' => ['required', 'uuid']], ['operation_id.*' => 'Thiếu mã thao tác — tải lại trang.']);
        [$stage, $reason] = app(YachtDesignService::class)->run($id, $request->boolean('force'), (string) $data['operation_id'], $this->requester());

        if ($stage === null) {
            return back()->with('error', $reason);
        }

        if ($reason === 'queued') {
            return $this->queued($request, $id, $stage, 'thiết kế', route('video-projects.anchor', $id));
        }

        $message = match ($reason) {
            'cached' => 'DNA và prompt Phase 3 không đổi — dùng lại bản thiết kế đã lưu, không gọi model.',
            'replayed' => 'Thao tác này đã chạy trước đó — không gọi model lần nữa.',
            default => 'Đã lưu bản thiết kế Phase 3 (draft) — xem kết quả kiểm RULE.',
        };

        return ($stage->output_json['check']['verdict'] ?? null) === YachtDesignCheck::PASS
            ? redirect()->to(route('video-projects.anchor', $id).'#modelSheetPanel')->with('success', $message.' Thiết kế đạt kiểm — render model sheet.')
            : back()->with('success', $message);
    }

    public function resetVesselDesign(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = app(VesselDesignService::class)->reset($id);

        return $done
            ? back()->with('success', $reason === 'pending' ? self::CANCELLED : 'Đã reset — bấm Thiết kế tàu để chạy lại.')
            : back()->with('error', $reason);
    }

    public function renderModelSheet(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'ModelSheetRenderForm');
        [$image, $reason] = app(ModelSheetService::class)->render($id, (string) auth()->user()?->name, [
            'model' => (string) $data['model'],
            'quality' => (string) $data['quality'],
            'attempt' => (string) $data['attempt'],
            'qa_stage_id' => (string) $data['qa_stage_id'],
            'prompt_sha256' => (string) $data['prompt_sha256'],
        ], $this->requester());

        if ($image === null) {
            return back()->with('error', $reason);
        }

        if ($reason === 'queued') {
            return redirect()->to(route('video-projects.anchor', $id).'#modelSheetPanel')->with('success', "Đã đưa render master ({$image->image_code}) vào hàng đợi — chạy nền trên server, tự cắt tám ô khi xong; đóng trang vẫn chạy tiếp, trang tự cập nhật khi xong.");
        }

        if (str_starts_with($reason, 'processing_failed|')) {
            return back()->with('error', "Đã render master ({$image->image_code}) nhưng xử lý ảnh lỗi: ".substr($reason, strlen('processing_failed|')).' — bấm "Xử lý lại từ master", không cần render lại.');
        }

        return match ($reason) {
            'rendered' => back()->with('success', "Đã render master ({$image->image_code}) và cắt tám ô — kiểm tra rồi duyệt hoặc từ chối cả sheet."),
            'already_exists' => back()->with('error', "Lượt render này đã được gửi trước đó ({$image->image_code}, trạng thái {$image->status}) — không gọi provider lần nữa. Tải lại trang để có lượt mới."),
            default => back()->with('error', "Render master chưa thành công ({$reason}): ".(string) $image->render_error),
        };
    }

    public function qaModelSheet(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate(['operation_id' => ['required', 'uuid']], ['operation_id.*' => 'Thiếu mã thao tác — tải lại trang.']);
        [$stage, $reason] = app(ModelSheetService::class)->qa($id, $request->boolean('retry'), (string) $data['operation_id'], $this->requester());

        if ($stage !== null && $reason === 'queued') {
            return $this->queued($request, $id, $stage, 'QA model sheet', route('video-projects.anchor', $id).'#modelSheetPanel');
        }

        return match ($reason) {
            'ready' => back()->with('success', 'QA xong — prompt đạt kiểm. Xem prompt cuối, thay đổi và findings rồi bấm Render.'),
            'not_ready' => back()->with('error', 'QA xong nhưng prompt chưa đạt kiểm — xem lỗi và findings bên dưới. Sửa ở thiết kế nếu là lỗi nguồn, hoặc bấm "QA lại".'),
            'cached' => back()->with('success', 'QA đã có cho đúng nguồn này — không gọi Sonnet lần nữa.'),
            'replayed' => back()->with('success', 'Thao tác này đã chạy trước đó — không gọi Sonnet lần nữa.'),
            default => back()->with('error', $reason),
        };
    }

    public function resetModelSheetQa(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = app(ModelSheetQaService::class)->reset($id);

        return $done
            ? back()->with('success', $reason === 'pending' ? self::CANCELLED : 'Đã reset lượt QA model sheet.')
            : back()->with('error', $reason);
    }

    public function approveModelSheet(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'AnchorApproveForm');
        [$done, $reason] = app(ModelSheetService::class)->approve($id, (string) $data['artifact_id'], auth()->id());

        if (! $done) {
            return back()->with('error', $reason);
        }

        return back()->with('success', 'Đã duyệt model sheet — đây chưa phải DESIGN LOCK.');
    }

    public function rejectModelSheet(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate(['image_id' => ['required', 'uuid']], ['image_id.*' => 'Thiếu master cần từ chối.']);
        [$done, $reason] = app(ModelSheetService::class)->reject($id, (string) $data['image_id']);

        return $done ? back()->with('success', 'Đã từ chối cả master — render một master mới.') : back()->with('error', $reason);
    }

    public function processModelSheet(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate(['image_id' => ['required', 'uuid']], ['image_id.*' => 'Thiếu master cần xử lý.']);
        [$done, $reason] = app(ModelSheetService::class)->process($id, (string) $data['image_id']);

        return match (true) {
            ! $done => back()->with('error', $reason),
            $reason === 'already_processed' => back()->with('success', 'Tám ô cắt đã đủ và khớp sha256 — không cần cắt lại.'),
            default => back()->with('success', 'Đã xử lý lại từ master đã lưu — không gọi model.'),
        };
    }

    public function writeScript(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate(['operation_id' => ['required', 'uuid']], ['operation_id.*' => 'Thiếu mã thao tác — tải lại trang.']);
        [$stage, $reason] = app(ScriptWriterService::class)->run($id, $request->boolean('rewrite'), (string) $data['operation_id'], $this->requester());

        if ($stage === null) {
            return back()->with('error', $reason);
        }

        if ($reason === 'queued') {
            return $this->queued($request, $id, $stage, 'viết kịch bản', route('video-projects.anchor', $id).'#scriptPanel');
        }

        $panel = redirect()->to(route('video-projects.anchor', ['id' => $id, 'script' => $stage->id]).'#scriptPanel');

        return match ($reason) {
            'cached' => $panel->with('success', 'Nguồn, prompt và brief không đổi — dùng lại bản kịch bản đã lưu, không gọi model.'),
            'replayed' => $panel->with('success', 'Thao tác này đã chạy trước đó — không gọi model lần nữa.'),
            default => $panel->with('success', 'Đã lưu bản kịch bản — xem kết quả kiểm rồi duyệt.'),
        };
    }

    public function resetScript(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = app(ScriptWriterService::class)->reset($id);

        return $done
            ? back()->with('success', $reason === 'pending' ? self::CANCELLED : 'Đã reset lượt viết kịch bản bị kẹt — bản đã duyệt giữ nguyên.')
            : back()->with('error', $reason);
    }

    public function saveScriptBrief(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate([
            'video_length' => ['nullable', 'integer'],
            'aspect_ratio' => ['nullable', 'string', 'max:10'],
            'platform' => ['nullable', 'string', 'max:200'],
            'tone_notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'video_length.*' => 'Thời lượng phải là số nguyên giây.',
            'aspect_ratio.*' => 'Tỷ lệ khung không hợp lệ.',
            'platform.*' => 'Nền tảng quá dài.',
            'tone_notes.*' => 'Ghi chú giọng điệu quá dài.',
        ]);
        [$done, $reason] = app(ScriptWriterService::class)->saveBrief($id, $data);

        return $done
            ? redirect()->to(route('video-projects.anchor', $id).'#scriptPanel')->with('success', 'Đã lưu brief kịch bản của dự án — lượt viết sau dùng brief này; bản đã viết giữ nguyên.')
            : back()->with('error', $reason);
    }

    public function approveScript(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate([
            'stage_id' => ['required', 'uuid'],
            'content_hash' => ['required', 'string', 'max:80'],
            'selection_version' => ['required', 'integer', 'min:0'],
            'operation_id' => ['required', 'uuid'],
        ], [
            'stage_id.*' => 'Thiếu bản kịch bản cần duyệt — tải lại trang.',
            'content_hash.*' => 'Thiếu hash nội dung — tải lại trang.',
            'selection_version.*' => 'Thiếu phiên bản lựa chọn — tải lại trang.',
            'operation_id.*' => 'Thiếu mã thao tác — tải lại trang.',
        ]);
        [$decision, $reason] = app(ScriptSourceService::class)->approve(
            $id,
            (string) $data['stage_id'],
            (string) $data['content_hash'],
            (int) $data['selection_version'],
            (string) $data['operation_id'],
            auth()->id(),
        );

        if ($decision === null) {
            return back()->with('error', $reason);
        }

        return redirect()->route('video-projects.locations', $id)
            ->with('success', $reason === 'replayed'
                ? 'Lần duyệt này đã được ghi trước đó — không ghi thêm.'
                : 'Đã duyệt và chọn bản kịch bản này làm nguồn cho các công đoạn sau.');
    }

    public function locations(Request $request, string $id)
    {
        $project = $this->ownedProject($id);
        $stage = $request->query('stage');

        return view('video-projects.locations', $this->chrome() + [
            'project' => $project,
            'id' => $id,
            'step' => app(LocationWriterService::class)->panel($id, is_string($stage) && Str::isUuid($stage) ? $stage : null),
            'scenesSupported' => app(SceneSourceService::class)->supports($id),
        ]);
    }

    public function writeLocations(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate([
            'operation_id' => ['required', 'uuid'],
            'effort' => ['nullable', 'string', Rule::in((array) config('video.superyacht.locations.efforts'))],
        ], ['operation_id.*' => 'Thiếu mã thao tác — tải lại trang.', 'effort.*' => 'Mức effort không hợp lệ.']);
        [$stage, $reason] = app(LocationWriterService::class)->run($id, $request->boolean('rewrite'), (string) $data['operation_id'], isset($data['effort']) ? (string) $data['effort'] : null, $this->requester());

        if ($stage === null) {
            return back()->with('error', $reason);
        }

        if ($reason === 'queued') {
            return $this->queued($request, $id, $stage, 'tạo địa điểm và bối cảnh', route('video-projects.locations', $id));
        }

        return redirect()->route('video-projects.locations', ['id' => $id, 'stage' => $stage->id])->with('success', match ($reason) {
            'cached' => 'Nguồn và prompt không đổi — dùng lại bộ địa điểm và bối cảnh đã lưu, không gọi model.',
            'replayed' => 'Thao tác này đã chạy trước đó — không gọi model lần nữa.',
            default => 'Đã lưu địa điểm và bối cảnh — xem kết quả kiểm và prompt rồi render.',
        });
    }

    public function resetLocations(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = app(LocationWriterService::class)->reset($id);

        if (! $done) {
            return back()->with('error', $reason);
        }

        return back()->with('success', $reason === 'pending' ? self::CANCELLED : 'Đã reset lượt tạo địa điểm và bối cảnh bị kẹt.');
    }

    /** @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse a run taken into the queue: 202 for a script, the page with a notice for a form */
    private function queued(Request $request, string $id, VideoPlanningStage $stage, string $label, string $page)
    {
        return $request->expectsJson()
            ? response()->json(['status' => (string) $stage->status, 'stage_id' => (string) $stage->id, 'status_url' => route('video-projects.stage-status', [$id, $stage->id])], 202)
            : redirect()->to($page)->with('success', "Đã đưa lượt {$label} vào hàng đợi — chạy nền trên server; đóng trang vẫn chạy tiếp, trang tự cập nhật khi xong.");
    }

    public function stageStatus(string $id, string $stage)
    {
        $this->ownedProject($id);

        $row = Str::isUuid($stage)
            ? VideoPlanningStage::query()->withoutSnapshot()->where('project_id', $id)->find($stage)
            : null;

        abort_if($row === null, 404);

        return response()->json($this->stageState($id, $row));
    }

    public function tasks(Request $request, string $id)
    {
        $this->ownedProject($id);
        $ids = static fn (string $kind): array => array_slice(array_values(array_filter(explode(',', (string) $request->query($kind, '')), static fn (string $value): bool => Str::isUuid($value))), 0, 50);

        return response()->json([
            'stages' => VideoPlanningStage::query()->withoutSnapshot()->where('project_id', $id)->whereIn('id', $ids('stages'))->get()
                ->mapWithKeys(fn (VideoPlanningStage $row): array => [(string) $row->id => $this->stageState($id, $row)])
                ->all(),
            'images' => VideoDesignImage::query()->where('project_id', $id)->whereIn('id', $ids('images'))->get(['id', 'project_id', 'image_type', 'status', 'render_error', 'prompt_spec_json', 'claimed_at', 'render_queue'])
                ->mapWithKeys(fn (VideoDesignImage $image): array => [(string) $image->id => $this->imageState($id, $image)])
                ->all(),
        ]);
    }

    /** @return array<string, mixed> what a page shows of a cell render while a job waits for it or a worker sends it, and where its result is shown once it ended */
    private function imageState(string $id, VideoDesignImage $image): array
    {
        $task = ImageRenderQueue::taskOf($image);
        $failed = $task === null && $image->status === DesignImageStatus::FAILED->value;

        return [
            'image_id' => (string) $image->id,
            'status' => $task['status'] ?? ($failed ? 'failed' : (string) $image->status),
            'done' => $task === null,
            'requested_by' => $task['requested_by'] ?? null,
            'queued_at' => $task['queued_at'] ?? null,
            'started_at' => $task['started_at'] ?? null,
            'retry_at' => $task['retry_at'] ?? null,
            'progress' => null,
            'error' => $failed ? Str::limit((string) $image->render_error, 500) : null,
            'result_url' => $task !== null ? null : match (true) {
                $image->image_type === DesignImageStore::LOCATION_REFERENCE_TYPE => route('video-projects.locations', $id).'#ref_'.(((array) $image->prompt_spec_json)['request_id'] ?? ''),
                is_string(((array) $image->prompt_spec_json)['shot_id'] ?? null) => route('video-projects.keyframes', $id).'#shot-'.((array) $image->prompt_spec_json)['shot_id'],
                default => route('video-projects.anchor', $id).'#modelSheetPanel',
            },
        ];
    }

    /** @return array<string, mixed> what a page shows of a run while it waits, runs or has ended; never its snapshot */
    private function stageState(string $id, VideoPlanningStage $row): array
    {
        $task = PlanningStageStore::taskOf($row);
        $done = in_array($row->status, [VideoPlanningStageStatus::SUCCEEDED->value, VideoPlanningStageStatus::FAILED->value], true);
        $stream = PlanningStageStore::streamOf($row);

        return [
            'stage_id' => (string) $row->id,
            'step' => (string) $row->stage,
            'revision' => (int) $row->planning_revision,
            'status' => (string) $row->status,
            'done' => $done,
            'requested_by' => $task['requested_by'] ?? (PlanningStageStore::metadataOf(is_array($row->input_json) ? $row->input_json : null)['requested_by']['name'] ?? null),
            'queued_at' => $task['queued_at'] ?? null,
            'started_at' => $row->started_at?->toIso8601String(),
            'finished_at' => $row->finished_at?->toIso8601String(),
            'retry_at' => $task['retry_at'] ?? null,
            'progress' => $stream === null ? null : array_intersect_key($stream, array_flip(['state', 'phase', 'text_chars', 'thinking_chars', 'output_tokens', 'elapsed_seconds'])),
            'error' => $row->status === VideoPlanningStageStatus::FAILED->value ? Str::limit((string) $row->error_message, 500) : null,
            'result_url' => $done ? $this->resultUrl($id, $row) : null,
        ];
    }

    /** @return string the page that shows a finished run: the run itself when it succeeded, the step page when it failed */
    private function resultUrl(string $id, VideoPlanningStage $row): string
    {
        $succeeded = $row->status === VideoPlanningStageStatus::SUCCEEDED->value;

        return match ($row->stage) {
            PlanningStageName::LOCATIONS->value => route('video-projects.locations', $succeeded ? ['id' => $id, 'stage' => $row->id] : ['id' => $id]),
            PlanningStageName::SCRIPT->value => route('video-projects.anchor', $succeeded ? ['id' => $id, 'script' => $row->id] : ['id' => $id]).'#scriptPanel',
            PlanningStageName::SCENES->value => route('video-projects.scene', $succeeded ? ['id' => $id, 'stage' => $row->id] : ['id' => $id]),
            PlanningStageName::SHOTS->value => route('video-projects.scene', $succeeded ? ['id' => $id, 'shots' => $row->id] : ['id' => $id]),
            PlanningStageName::YACHT_DESIGN->value => route('video-projects.anchor', $id).($succeeded && ($row->output_json['check']['verdict'] ?? null) === YachtDesignCheck::PASS ? '#modelSheetPanel' : ''),
            PlanningStageName::MODEL_SHEET_PROMPT->value => route('video-projects.anchor', $id).'#modelSheetPanel',
            default => route('video-projects.anchor', $id),
        };
    }

    /** @return ?array{id: string, name: string} */
    private function requester(): ?array
    {
        $user = auth()->user();

        return $user === null ? null : ['id' => (string) $user->getAuthIdentifier(), 'name' => (string) ($user->name ?? '')];
    }

    public function writeScenes(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate(['operation_id' => ['required', 'uuid']], ['operation_id.*' => 'Thiếu mã thao tác — tải lại trang.']);
        [$stage, $reason] = app(SceneWriterService::class)->run($id, $request->boolean('rewrite'), (string) $data['operation_id'], $this->requester());

        if ($stage === null) {
            return back()->with('error', $reason);
        }

        if ($reason === 'queued') {
            return $this->queued($request, $id, $stage, 'chia scene', route('video-projects.scene', $id));
        }

        return redirect()->route('video-projects.scene', ['id' => $id, 'stage' => $stage->id])->with('success', match ($reason) {
            'cached' => 'Nguồn và prompt không đổi — dùng lại bản chia scene đã lưu, không gọi model.',
            'replayed' => 'Thao tác này đã chạy trước đó — không gọi model lần nữa.',
            default => 'Đã lưu bản chia scene — xem kết quả kiểm rồi duyệt.',
        });
    }

    public function resetScenes(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = app(SceneWriterService::class)->reset($id);

        return $done ? back()->with('success', $reason === 'pending' ? self::CANCELLED : 'Đã reset lượt chia scene bị kẹt — bản đã duyệt giữ nguyên.') : back()->with('error', $reason);
    }

    public function writeShots(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate(['operation_id' => ['required', 'uuid']], ['operation_id.*' => 'Thiếu mã thao tác — tải lại trang.']);
        [$stage, $reason] = app(ShotWriterService::class)->run($id, $request->boolean('rewrite'), (string) $data['operation_id'], $this->requester());

        if ($stage === null) {
            return back()->with('error', $reason);
        }

        if ($reason === 'queued') {
            return $this->queued($request, $id, $stage, 'lập kế hoạch shot', route('video-projects.scene', $id));
        }

        return redirect()->route('video-projects.scene', ['id' => $id, 'shots' => $stage->id])->with('success', match ($reason) {
            'cached' => 'Nguồn và prompt không đổi — dùng lại kế hoạch shot đã lưu, không gọi model.',
            'replayed' => 'Thao tác này đã chạy trước đó — không gọi model lần nữa.',
            default => 'Đã lưu kế hoạch shot — xem kết quả kiểm rồi duyệt.',
        });
    }

    public function resetShots(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = app(ShotWriterService::class)->reset($id);

        return $done ? back()->with('success', $reason === 'pending' ? self::CANCELLED : 'Đã reset lượt lập kế hoạch shot bị kẹt — bản đã duyệt giữ nguyên.') : back()->with('error', $reason);
    }

    public function approveShots(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate([
            'stage_id' => ['required', 'uuid'],
            'content_hash' => ['required', 'string', 'max:80'],
            'selection_version' => ['required', 'integer', 'min:0'],
            'operation_id' => ['required', 'uuid'],
        ], [
            'stage_id.*' => 'Thiếu kế hoạch shot cần duyệt — tải lại trang.',
            'content_hash.*' => 'Thiếu hash nội dung — tải lại trang.',
            'selection_version.*' => 'Thiếu phiên bản lựa chọn — tải lại trang.',
            'operation_id.*' => 'Thiếu mã thao tác — tải lại trang.',
        ]);
        [$decision, $reason] = app(ShotSourceService::class)->approve(
            $id,
            (string) $data['stage_id'],
            (string) $data['content_hash'],
            (int) $data['selection_version'],
            (string) $data['operation_id'],
            auth()->id(),
        );

        if ($decision === null) {
            return back()->with('error', $reason);
        }

        return redirect()->route('video-projects.scene', ['id' => $id, 'shots' => $data['stage_id']])
            ->with('success', $reason === 'replayed'
                ? 'Lần duyệt này đã được ghi trước đó — không ghi thêm.'
                : 'Đã duyệt kế hoạch shot này.');
    }

    public function renderLocationReference(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate([
            'request_id' => ['required', 'regex:/^(P|BG)[0-9]{2}$/'],
            'image_id' => ['nullable', 'uuid'],
            'stage_id' => ['required_without:image_id', 'nullable', 'uuid'],
            'content_hash' => ['required_without:image_id', 'nullable', 'string', 'max:80'],
            'prompts_hash' => ['required_without:image_id', 'nullable', 'string', 'max:80'],
            'prompt_sha256' => ['required_without:image_id', 'nullable', 'string', 'max:80'],
            'selection_version' => ['required_without:image_id', 'nullable', 'integer', 'min:0'],
            'provider_model' => ['nullable', 'string', 'max:120'],
            'quality' => ['nullable', 'string', 'max:20'],
            'size' => ['nullable', 'string', 'max:20'],
            'aspect_ratio' => ['nullable', 'string', 'max:10'],
            'image_size' => ['nullable', 'string', 'max:10'],
            'variations' => ['nullable', 'integer', 'min:1'],
            'operation_id' => ['required', 'uuid'],
        ], ['*' => 'Thiết lập render không hợp lệ — tải lại trang.']);
        $service = app(LocationReferenceService::class);
        [$image, $reason] = isset($data['image_id'])
            ? $service->retry($id, (string) $data['image_id'], $this->requester())
            : $service->render($id, (string) auth()->user()?->name, (string) $data['request_id'], $data, (string) $data['operation_id'], [
                'stage_id' => (string) $data['stage_id'],
                'content_hash' => (string) $data['content_hash'],
                'prompts_hash' => (string) $data['prompts_hash'],
                'prompt_sha256' => (string) $data['prompt_sha256'],
                'selection_version' => (int) $data['selection_version'],
            ], auth()->id(), $this->requester());

        return $image === null
            ? back()->with('error', $reason)
            : redirect()->to(route('video-projects.locations', $id).'#ref_'.$data['request_id'])->with('success', match ($reason) {
                'already_exists' => 'Ảnh với đúng thiết lập này đã có — không render lại.',
                'queued' => "Đã đưa render {$data['request_id']} vào hàng đợi — chạy nền trên server; đóng trang vẫn chạy tiếp, trang tự cập nhật khi xong.",
                default => 'Render xong — duyệt ảnh cho bối cảnh này.',
            });
    }

    public function chooseLocationReference(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $request->validate([
            'request_id' => ['required', 'regex:/^(P|BG)[0-9]{2}$/'],
            'artifact_id' => ['required', 'uuid'],
            'selection_version' => ['required', 'integer', 'min:0'],
        ], ['*' => 'Thiếu dữ liệu chọn ảnh — tải lại trang.']);
        [$done, $reason] = app(LocationSourceService::class)->choose($id, (string) $data['request_id'], (string) $data['artifact_id'], (int) $data['selection_version']);

        return $done
            ? redirect()->to(route('video-projects.locations', $id).'#ref_'.$data['request_id'])->with('success', 'Đã duyệt ảnh cho '.$data['request_id'].'.')
            : back()->with('error', $reason);
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

    private function renderOutcome(string $reason, \App\Models\VideoDesignImage $image): string
    {
        return match ($reason) {
            'rendered' => __('messages.anchor_image_rendered', ['code' => $image->image_code]),
            'failed' => __('messages.anchor_image_failed', [
                'code' => $image->image_code,
                'reason' => (string) $image->render_error,
            ]),
            'not_enqueueable' => __('messages.anchor_render_not_enqueueable', ['code' => $image->image_code]),
            'already_exists' => __('messages.reference_already_exists', ['code' => $image->image_code]),
            default => $reason,
        };
    }

    private function anchorMessage(string $reason): string
    {
        if (str_starts_with($reason, 'film_cut_screenplay_invalid|')) {
            return __('messages.film_cut_screenplay_invalid', ['detail' => substr($reason, strlen('film_cut_screenplay_invalid|'))]);
        }

        return match ($reason) {
            'project_not_found' => __('messages.project_not_found'),
            'image_not_found' => __('messages.anchor_image_not_found'),
            'choose_prompt_settings' => __('messages.anchor_choose_prompt_settings'),
            'anchor_prompt_missing' => __('messages.anchor_prompt_missing'),
            'character_prompt_no_screenplay' => 'Chưa có bản phân cảnh được duyệt và chọn cho production — duyệt, chọn bản phân cảnh rồi mới viết prompt cho từng nhân vật.',
            'character_prompt_unknown_character' => 'Nhân vật này không có trong bản phân cảnh production.',
            'character_not_main' => 'Chỉ nhân vật chính (protagonist) mới có anchor — nhân vật phụ được mô tả bằng chữ khi render. Không gọi model, không render, không duyệt.',
            'character_prompt_v3_profile_uses_design' => 'Tàu này lấy từ bản thiết kế v3 — anchor viết từ bản thiết kế, không qua đường viết prompt nhân vật cũ. Không gọi model.',
            'anchor_prompt_other_screenplay' => 'Prompt hoặc ảnh này thuộc một bản phân cảnh khác bản production đang chọn — viết lại prompt trước.',
            'screenplay_has_no_main_object' => 'Bản phân cảnh production không có nhân vật chính loại object để làm anchor chính.',
            'character_prompt_running' => 'Đang có một lượt viết prompt chạy cho dự án này.',
            'anchor_prompt_stale' => __('messages.anchor_prompt_stale'),
            'no_inspiration_brief' => __('messages.anchor_no_inspiration_brief'),
            'inspiration_phase1_not_supported' => 'Lượt Inspiration mới nhất của dự án chạy theo Phase 1 v6.3 (phản hồi chưa kiểm nội dung) — bước này chưa hỗ trợ kết quả đó và không dùng brief cũ. Không gọi model.',
            'artifact_not_found' => __('messages.anchor_artifact_not_found'),
            'not_approvable' => __('messages.anchor_not_approvable'),
            'no_approved_anchor' => __('messages.no_approved_anchor'),
            'no_scene_profile' => __('messages.scene_no_profile'),
            'identity_summary_unreadable' => __('messages.scene_identity_unreadable'),
            'storyboard_design_source_missing', 'storyboard_design_source_invalid', 'storyboard_design_revision_mismatch',
            'storyboard_design_identity_missing', 'storyboard_film_profile_invalid', 'storyboard_source_changed', 'storyboard_recheck_input_changed',
            'storyboard_nothing_to_recheck', 'storyboard_finding_reason_required', 'storyboard_finding_not_found',
            'storyboard_finding_not_acceptable', 'storyboard_finding_after_approval' => __('messages.'.$reason),
            'storyboard_running' => __('messages.storyboard_running'),
            'storyboard_unchanged' => __('messages.storyboard_unchanged'),
            'storyboard_failed' => __('messages.storyboard_failed'),
            'storyboard_failed_unrecorded' => __('messages.storyboard_failed_unrecorded'),
            'storyboard_claim_lost' => __('messages.storyboard_claim_lost'),
            'storyboard_needs_beats' => __('messages.storyboard_needs_beats'),
            'scene_plan_misconfigured' => __('messages.scene_plan_misconfigured'),
            'film_cut_misconfigured' => __('messages.film_cut_misconfigured'),
            'film_cut_design_missing' => __('messages.film_cut_design_missing'),
            'film_cut_screenplay_outdated' => __('messages.film_cut_screenplay_outdated'),
            'storyboard_mode_unknown' => __('messages.storyboard_mode_unknown'),
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
            'screenplay_profile_invalid' => __('messages.screenplay_profile_invalid'),
            'screenplay_profile_changed' => 'Nội dung kịch bản đang chọn được viết theo profile kịch bản khác với profile hiện hành — tạo lại nội dung kịch bản. Không gọi model.',
            'screenplay_launch_means_missing' => 'Nội dung kịch bản chưa nêu phương thức hạ thủy — bổ sung hoặc tạo lại nội dung kịch bản, duyệt rồi mới tạo lại địa điểm. Lượt này được ghi là thất bại để xem lại; bản địa điểm thành công trước đó vẫn giữ nguyên.',
            'story_profile_design_changed' =>'Phần profile mà bản thiết kế tàu đã dùng (yêu cầu thiết kế, danh sách phòng) đã đổi kể từ lúc thiết kế — thiết kế lại tàu trước khi viết kịch bản. Không gọi model.',
            'screenplay_profile_unknown' =>'Không xác định được profile kịch bản mà bản nội dung này đã dùng — tạo lại nội dung kịch bản. Không gọi model.',
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
            'screenplay_locations_without_brief_spaces' => 'Bộ địa điểm đang chọn gắn không gian của yêu cầu project (brief_space) sai: trùng phòng, phòng không có trong yêu cầu hoặc không thuộc tàu — tạo lại địa điểm theo nội dung kịch bản hiện tại. Không gọi model.',
            'screenplay_film_brief_invalid' => 'Khối film_brief trong profile kịch bản không hợp lệ hoặc không khớp coverage của profile. Không gọi model — xem log để biết mục nào.',
            'screenplay_locations_unprofiled' =>'Danh sách địa điểm này tạo theo mẫu cũ (chỉ có tên và mô tả), thiếu bố cục, lối nối, thiết bị cố định và nguồn sáng — tạo lại địa điểm.',
            'workflow_not_design_first' => 'Dự án này chạy luồng cũ (kịch bản trước, thiết kế sau). Luồng thiết kế trước chỉ áp dụng cho dự án mới.',
            'screenplay_characters_from_design' => 'Nhân vật được đóng gói tự động từ bản thiết kế tàu khi tạo nội dung kịch bản, không tạo bằng model.',
            'character_prompt_no_design' => 'Chưa có bản thiết kế tàu — bấm Thiết kế tàu trước khi viết prompt anchor.',
            'anchor_prompt_other_design' => 'Prompt hoặc ảnh này thuộc một bản thiết kế khác bản thiết kế hiện hành — viết lại prompt anchor từ bản thiết kế mới.',
            'anchor_design_unresolved' => 'Bản thiết kế hiện hành còn quyết định thiết kế chưa chốt — chốt các quyết định đó trước khi viết prompt hoặc render anchor. Không gọi model.',
            'anchor_prompt_unstamped' => 'Prompt anchor này viết trước khi có dấu nguồn (bước viết, nguồn, skill, khung hình) — viết lại prompt anchor. Không render.',
            'anchor_prompt_size_unsupported' => 'Prompt anchor ghép cho Size đang chọn vượt giới hạn ký tự của model ảnh — chọn Size khác. Không render.',
            'anchor_design_placement_ambiguous' => 'Hai sàn ngoài trời nhìn thấy từ góc anchor có cùng tầng, cùng loại mà thiết kế không phân biệt được — thiếu dữ kiện định vị, cần bổ sung trong thiết kế. Không ghép prompt.',
            'anchor_design_not_canonical' => 'Bản thiết kế này không có phần hình học chuẩn (canonical) — tạo lại thiết kế trước khi ghép prompt anchor.',
            'anchor_design_missing_dimensions' => 'Thiết kế thiếu tên tàu, chiều dài hoặc chiều rộng — không ghép được prompt anchor.',
            'anchor_design_missing_decks' => 'Thiết kế chưa khai các tầng boong — không ghép được prompt anchor.',
            'anchor_design_missing_near_end' => 'Thiết kế thiếu mô tả phần mũi tàu (phía gần máy quay của ảnh anchor) — không ghép được prompt anchor.',
            'anchor_design_missing_side' => 'Thiết kế thiếu mô tả mặt mạn tàu (phía máy quay nhìn thấy) — không ghép được prompt anchor.',
            'anchor_design_opening_unplaced' => 'Có ô mở nhìn thấy từ góc anchor nhưng thiếu tầng hoặc thiếu phần chứa nó — bổ sung trong thiết kế. Không ghép prompt.',
            'anchor_design_missing_mandatory' => 'Thiết kế không có ràng buộc phải giữ (must_preserve) nào thấy được từ góc anchor — không ghép prompt.',
            'anchor_design_missing_masses' => 'Thiết kế chưa khai khối chính (primary_masses) thấy được từ góc anchor — không ghép prompt.',
            'anchor_design_moving_part_state_missing' => 'Có bộ phận chuyển động chưa khai trạng thái chuẩn (canonical_state) — bổ sung trong thiết kế, không tự chọn mở hay đóng. Không ghép prompt.',
            'anchor_prompt_too_long' => 'Prompt anchor ghép từ thiết kế vượt giới hạn ký tự của model ảnh. Không render.',
            'anchor_prompt_source_changed' => 'Thiết kế hoặc bộ ghép prompt đã đổi so với prompt đang xem — tải lại trang để ghép prompt mới. Không render.',
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
            'anchor_prompt_not_written' => 'Chưa có prompt anchor cho bản thiết kế hiện hành — bấm "Viết prompt anchor bằng Claude".',
            'anchor_geometry_call_failed' => 'Gọi Claude viết hình học anchor thất bại — phản hồi (nếu có) và usage đã lưu, prompt đang dùng giữ nguyên.',
            'anchor_geometry_invalid' => 'Kết quả Claude không qua kiểm tra (bảng đối soát, số liệu, id hoặc lệnh camera) — raw và usage đã lưu, prompt đang dùng giữ nguyên.',
            'anchor_geometry_source_changed' => 'Bản thiết kế đã đổi trong lúc Claude chạy — kết quả được giữ để đối chiếu, không áp dụng.',
            'anchor_prompt_needs_review' => 'Prompt anchor còn mục cần xử lý (thiếu dữ liệu, mâu thuẫn hoặc xung đột chính sách) — chưa render được.',
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

    public function deleteReference(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'AnchorApproveForm');

        [$done, $reason] = $this->videoProjectService->deleteReference($id, (string) $data['artifact_id']);

        [$code, $detail] = array_pad(explode('|', $reason, 2), 2, '');

        return back()->with($done ? 'success' : 'error', match ($code) {
            'deleted' => __('messages.reference_deleted'),
            'artifact_in_use' => __('messages.reference_delete_in_use'),
            'artifact_used_by' => __('messages.reference_delete_used_by', ['users' => $detail]),
            'render_in_flight' => __('messages.reference_delete_in_flight'),
            'file_not_deleted' => __('messages.reference_delete_file_failed'),
            'delete_failed' => __('messages.reference_delete_rolled_back'),
            'file_left_in_trash' => __('messages.reference_delete_file_in_trash', ['trash' => $detail]),
            default => $this->anchorMessage($reason),
        });
    }

    public function lockReferences(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = app(VesselDesignService::class)->lockReferences($id, auth()->id());

        return $done
            ? redirect()->route('video-projects.anchor', $id)->with('success', $this->anchorMessage($reason))
            : back()->with('error', $this->anchorMessage($reason));
    }

    public function reference(Request $request, string $id)
    {
        $this->ownedProject($id);

        $parts = app(\App\Services\Video\PartsDetailService::class);

        if ($parts->supports($id)) {
            if ($request->isMethod('post')) {
                return redirect()->route('video-projects.reference', $id)->with('error', 'Dùng các thao tác của bảng Reference.');
            }

            return view('video-projects.reference', $this->chrome() + [
                'id' => $id,
                'project' => VideoProject::query()->findOrFail($id),
                'motoReference' => $parts->panel($id),
                'motoCharacters' => app(\App\Services\Video\MotoCharacterReferenceService::class)->panel($id),
            ]);
        }

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

    public function partsDetail(Request $request, string $id, string $action)
    {
        $this->ownedProject($id);
        $service = app(\App\Services\Video\PartsDetailService::class);
        abort_unless($service->supports($id), 404);
        $cells = ['cells' => 'required|array|min:1|max:100', 'cells.*' => 'required|string|regex:/^R[0-9a-f]{10}$/'];
        $data = $request->validate(match ($action) {
            'plan' => ['hash' => 'required|string|max:80'],
            'layout' => ['rows' => 'required|integer', 'columns' => 'required|integer', 'size' => 'required|string|max:20', 'model' => 'required|string|max:100', 'quality' => 'required|string|max:30'],
            'review' => ['plan_id' => 'required|uuid', 'operation_id' => 'required|uuid', 'retry' => 'nullable|boolean', 'confirm_cost' => 'accepted'] + $cells,
            'render' => ['hash' => 'required|string|max:80', 'sheet_hash' => 'required|string|max:80', 'model' => 'required|string|max:100', 'quality' => 'required|string|max:30', 'operation_id' => 'required|uuid', 'confirm_cost' => 'accepted'] + $cells,
            'accept' => ['plan_id' => 'required|uuid', 'reviews' => 'required|array|min:1|max:10', 'reviews.*' => 'required|uuid'],
            'process' => ['artifact_id' => 'required|uuid'],
            'approve', 'reject', 'revoke' => ['artifact_id' => 'required|uuid'],
            'answer' => ['hash' => 'required|string|max:80', 'reference_id' => 'required|string|regex:/^R[0-9a-f]{10}$/', 'decisions' => 'required|array|min:1|max:20', 'decisions.*.question' => 'required|string|max:2000', 'decisions.*.answer' => 'required|string|max:1000'],
            default => abort(404),
        });
        $requester = auth()->id() === null ? null : ['id' => (string) auth()->id(), 'name' => (string) auth()->user()?->name];

        try {
            $message = match ($action) {
                'plan' => (function () use ($service, $id, $data): string {
                    $service->save($id, $data['hash']);

                    return 'Đã lập kế hoạch Reference từ nguồn đã duyệt — không gọi AI, không render.';
                })(),
                'layout' => (function () use ($service, $id, $data): string {
                    $service->saveGridChoice($id, (int) $data['rows'], (int) $data['columns'], $data['size'], $data['model'], $data['quality']);

                    return 'Đã lưu lưới và cấu hình render — không gọi AI, không render.';
                })(),
                'review' => (function () use ($id, $data, $requester): string {
                    [$stage, $reason] = app(\App\Services\Video\ReferenceWriterService::class)->run($id, $data['plan_id'], array_values($data['cells']), (bool) ($data['retry'] ?? false), $data['operation_id'], $requester);

                    if ($stage === null) {
                        throw new \RuntimeException($reason);
                    }

                    return match ($reason) {
                        'queued' => 'Đã xếp hàng lượt kiểm prompt — máy chạy nền sẽ gọi model; tải lại trang để xem kết quả.',
                        'cached', 'replayed' => 'Các ô này đã có lượt kiểm prompt cho đúng nguồn — không gọi model thêm.',
                        default => 'Kiểm prompt xong: '.($stage->output_json['check']['verdict'] ?? $reason).'.',
                    };
                })(),
                'render' => (function () use ($service, $id, $data, $requester): string {
                    $plan = $service->save($id, $data['hash']);
                    [$image, $reason] = $service->render($id, (string) $plan->id, array_values($data['cells']), $data['sheet_hash'], $data['model'], $data['quality'], $data['operation_id'], (string) auth()->user()?->name, $requester);

                    return match (true) {
                        $image === null => throw new \RuntimeException($reason),
                        $reason === 'queued' => 'Đã xếp hàng render master — máy chạy nền gửi request, cắt ảnh khi xong.',
                        $reason === 'already_exists' => 'Lượt render này đã có ('.$image->status.') — không gọi thêm API.',
                        $reason === 'rendered' => 'Đã render và cắt master. Xem rồi duyệt hoặc từ chối cả sheet.',
                        $reason === 'rendered_stale' => throw new \RuntimeException('Master đã render và lưu lịch sử, nhưng nguồn đổi trong lúc render — lập lại kế hoạch trước khi dùng.'),
                        str_starts_with($reason, 'processing_failed|') => throw new \RuntimeException('Đã render nhưng cắt ảnh lỗi: '.substr($reason, 18)),
                        default => throw new \RuntimeException('Render: '.$reason.' — '.($image->render_error ?? 'xem lịch sử lượt render.')),
                    };
                })(),
                'accept' => (function () use ($service, $id, $data): string {
                    $service->accept($id, $data['plan_id'], $data['reviews'], auth()->id() === null ? null : (string) auth()->id());

                    return 'Đã xác nhận nội dung AI viết lại cho '.count($data['reviews']).' ô — master có thể render khi mọi ô đã đạt.';
                })(),
                'process' => 'Xử lý ảnh: '.$service->process($id, $data['artifact_id']),
                'approve' => (function () use ($service, $id, $data): string {
                    $service->approveSheet($id, $data['artifact_id'], auth()->id() === null ? null : (string) auth()->id());

                    return 'Đã duyệt cả sheet — các ảnh part được dùng chung cho shot.';
                })(),
                'reject' => (function () use ($service, $id, $data): string {
                    $service->rejectSheet($id, $data['artifact_id'], auth()->id() === null ? null : (string) auth()->id());

                    return 'Đã từ chối cả sheet — các part trở lại danh sách cần tạo.';
                })(),
                'revoke' => (function () use ($service, $id, $data): string {
                    $service->revokeSheet($id, $data['artifact_id'], auth()->id() === null ? null : (string) auth()->id());

                    return 'Đã thu hồi duyệt — sheet trở về chờ quyết định, các part vẫn giữ ở sheet này. Duyệt lại để dùng, hoặc từ chối cả sheet để render lại.';
                })(),
                'answer' => (function () use ($service, $id, $data): string {
                    $service->answer($id, $data['reference_id'], array_values($data['decisions']), $data['hash'], auth()->id() === null ? null : (string) auth()->id());

                    return 'Đã lưu quyết định và lập lại kế hoạch — không gọi AI. Ô này cần kiểm prompt lại với quyết định mới.';
                })(),
            };

            return redirect()->route('video-projects.reference', $id)->with('success', $message);
        } catch (\RuntimeException $e) {
            return redirect()->route('video-projects.reference', $id)->with('error', $e->getMessage());
        }
    }

    public function characterReference(Request $request, string $id, string $character, string $action)
    {
        $this->ownedProject($id);
        abort_unless(app(\App\Services\Video\PartsDetailService::class)->supports($id), 404);
        $service = app(\App\Services\Video\MotoCharacterReferenceService::class);
        $data = $request->validate(match ($action) {
            'settings' => ['model' => 'required|string|max:100', 'quality' => 'required|string|max:30', 'size' => 'required|string|max:20'],
            'render' => ['sheet_hash' => 'required|string|max:80', 'operation_id' => 'required|uuid', 'confirm_cost' => 'accepted'],
            'process', 'approve', 'reject', 'revoke' => ['artifact_id' => 'required|uuid'],
            default => abort(404),
        });
        $requester = auth()->id() === null ? null : ['id' => (string) auth()->id(), 'name' => (string) auth()->user()?->name];
        $reviewer = auth()->id() === null ? null : (string) auth()->id();
        $page = route('video-projects.reference', $id).'#motoCharacters';

        try {
            $message = match ($action) {
                'settings' => (function () use ($service, $id, $character, $data): string {
                    $service->saveChoice($id, $character, $data['model'], $data['quality'], $data['size']);

                    return 'Đã lưu cấu hình render của '.$character.' — chưa render.';
                })(),
                'render' => (function () use ($service, $id, $character, $data, $requester): string {
                    [$image, $reason] = $service->render($id, $character, $data['sheet_hash'], $data['operation_id'], (string) auth()->user()?->name, $requester);

                    return match (true) {
                        $image === null => throw new \RuntimeException($reason),
                        $reason === 'queued' => 'Đã xếp hàng render ảnh nhân vật — máy chạy nền gửi request.',
                        $reason === 'already_exists' => 'Lượt render này đã có ('.$image->status.') — không gọi thêm API.',
                        $reason === 'rendered' => 'Đã render ảnh nhân vật. Xem rồi duyệt hoặc từ chối.',
                        str_starts_with($reason, 'processing_failed|') => throw new \RuntimeException('Đã render nhưng cắt ảnh lỗi: '.substr($reason, 18)),
                        default => throw new \RuntimeException('Render: '.$reason.' — '.($image->render_error ?? 'xem lịch sử lượt render.')),
                    };
                })(),
                'process' => 'Xử lý ảnh: '.$service->process($id, $data['artifact_id']),
                'approve' => (function () use ($service, $id, $data, $reviewer): string {
                    $service->approve($id, $data['artifact_id'], $reviewer);

                    return 'Đã duyệt ảnh nhân vật — keyframe dùng được ảnh này.';
                })(),
                'reject' => (function () use ($service, $id, $data, $reviewer): string {
                    $service->reject($id, $data['artifact_id'], $reviewer);

                    return 'Đã từ chối — các ô của ảnh này trở lại danh sách cần tạo.';
                })(),
                'revoke' => (function () use ($service, $id, $data, $reviewer): string {
                    $service->revoke($id, $data['artifact_id'], $reviewer);

                    return 'Đã thu hồi duyệt — ảnh và lịch sử vẫn giữ; duyệt lại để dùng, hoặc từ chối để render lại.';
                })(),
            };

            return redirect()->to($page)->with('success', $message);
        } catch (\RuntimeException $e) {
            return redirect()->to($page)->with('error', $e->getMessage());
        }
    }

    public function keyframes(string $id)
    {
        $project = $this->ownedProject($id);
        abort_unless(app(\App\Services\Video\PartsDetailService::class)->supports($id), 404);

        return view('video-projects.keyframes', $this->chrome() + [
            'id' => $id,
            'project' => $project,
            'motoKeyframes' => app(\App\Services\Video\MotoKeyframeService::class)->panel($id),
        ]);
    }

    public function keyframeAction(Request $request, string $id, string $shot, string $action)
    {
        $this->ownedProject($id);
        abort_unless(app(\App\Services\Video\PartsDetailService::class)->supports($id), 404);
        $service = app(\App\Services\Video\MotoKeyframeService::class);
        $data = $request->validate(match ($action) {
            'source' => ['kind' => 'required|in:design,environment', 'from' => 'nullable|uuid', 'to' => 'required|uuid'],
            'settings' => ['model' => 'required|string|max:100', 'quality' => 'required|string|max:30', 'size' => 'required|string|max:20'],
            'render' => ['preview_hash' => 'required|string|max:80', 'operation_id' => 'required|uuid', 'confirm_cost' => 'accepted'],
            'approve', 'revoke' => ['artifact_id' => 'required|uuid'],
            default => abort(404),
        });
        $requester = auth()->id() === null ? null : ['id' => (string) auth()->id(), 'name' => (string) auth()->user()?->name];
        $reviewer = auth()->id() === null ? null : (string) auth()->id();
        $page = route('video-projects.keyframes', $id).'#shot-'.$shot;

        try {
            $message = match ($action) {
                'source' => (function () use ($service, $id, $shot, $data): string {
                    $service->saveSource($id, $shot, $data['kind'], $data['from'] ?? null, $data['to']);

                    return 'Đã đổi ảnh cho lượt keyframe này — nguồn gốc không đổi, chưa render.';
                })(),
                'settings' => (function () use ($service, $id, $shot, $data): string {
                    $service->saveSettings($id, $shot, $data['model'], $data['quality'], $data['size']);

                    return 'Đã lưu cấu hình render của shot — chưa render.';
                })(),
                'render' => (function () use ($service, $id, $shot, $data, $requester): string {
                    [$image, $reason] = $service->render($id, $shot, $data['preview_hash'], $data['operation_id'], $requester);

                    return match (true) {
                        $image === null => throw new \RuntimeException($reason),
                        $reason === 'queued' => 'Đã xếp hàng render keyframe — máy chạy nền gửi request.',
                        $reason === 'already_exists' => 'Lượt render này đã có ('.$image->status.') — không gọi thêm API.',
                        $reason === 'rendered' => 'Đã render keyframe. Xem ảnh rồi duyệt.',
                        default => throw new \RuntimeException('Render: '.$reason.' — '.($image->render_error ?? 'xem lịch sử lượt render.')),
                    };
                })(),
                'approve' => (function () use ($service, $id, $shot, $data, $reviewer): string {
                    $service->approve($id, $shot, $data['artifact_id'], $reviewer);

                    return 'Đã duyệt và dùng ảnh này cho '.$shot.'.';
                })(),
                'revoke' => (function () use ($service, $id, $shot, $data, $reviewer): string {
                    $service->revoke($id, $shot, $data['artifact_id'], $reviewer);

                    return 'Đã thu hồi duyệt — ảnh và lịch sử vẫn giữ, '.$shot.' chưa có keyframe đang dùng.';
                })(),
            };

            return redirect()->to($page)->with('success', $message);
        } catch (\RuntimeException $e) {
            return redirect()->to($page)->with('error', $e->getMessage());
        }
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

    public function writeReferencePlan(Request $request, string $id)
    {
        $this->ownedProject($id);

        [$stage, $reason, $violations] = $this->videoProjectService->writeReferencePlan($id, auth()->id(), $request->boolean('force'));
        $message = $this->referenceMessage(explode('|', $reason, 2)[0]);

        if ($violations !== []) {
            $message .= ' Chi tiết: '.implode(' | ', $violations);
        }

        return redirect()->to(route('video-projects.reference', $id).'#referencePlan')
            ->with($stage === null ? 'error' : 'success', $message);
    }

    public function decideReferencePlanItem(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'ReferenceImageForm', 'plan_decision');
        [$ok, $reason] = $this->videoProjectService->decideReferencePlanItem(
            $id, auth()->id(), (string) $data['reference_plan_stage_id'], (string) $data['request_id'], (string) $data['status'],
        );

        return redirect()->to(route('video-projects.reference', $id).'#referencePlan')
            ->with($ok ? 'success' : 'error', $ok ? 'Đã lưu quyết định cho mục Reference.' : $this->referenceMessage(explode('|', $reason, 2)[0]));
    }

    public function renderReferencePlanItem(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'ReferenceImageForm', 'plan_render');
        [$image, $reason] = $this->videoProjectService->renderReferencePlanItem($id, (string) auth()->user()?->name, $data);

        if ($image === null) {
            return redirect()->to(route('video-projects.reference', $id).'#referencePlan')
                ->with('error', $this->referenceMessage(explode('|', $reason, 2)[0]));
        }

        return redirect()->to(route('video-projects.reference', $id).'#referencePlan')
            ->with(in_array($reason, ['rendered', 'already_exists'], true) ? 'success' : 'error', $this->renderOutcome($reason, $image));
    }

    public function referenceSheetPreview(Request $request, string $id)
    {
        $this->ownedProject($id);

        $data = $this->form->validate($request, 'ReferenceImageForm', 'sheet_preview');

        [$sheet, $reason] = $this->videoProjectService->referenceSheetPreview(
            $id,
            (string) $data['sheet_state'],
            (string) $data['environment'],
        );

        if ($sheet === null) {
            return response()->json(['ok' => false, 'message' => $this->referenceMessage($reason)]);
        }

        return response()->json([
            'ok' => true,
            'prompt' => $sheet['prompt'],
            'prompt_sha256' => $sheet['prompt_sha256'],
            'length' => mb_strlen($sheet['prompt']),
        ]);
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
            'reference_sheet_needs_design' => 'Bộ 6 góc cần bản thiết kế đã chốt (thiết kế trước) — dự án này chưa có. Không gọi model.',
            'reference_sheet_finish_incomplete' => 'Thiết kế chưa chốt đủ thân tàu, thượng tầng, kính và vật liệu — chưa dùng được "Hoàn thiện theo thiết kế". Không gọi model.',
            'reference_sheet_prompt_too_long' => 'Prompt bộ 6 góc vượt giới hạn ký tự của model ảnh. Không gọi model.',
            'reference_sheet_state_unknown' => 'Trạng thái bộ 6 góc không hợp lệ.',
            'reference_sheet_design_name_missing' => 'Bản thiết kế v3 nhắc tới một bộ phận chưa được đặt tên — không đoán tên, không viết được prompt bộ 6 góc. Không gọi model.',
            'reference_writer_v3_uses_sheet' => 'Bản thiết kế v3 dùng bộ 6 góc ghép từ thiết kế, không qua đường viết prompt Reference từng góc. Không gọi model.',
            default => str_starts_with($reason, 'reference_plan_') ? __('messages.'.$reason) : $this->anchorMessage($reason),
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

        if ($request->expectsJson()) {
            return response()->json(['ok' => $done, 'message' => $this->anchorMessage($reason)]);
        }

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

            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $this->anchorMessage('environment_media_models_broken')])
                : back()->with('error', $this->anchorMessage('environment_media_models_broken'));
        }

        [$image, $reason] = $this->videoProjectService->renderEnvironmentDirect(
            $id,
            (string) auth()->user()?->name,
            $data,
        );

        if ($image === null) {
            return $request->expectsJson()
                ? response()->json(['ok' => false, 'message' => $this->anchorMessage($reason)])
                : back()->with('error', $this->anchorMessage($reason));
        }

        $level = in_array($reason, ['rendered', 'already_exists'], true) ? 'success' : 'error';

        if ($request->expectsJson()) {
            return response()->json(['ok' => $level === 'success', 'message' => $this->renderOutcome($reason, $image)]);
        }

        return redirect()
            ->route('video-projects.environment', $id)
            ->with($level, $this->renderOutcome($reason, $image));
    }

    public function scene(string $id, ?string $scene = null, ?string $step = null)
    {
        $project = $this->ownedProject($id);

        if (app(SceneSourceService::class)->supports($id)) {
            $shown = request()->query('stage');
            $shots = request()->query('shots');

            return view('video-projects.scene', $this->chrome() + [
                'id' => $id,
                'project' => $project,
                'scenesPanel' => app(SceneWriterService::class)->panel($id, is_string($shown) && Str::isUuid($shown) ? $shown : null),
                'shotsPanel' => app(ShotSourceService::class)->supports($id)
                    ? app(ShotWriterService::class)->panel($id, is_string($shots) && Str::isUuid($shots) ? $shots : null)
                    : null,
            ]);
        }

        $stage = SceneStep::tryFrom((string) ($step ?? SceneStep::PLANNING->value));

        abort_if($stage === null, 404);

        $plan = $this->videoProjectService->latestScenePlan($id);
        $scenes = $plan['scenes'];
        $current = $scene === null
            ? ($scenes[0] ?? null)
            : collect($scenes)->firstWhere('id', $scene);

        abort_if($scenes !== [] && $current === null, 404);

        $filmCut = $this->videoProjectService->filmCutView($id, (int) $plan['revision']);
        $keyframes = $this->videoProjectService->sceneKeyframeCells($id, $plan['revision']);
        $cells = $this->videoProjectService->sceneSourceCells($id, $plan['revision']);

        return view('video-projects.scene', $this->chrome() + [
            'filmCut' => $filmCut,
            'filmCutPreset' => (array) config('video.film_cut.preset'),
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
            'storyboard' => $this->videoProjectService->storyboardScenes($id),
            'storyboardRun' => $this->videoProjectService->storyboardRun($id),
            'approval' => $this->videoProjectService->storyboardApproval($id, (int) $plan['revision']),
            'findings' => $this->videoProjectService->storyboardFindings($id, (int) $plan['revision']),
            'readiness' => $this->videoProjectService->referenceReadiness($id, (int) $plan['revision'], $scenes, $cells),
            'storedAnswer' => $this->videoProjectService->storedStoryboardAnswer($id) !== null,
            'keyframeModels' => $this->videoProjectService->sceneKeyframeModels(),
            'keyframes' => $keyframes,
            'referenceRoles' => [
                'anchor' => 'ảnh neo',
                'source_keyframe' => 'keyframe trước',
                'identity' => 'nhận dạng',
                'environment' => 'tấm nền',
                'geometry' => 'hình học',
                'space_geometry' => 'nguồn hình học',
                'continuity' => 'liền mạch',
                'design_reference' => 'thiết kế',
            ],
            'sources' => collect($cells)
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

    public function generateStoryboard(Request $request, string $id)
    {
        $this->ownedProject($id);

        $mode = $request->input('mode', VideoProjectService::STORYBOARD_MODE);

        [$count, $reason] = $this->videoProjectService->generateStoryboard(
            $id,
            auth()->id(),
            $request->boolean('force'),
            is_string($mode) ? $mode : '',
        );

        $message = $count === null ? $this->anchorMessage($reason) : __('messages.'.$reason, ['count' => $count]);

        if (! $request->expectsJson()) {
            return redirect()->to(route('video-projects.anchor', $id).'#storyboard-panel')
                ->with($count === null ? 'error' : 'success', $message);
        }

        return response()->json(['ok' => $count !== null, 'reason' => $reason, 'message' => $message]);
    }

    public function recheckStoryboard(string $id)
    {
        $this->ownedProject($id);

        [$count, $reason] = $this->videoProjectService->recheckStoredStoryboard($id, auth()->id());

        return response()->json([
            'ok' => $count !== null,
            'reason' => $reason,
            'message' => $count === null ? $this->anchorMessage($reason) : __('messages.'.$reason, ['count' => $count]),
        ]);
    }

    public function acceptStoryboardFinding(Request $request, string $id)
    {
        $this->ownedProject($id);

        [$ok, $reason] = $this->videoProjectService->acceptStoryboardFinding(
            $id,
            auth()->id(),
            (int) $request->input('revision'),
            (string) $request->input('finding_id'),
            (string) $request->input('reason'),
        );

        return response()->json([
            'ok' => $ok,
            'reason' => $reason,
            'message' => $ok ? __('messages.storyboard_finding_accepted') : $this->anchorMessage($reason),
        ]);
    }

    public function confirmShotReference(Request $request, string $id, string $sceneId)
    {
        $this->ownedProject($id);

        [$ok, $reason] = $this->videoProjectService->confirmShotReference(
            $id,
            auth()->id(),
            $sceneId,
            (int) $request->input('index'),
            (string) $request->input('artifact_id'),
        );

        return response()->json([
            'ok' => $ok,
            'reason' => $reason,
            'message' => __('messages.'.($ok ? 'reference_confirmed' : $reason)),
        ]);
    }

    public function approveStoryboard(Request $request, string $id)
    {
        $this->ownedProject($id);

        [$revision, $reason] = $this->videoProjectService->approveStoryboard(
            $id,
            auth()->id(),
            (int) $request->input('revision'),
        );

        return response()->json([
            'ok' => $revision !== null,
            'reason' => $reason,
            'message' => match ($reason) {
                'ok' => __('messages.storyboard_approved', ['revision' => $revision]),
                'storyboard_already_approved' => __('messages.storyboard_already_approved', ['revision' => $revision]),
                'storyboard_not_latest' => __('messages.storyboard_not_latest'),
                'project_not_found' => __('messages.project_not_found'),
                'storyboard_findings_blocking', 'storyboard_findings_open', 'storyboard_source_changed' => __('messages.'.$reason),
                default => $this->sceneKeyframeMessage($reason),
            },
        ]);
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
            $id,
            $this->actorId(),
            $sceneId,
            $request->filled('provider_model') ? $request->only(['provider_model', 'size', 'quality']) : null,
        );

        return $this->keyframeJson($preview, $reason);
    }

    public function clips(string $id)
    {
        $project = $this->ownedProject($id);

        if ($this->usesShotClipFlow($id)) {
            return view('video-projects.show', $this->chrome() + ['id' => $id, 'project' => $project] + app(\App\Services\Video\MotoClipSelectionService::class)->screen($id));
        }

        $plan = $this->videoProjectService->selectedScenePlan($id);
        $revision = (int) $plan['revision'];
        $scenes = $plan['scenes'] ?? [];
        $clips = $this->videoProjectService->sceneClipCells($id, $revision);
        $keyframes = $this->videoProjectService->sceneKeyframeCells($id, $revision);
        $segments = $this->videoProjectService->filmSegments($id, $revision);

        if ($segments !== []) {
            $scenes = array_map(static fn(array $segment): array => [
                'scene_id' => (string) $segment['owner']->id,
                'title' => $segment['title'],
                'video_prompt' => $segment['prompt'],
                'duration_seconds' => $segment['duration_seconds'],
                'film_cut' => true,
                'film_keyframes' => $segment['keyframes'],
                'film_references' => array_map(static fn(array $reference): array => [
                    'role' => (string) $reference['role'],
                    'label' => (string) $reference['label'],
                    'url' => route('video-artifacts.show', (string) $reference['artifact_id']),
                ], $segment['references']),
                'film_views' => $segment['views'],
                'film_blocked' => $segment['blocked'],
            ], $segments);
        }

        return view('video-projects.show', $this->chrome() + [
            'id' => $id,
            'project' => $project,
            'scenes' => $scenes,
            'clips' => $clips,
            'keyframes' => $keyframes,
            'clipModels' => $segments === []
                ? $this->clipModels()
                : $this->clipModels(SceneClipDispatchService::FILM_TASK, (int) $segments[0]['duration_seconds']),
            'summary' => $this->clipSummary($scenes, $clips, $keyframes),
        ]);
    }

    public function finalCompositionPreview(string $id)
    {
        $project = $this->ownedProject($id);

        // Du an dung phim theo chinh sach trong DB dung chinh man nay: cung cac o cua man, them phan rieng cua ke hoach phim.
        if ($this->usesFilmPolicyFlow($id)) {
            return view('video-projects.final-composition-preview', $this->chrome() + ['id' => $id, 'project' => $project] + app(\App\Services\Video\MotoFilmRenderService::class)->screen($id));
        }

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

        if ($this->usesFilmPolicyFlow($id)) {
            return $this->requestPolicyFilm($request, $id);
        }

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

        // Phim dung theo chinh sach: gui mot ban sao vua bam lai, chi khi dung hash receipt da ghi.
        if ($this->usesFilmPolicyFlow($id)) {
            $final = app(\App\Services\Video\MotoFilmRenderService::class)->ownedFinal($id, $finalId);
            $approvals = app(\App\Services\Video\MotoFilmApprovalService::class);
            abort_if($approvals->receipt($final) === null, 404);
            $copy = $approvals->servable($final);
            abort_if($copy === null, 409, 'File final khác hash đã kiểm — không gửi.');

            return response()->file($copy['path'], ['Content-Type' => 'video/mp4', 'Accept-Ranges' => 'bytes'])->deleteFileAfterSend(true);
        }

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

    /** Trang thai mot ban final cua phim dung theo chinh sach, cho man dang cho job chay nen. Chi doc. */
    public function finalCompositionStatus(string $id, string $finalId)
    {
        $this->ownedProject($id);
        abort_unless($this->usesFilmPolicyFlow($id), 404);
        $films = app(\App\Services\Video\MotoFilmRenderService::class);

        return response()->json($films->describe($films->ownedFinal($id, $finalId), null));
    }

    /** Nguoi dung duyet mot ban final da dung va da kiem; quyet dinh gan voi hash ke hoach phim, ban final va hash file. */
    public function approveFinalComposition(Request $request, string $id, string $finalId)
    {
        $this->ownedProject($id);
        abort_unless($this->usesFilmPolicyFlow($id), 404);
        $data = $request->validate([
            'expected_plan_hash' => ['required', 'string', 'max:80'],
            'expected_sha256' => ['required', 'string', 'size:64'],
            'operation_id' => ['required', 'uuid'],
        ]);

        try {
            [, $reason] = app(\App\Services\Video\MotoFilmApprovalService::class)->approve($id, $finalId, (string) $data['expected_plan_hash'], (string) $data['expected_sha256'], (string) $data['operation_id'], (string) auth()->id());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $reason === 'replayed' ? 'Thao tác duyệt này đã được ghi trước đó.' : 'Đã duyệt bản final.');
    }

    /**
     * Du an cat clip theo shot: prompt, schema va quyet dinh duyet doc tu DB, theo workflow cua chu de trong
     * `video.clips.workflows`. Chu de nao co workflow o do thi chay luong nay tren chinh cac route clip; chua co thi chay luong cu.
     */
    private function usesShotClipFlow(string $id): bool
    {
        return app(\App\Services\Video\MotoClipSourceService::class)->supports($id);
    }

    /** Du an dung phim theo chinh sach trong DB (`video.films.workflows`), tren chinh cac route Final Composition. */
    private function usesFilmPolicyFlow(string $id): bool
    {
        return app(\App\Services\Video\MotoFilmSourceService::class)->supports($id);
    }

    /** Gui render mot clip theo shot: kiem hash trang dang mo, ma thao tac va thiet lap model; tra trang thai cho man. */
    private function renderShotClip(Request $request, string $id, string $shotId)
    {
        // Trinh duyet dong lai khong duoc lam dung mot yeu cau da toi provider.
        ignore_user_abort(true);
        @set_time_limit((int) config('video.omni.timeout') + 60);

        try {
            $data = $request->validate([
                'model_id' => ['required', 'string'],
                'resolution' => ['required', 'string'],
                'aspect_ratio' => ['required', 'string'],
                'duration_seconds' => ['required', 'integer', 'min:1'],
                'expected_intent_version' => ['required', 'integer', 'min:0'],
                'operation_id' => ['required', 'uuid'],
                'expected_clip_hash' => ['required', 'string'],
            ]);
            [$render, , $reason] = app(\App\Services\Video\MotoClipRenderService::class)->render($id, $shotId, [
                'model_id' => (string) $data['model_id'],
                'resolution' => (string) $data['resolution'],
                'aspect_ratio' => (string) $data['aspect_ratio'],
                'duration_seconds' => (int) $data['duration_seconds'],
            ], (int) $data['expected_intent_version'], (string) $data['operation_id'], (string) $data['expected_clip_hash']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->refusedShotClip(implode(' ', $e->validator->errors()->all()), $id, $shotId);
        } catch (\Throwable $e) {
            return $this->refusedShotClip(match ($e->getMessage()) {
                'shot_dispatch_conflict' => 'Clip đã thay đổi ở thao tác khác. Tải lại trang trước khi render.',
                'shot_dispatch_operation_conflict' => 'Mã thao tác render đã được dùng cho một yêu cầu khác.',
                'shot_dispatch_replay_missing' => 'Không còn tìm thấy lượt render của thao tác trước.',
                default => $e->getMessage(),
            }, $id, $shotId);
        }

        return response()->json(app(\App\Services\Video\MotoClipSelectionService::class)->state($id, $render, $reason));
    }

    /** Duyet ket qua cua mot shot cho buoc final; quyet dinh gan voi dung file do va phien ban y dinh cua shot. */
    private function approveShotClip(Request $request, string $id, string $shotId)
    {
        $data = $request->validate([
            'render_id' => ['required', 'uuid'],
            'expected_intent_version' => ['required', 'integer', 'min:0'],
            'operation_id' => ['required', 'uuid'],
        ]);

        try {
            [, $reason] = app(\App\Services\Video\MotoClipSelectionService::class)->approve($id, $shotId, (string) $data['render_id'], (int) $data['expected_intent_version'], (string) $data['operation_id'], (string) auth()->id());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $reason === 'replayed' ? 'Thao tác duyệt này đã được ghi trước đó.' : 'Đã duyệt clip của '.$shotId.'.');
    }

    private function refusedShotClip(string $reason, string $id, string $shotId)
    {
        \Illuminate\Support\Facades\Log::warning('Clip shot bị từ chối trước khi gọi provider', ['project_id' => $id, 'shot_id' => $shotId, 'reason' => $reason]);

        return response()->json(['state' => 'idle', 'reason' => $reason, 'note' => $reason, 'poll_url' => null, 'file_url' => null, 'meta' => '', 'idle_stage' => 'chờ — chưa gửi render'], 422);
    }

    /** Nhan mot luot dung phim vao hang doi: kiem lai hash trang dang mo va ma thao tac, khong dung trong request. */
    private function requestPolicyFilm(Request $request, string $id)
    {
        $data = $request->validate([
            'expected_hash' => ['required', 'string', 'max:80'],
            'operation_id' => ['required', 'uuid'],
        ]);

        try {
            [$final, $reason] = app(\App\Services\Video\MotoFilmRenderService::class)->request($id, (string) $data['expected_hash'], (string) $data['operation_id'], (string) auth()->id());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $reason === 'replayed'
            ? 'Thao tác dựng này đã được ghi trước đó (bản final #'.$final->revision.', '.$final->status.').'
            : 'Đã xếp hàng dựng bản final #'.$final->revision.' — job chạy nền, đóng tab không làm dừng nó; trang tự cập nhật khi có kết quả.');
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

            if (array_key_exists('film_blocked', $scene) ? $scene['film_blocked'] === null : ($keyframes[$key]['approved'] ?? null) !== null) {
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
    /**
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    private function renderFilmSegment(Request $request, string $id, string $sceneId)
    {
        try {
            $data = $request->validate([
                'model_id' => ['required', 'string'],
                'resolution' => ['required', 'string'],
                'aspect_ratio' => ['required', 'string'],
                'duration_seconds' => ['required', 'integer', 'min:1'],
                'expected_intent_version' => ['required', 'integer', 'min:0'],
                'operation_id' => ['required', 'uuid'],
            ]);
        } catch (ValidationException $e) {
            return $this->clipRefused($request, implode(' ', $e->validator->errors()->all()), $id, $sceneId);
        }

        [$render, $reason] = $this->videoProjectService->renderFilmSegment(
            $id,
            $this->actorId(),
            $sceneId,
            Arr::only($data, ['model_id', 'resolution', 'aspect_ratio', 'duration_seconds']),
            (int) $data['expected_intent_version'],
            (string) $data['operation_id'],
        );

        if ($render === null) {
            [$code, $detail] = array_pad(explode('|', $reason, 2), 2, null);

            return $this->clipRefused($request, match (true) {
                $detail === 'shot_dispatch_conflict' => 'Clip đã thay đổi ở thao tác khác. Tải lại trang trước khi render.',
                $code === 'film_segment_blocked' => (string) $detail,
                default => $this->sceneKeyframeMessage($reason),
            }, $id, $sceneId);
        }

        [$ok, $submitted] = $this->clipExecution->submit($render->id);

        if ($request->expectsJson()) {
            return response()->json($this->clipState($id, $render->refresh(), $submitted));
        }

        return back()->with($ok ? 'status' : 'error', 'Clip: ' . $submitted);
    }

    public function renderSceneClip(Request $request, string $id, string $sceneId)
    {
        $this->ownedProject($id);

        // Luong clip theo shot: `sceneId` la ma shot cua ke hoach shot da duyet.
        if ($this->usesShotClipFlow($id)) {
            return $this->renderShotClip($request, $id, $sceneId);
        }

        $row = \App\Models\VideoRenderScene::query()->whereKey($sceneId)->where('project_id', $id)->first();

        if (\App\Video\Scene\FilmCutAuthor::isFilmShape($row?->state_json['film_cut_shape'] ?? null)) {
            return $this->renderFilmSegment($request, $id, $sceneId);
        }

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

            $review = $this->videoProjectService->clipSourcesNeedReview($scene);

            if ($review !== null) {
                throw new RuntimeException($review.' — render và duyệt lại keyframe trước khi render clip.');
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

        // Luong clip theo shot: `shotId` la ma shot; chon clip la duyet ket qua do cho buoc final.
        if ($this->usesShotClipFlow($id)) {
            return $this->approveShotClip($request, $id, $shotId);
        }

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

        if ($this->usesShotClipFlow($id)) {
            $owned = app(\App\Services\Video\MotoClipSelectionService::class)->ownedRender($id, $render);
            $disk = app(\Illuminate\Contracts\Filesystem\Factory::class)->disk((string) config('video.veo.disk'));
            $path = (string) $owned->artifact_path;
            abort_if($path === '' || ! $disk->exists($path), 404);

            return $disk->response($path, basename($path), ['Content-Type' => 'video/mp4', 'Accept-Ranges' => 'bytes']);
        }

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

        if ($this->usesShotClipFlow($id)) {
            $selection = app(\App\Services\Video\MotoClipSelectionService::class);
            $owned = $selection->ownedRender($id, $render);
            [, $reason] = app(\App\Services\Video\MotoClipRenderService::class)->track((string) $owned->id);

            return response()->json($selection->state($id, $owned->refresh(), $reason));
        }

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
            isset($data['provider_model']) ? array_intersect_key($data, array_flip(['provider_model', 'size', 'quality'])) : null,
            isset($data['reference_choice_version']) ? (int) $data['reference_choice_version'] : null,
        );

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $this->keyframeOutcome($image, $reason) === 'success',
                'message' => $this->sceneKeyframeMessage($reason),
            ]);
        }

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

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => $this->keyframeOutcome($done, $reason) === 'success',
                'message' => $this->sceneKeyframeMessage($reason),
            ]);
        }

        return back()->with(
            $this->keyframeOutcome($done, $reason),
            $this->sceneKeyframeMessage($reason),
        );
    }

    public function saveSceneReferences(Request $request, string $id, string $sceneId)
    {
        $this->ownedProject($id);

        $data = $request->validate([
            'expected_version' => ['required', 'integer', 'min:0'],
            'items' => ['nullable', 'array', 'max:'.(VideoProjectService::SCENE_MAX_SOURCE_IMAGES - 1)],
            'items.*.artifact_id' => ['required', 'uuid'],
            'items.*.role' => ['required', 'string', 'max:40'],
        ]);

        [$version, $reason] = $this->videoProjectService->saveReferenceChoice(
            $id,
            $this->actorId(),
            $sceneId,
            (int) $data['expected_version'],
            $request->boolean('reset') ? null : array_values((array) ($data['items'] ?? [])),
        );

        return $version === null
            ? response()->json(['ok' => false, 'reason' => $reason, 'message' => $this->sceneKeyframeMessage($reason)], 422)
            : response()->json(['ok' => true, 'version' => $version]);
    }

    public function chooseSceneKeyframe(Request $request, string $id, string $sceneId)
    {
        $this->ownedProject($id);

        $data = $request->validate(['use' => ['required', 'boolean']]);

        [$done, $reason] = $this->videoProjectService->chooseFilmKeyframe(
            $id,
            $this->actorId(),
            $sceneId,
            (bool) $data['use'],
        );

        if ($request->expectsJson()) {
            return response()->json(['ok' => $done, 'message' => $this->sceneKeyframeMessage($reason)], $done ? 200 : 422);
        }

        return back()->with($done ? 'success' : 'error', $this->sceneKeyframeMessage($reason));
    }

    public function chooseFilmViews(Request $request, string $id, string $sceneId)
    {
        $this->ownedProject($id);

        $data = $request->validate([
            'auto' => ['nullable', 'boolean'],
            'views' => ['nullable', 'array', 'max:10'],
            'views.*' => ['required', 'uuid'],
        ]);

        [$done, $reason] = $this->videoProjectService->chooseFilmReferenceViews(
            $id,
            $this->actorId(),
            $sceneId,
            $request->boolean('auto') ? null : array_values((array) ($data['views'] ?? [])),
        );

        if ($request->expectsJson()) {
            return response()->json(['ok' => $done, 'message' => $this->sceneKeyframeMessage($reason)], $done ? 200 : 422);
        }

        return back()->with($done ? 'success' : 'error', $this->sceneKeyframeMessage($reason));
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

        if ($request->expectsJson()) {
            return response()->json(['ok' => $done, 'message' => $this->sceneKeyframeMessage($reason)]);
        }

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
    private function clipModels(string $task = SceneClipDispatchService::TASK, ?int $segmentSeconds = null): array
    {
        $registry = app(\App\Video\Media\VideoModelRegistry::class);

        if (! $registry->available($task)) {
            return [];
        }

        try {
            $entries = $registry->forTask($task);
        } catch (InvalidArgumentException $e) {
            Log::warning('Registry clip hong: ' . $e->getMessage());

            return [];
        }

        return array_map(static fn(array $entry): array => [
            'id' => (string) $entry['id'],
            'label' => (string) $entry['label'],
            'default' => $entry['default'] === true,
            'controls' => $segmentSeconds === null
                ? $entry['controls']
                : $entry['controls'] + ['durations' => [$segmentSeconds], 'default_duration' => $segmentSeconds],
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
