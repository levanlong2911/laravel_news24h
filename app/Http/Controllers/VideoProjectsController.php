<?php

namespace App\Http\Controllers;

use App\Enums\ImageModel;
use App\Enums\ImageQuality;
use App\Enums\ImageResolution;
use App\Enums\ImageVariations;
use App\Services\VideoProjectService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VideoProjectsController extends Controller
{
    private VideoProjectService $videoProjectService;

    public function __construct(VideoProjectService $videoProjectService)
    {
        $this->videoProjectService = $videoProjectService;
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
        [$compiledPrompt, $compileReason] = $this->videoProjectService->compiledAnchorPrompt($project->id);
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
            'compileReason' => $compileReason,
            'nextImageCode' => $nextImageCode,
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

    public function resetConcept(string $id)
    {
        [$done, $reason] = $this->videoProjectService->resetConcept($id);

        return $done
            ? back()->with('success', 'Đã reset — bấm Dựng Concept để chạy lại.')
            : back()->with('error', $reason);
    }

    public function createAnchorImage(Request $request, string $id)
    {
        $data = $request->validate([
            'model' => ['required', Rule::enum(ImageModel::class)],
            'quality' => ['required', Rule::enum(ImageQuality::class)],
            'resolution' => ['required', Rule::enum(ImageResolution::class)],
            'variations' => ['required', 'integer', Rule::in(ImageVariations::values())],
        ], [
            'model.required' => __('messages.anchor_setting_required', ['field' => 'Model']),
            'model.*' => __('messages.anchor_setting_invalid', ['field' => 'Model']),
            'quality.required' => __('messages.anchor_setting_required', ['field' => 'Quality']),
            'quality.*' => __('messages.anchor_setting_invalid', ['field' => 'Quality']),
            'resolution.required' => __('messages.anchor_setting_required', ['field' => 'Resolution']),
            'resolution.*' => __('messages.anchor_setting_invalid', ['field' => 'Resolution']),
            'variations.required' => __('messages.anchor_setting_required', ['field' => 'Variations']),
            'variations.integer' => __('messages.anchor_setting_invalid', ['field' => 'Variations']),
            'variations.in' => __('messages.anchor_setting_invalid', ['field' => 'Variations']),
        ]);

        [$image, $reason] = $this->videoProjectService->createAnchorImage(
            $id,
            (string) auth()->user()?->name,
            ImageModel::from($data['model']),
            ImageQuality::from($data['quality']),
            ImageResolution::from($data['resolution']),
            ImageVariations::from((int) $data['variations']),
        );

        if ($image === null) {
            return back()->with('error', $this->anchorMessage($reason));
        }

        return back()->with('success', match ($reason) {
            'created' => __('messages.anchor_image_created', ['code' => $image->image_code]),
            'already_exists' => __('messages.anchor_image_exists', ['code' => $image->image_code]),
            default => $reason,
        });
    }

    private function anchorMessage(string $reason): string
    {
        return match ($reason) {
            'project_not_found' => __('messages.project_not_found'),
            'no_category' => __('messages.anchor_no_category'),
            'no_concept' => __('messages.anchor_no_concept'),
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
