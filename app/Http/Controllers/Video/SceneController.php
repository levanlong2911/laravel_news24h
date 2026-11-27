<?php

namespace App\Http\Controllers\Video;

use App\Enums\SceneStep;
use App\Services\Video\SceneSourceService;
use App\Services\Video\SceneWriterService;
use App\Services\Video\ShotSourceService;
use App\Services\Video\ShotWriterService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class SceneController extends VideoController
{
    public function run(Request $request, string $id)
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

    public function reset(string $id)
    {
        $this->ownedProject($id);

        [$done, $reason] = app(SceneWriterService::class)->reset($id);

        return $done ? back()->with('success', $reason === 'pending' ? self::CANCELLED : 'Đã reset lượt chia scene bị kẹt — bản đã duyệt giữ nguyên.') : back()->with('error', $reason);
    }

    public function index(string $id, ?string $scene = null, ?string $step = null)
    {
        $this->ownedProject($id);
        if (($refused = $this->refusedBy(\App\Http\Middleware\RefuseV63Project::class, 'scenes')) !== null) { return $refused; }

        if (app(SceneSourceService::class)->supports($id)) {
            $shown = request()->query('stage');
            $shots = request()->query('shots');

            return view('video-projects.scene', $this->chrome() + [
                'id' => $id,
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
}
