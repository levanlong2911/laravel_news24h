<?php

namespace App\Http\Controllers\Video;

use App\Services\Video\DesignDnaService;
use App\Services\Video\ModelSheetService;
use App\Services\Video\ScriptWriterService;
use App\Services\Video\YachtDesignService;
use App\Video\Screenplay\VesselDesign;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class VideoProjectController extends VideoController
{
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

    public function show(Request $request, string $id)
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
}
