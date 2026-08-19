<?php

namespace App\Http\Controllers;

use App\Models\VideoProject;
use App\Services\VideoProjectService;

class VideoProjectsController extends Controller
{
    private VideoProjectService $videoProjectService;

    public function __construct(
        VideoProjectService $videoProjectService
    )
    {
        $this->videoProjectService = $videoProjectService;
    }

    /**
     Get data by $articleId
     */
    public function store(string $articleId)
    {
        $project = $this->videoProjectService->getdataByArticleId($articleId);
        if (!$project) {
            return back()->with('error', 'Khong tim thay bai viet.');
        }
        return view("video-projects.anchor", [
            "route" => "video-projects",
            "action" => "admin-video-projects",
            "menu" => "menu-open",
            "active" => "active",
            'project' => $project
        ]);
    }

    public function index()
    {
        return view('video-projects.index', $this->chrome() + [
            'projects' => $this->videoProjectService->listAll(),
        ]);
    }

    /**
     * Màn ASSET CREATION.
     *
     * GET KHÔNG gọi Claude — prompt để rỗng cho tới khi người bấm
     * "Generate Anchor". Đây đúng là lỗi đang có ở `video-session/{id}/anchor`:
     * mở trang là tiêu ~$0.021, và trình duyệt thì tự gọi lại GET khi F5,
     * bookmark hay prefetch.
     */
    public function anchor(string $id)
    {
        $project = VideoProject::findOrFail($id);

        return view('video-projects.anchor', $this->chrome() + [
            'id' => $project->id,
            'prompt' => null,
            'reason' => 'chua sinh',
        ]);
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
