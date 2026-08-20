<?php

namespace App\Http\Controllers;

use App\Services\VideoProjectService;

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
        return view('video-projects.index', $this->chrome() + [
            'projects' => $this->videoProjectService->listAll(),
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
        $nextImageCode = $this->videoProjectService->nextImageCode($project->id, (string) auth()->user()?->name);

        return view('video-projects.anchor', [
            'route' => 'video-projects.anchor',
            'action' => 'video-projects.anchor',
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
