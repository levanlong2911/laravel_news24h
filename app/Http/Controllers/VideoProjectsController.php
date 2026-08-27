<?php

namespace App\Http\Controllers;

use App\Enums\AnchorStage;
use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\ImageVariations;
use App\Form\AdminCustomValidator;
use App\Services\VideoProjectService;
use App\Video\Concept\Viewpoint;
use Illuminate\Http\Request;

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
        [$project, $reason] = $this->videoProjectService->getdataByArticleId($articleId);

        if ($project === null) {
            return redirect()->route('article.index')->with('error', $reason);
        }

        return redirect()
            ->route('video-projects.anchor', $project->id)
            ->with('success', __('messages.add_success'));
    }

    public function index()
    {
        $projects = $this->videoProjectService->listAll();

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

        if ($project === null) {
            return redirect()->route('video-projects.index')
                ->with('error', __('messages.project_not_found'));
        }
        $brief = $this->videoProjectService->latestInspiration($project->id);
        $concept = $this->videoProjectService->latestConcept($project->id);
        $promptPreview = $this->videoProjectService->anchorPromptPreview($project->id);
        $selectedModel = ImageModel::tryFrom((string) old('model', ''));
        $selectedQuality = ImageQuality::tryFrom((string) old('quality', ''));
        $selectedStage = AnchorStage::tryFrom((string) old('stage', $promptPreview['stage'] ?? ''));
        $selectedViewpoint = Viewpoint::tryFrom((string) old('viewpoint', $promptPreview['viewpoint'] ?? ''));
        $selectedSize = ImageSize::tryFrom((string) old('size', $promptPreview['size'] ?? ''));
        $selectedVariations = ImageVariations::tryFrom((int) old('variations', 0));

        // Man hinh chi duoc bay prompt DA LUU. Bien dich tai day roi khong luu
        // se cho ra mot prompt khong co hash trong DB: nguoi dung thay `VALID`,
        // bam Generate thi bi tu choi `stale`.
        $compiledPrompt = null;
        $compiledPromptHash = null;
        $compileReason = 'choose_prompt_settings';

        if ($promptPreview !== null
            && $selectedStage?->value === $promptPreview['stage']
            && $selectedViewpoint?->value === $promptPreview['viewpoint']
            && $selectedSize?->value === $promptPreview['size']) {
            $compiledPrompt = $promptPreview['prompt'];
            $compiledPromptHash = $promptPreview['prompt_sha256'];
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
            'selectedViewpoint' => $selectedViewpoint,
            'viewpointLabels' => [
                Viewpoint::FrontThreeQuarter->value => 'Front three-quarter',
                Viewpoint::Side->value => 'Side profile',
                Viewpoint::RearThreeQuarter->value => 'Rear three-quarter',
            ],
            'selectedSize' => $selectedSize,
            'selectedVariations' => $selectedVariations,
            'anchorCells' => $this->videoProjectService->anchorCells($project->id),
            'prompt' => null,
            'reason' => 'chua sinh',
        ]);
    }

    public function inspiration(string $id)
    {
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
        [$done, $reason] = $this->videoProjectService->resetInspiration($id);

        return $done
            ? back()->with('success', 'Đã reset — bấm Gọi Haiku để chạy lại.')
            : back()->with('error', $reason);
    }

    public function concept(string $id)
    {
        [$prompt, $reason] = $this->videoProjectService->runConcept($id);

        if ($prompt === null) {
            return back()->with('error', $reason);
        }

        return back()->with('success', $reason === 'cached'
            ? 'Brief không đổi — dùng lại concept cũ, không gọi Claude.'
            : 'Đã dựng concept.');
    }

    /**
     * Nut RIENG chu khong phai mot co trong body: mot duong dan tieu tien phai
     * nhin thay duoc tu bang route, khong an trong tham so.
     */
    public function rerunConcept(string $id)
    {
        [$prompt, $reason] = $this->videoProjectService->runConcept($id, true);

        if ($prompt === null) {
            return back()->with('error', $reason);
        }

        return back()->with('success', __('messages.concept_rebuilt'));
    }

    public function resetConcept(string $id)
    {
        [$done, $reason] = $this->videoProjectService->resetConcept($id);

        return $done
            ? back()->with('success', 'Đã reset — bấm Dựng Concept để chạy lại.')
            : back()->with('error', $reason);
    }

    public function compileAnchorPrompt(Request $request, string $id)
    {
        $data = $this->form->validate($request, 'AnchorPromptForm');

        $stage = AnchorStage::from($data['stage']);
        $viewpoint = Viewpoint::from($data['viewpoint']);
        $size = ImageSize::from($data['size']);

        [$prompt, $reason, $concept] = $this->videoProjectService->compiledAnchorPrompt(
            $id, $stage, $viewpoint, $size,
        );

        if ($prompt === null || $concept === null) {
            return back()->withInput()->with('error', $this->anchorMessage($reason));
        }

        $this->videoProjectService->storeAnchorPromptPreview($id, $stage, $viewpoint, $size, $prompt, $concept);

        return back();
    }

    public function createAnchorImage(Request $request, string $id)
    {
        $data = $this->form->validate($request, 'AnchorImageForm');

        [$image, $reason] = $this->videoProjectService->renderAnchorFromPreview(
            $id,
            (string) auth()->user()?->name,
            (string) $data['prompt_sha256'],
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
        [$image, $reason] = $this->videoProjectService->renderDesignImage($id, $imageId);

        if ($image === null) {
            return back()->with('error', $this->anchorMessage($reason));
        }

        return back()->with(
            in_array($reason, ['rendered', 'queued'], true) ? 'success' : 'error',
            $this->renderOutcome($reason, $image),
        );
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
            default => $reason,
        };
    }

    public function reference(string $id)
    {
        return view('video-projects.reference', $this->chrome() + ['id' => $id]);
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
