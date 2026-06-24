<?php

namespace App\Http\Controllers;

use App\Models\VideoJob;
use App\Services\Admin\PublisherService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * L10.5 — Human Approval gate.
 *
 * Every video that reaches 'uploaded' status must be reviewed here before
 * the publisher fires.  Blocks demonetize risk: an admin sees thumbnail,
 * hook text, 3 key facts, CTA, and the actual video (30 sec review max).
 *
 * States: uploaded → pending_review → approved | rejected | regenerating
 */
class VideoApprovalController extends Controller
{
    public function __construct(private PublisherService $publisher) {}

    /** Queue view: all jobs pending_review, newest first. */
    public function index()
    {
        $jobs = VideoJob::with(['storyPlan.article.category'])
            ->pendingReview()
            ->latest()
            ->paginate(10);

        return view('admin.video-jobs.approval-queue', [
            'route'  => 'video-approval',
            'action' => 'video-approval-index',
            'menu'   => 'menu-open',
            'active' => 'active',
            'jobs'   => $jobs,
        ]);
    }

    /** Detail review page: video player + thumbnail + facts + CTA. */
    public function review(VideoJob $videoJob)
    {
        $videoJob->load(['storyPlan.article.category', 'reviewer']);
        $script = $videoJob->script_json ?? [];
        $plan   = $videoJob->storyPlan;

        // Pull 3 key facts from the script's fact_refs for the reviewer to check
        $keyFacts = collect($script['scenes'] ?? [])
            ->flatMap(fn ($s) => $s['fact_refs'] ?? [])
            ->unique()
            ->take(3)
            ->values();

        return view('admin.video-jobs.approval-review', [
            'route'    => 'video-approval',
            'action'   => 'video-approval-review',
            'menu'     => 'menu-open',
            'active'   => 'active',
            'job'      => $videoJob,
            'plan'     => $plan,
            'script'   => $script,
            'keyFacts' => $keyFacts,
            'videoUrl' => $videoJob->video_path
                ? Storage::disk('public')->url($videoJob->video_path)
                : null,
            'thumbUrl' => $videoJob->thumbnail_path
                ? Storage::disk('public')->url($videoJob->thumbnail_path)
                : null,
        ]);
    }

    /** Approve → trigger publisher. */
    public function approve(Request $request, VideoJob $videoJob): RedirectResponse
    {
        $videoJob->update([
            'approval_status' => 'approved',
            'reviewed_by'     => Auth::id(),
            'reviewed_at'     => now(),
        ]);

        // Fire publisher in background so HTTP worker returns immediately
        try {
            $this->publisher->publish($videoJob);
        } catch (\Throwable $e) {
            return back()->with('error', 'Approved but publisher failed: ' . $e->getMessage());
        }

        return redirect()->route('video-approval.index')
            ->with('success', "Đã approve và đăng Part {$videoJob->part_number} lên platform.");
    }

    /** Reject — mark rejected with a note; does NOT re-run the pipeline. */
    public function reject(Request $request, VideoJob $videoJob): RedirectResponse
    {
        $request->validate(['note' => 'nullable|string|max:500']);

        $videoJob->update([
            'approval_status' => 'rejected',
            'reviewed_by'     => Auth::id(),
            'reviewed_at'     => now(),
            'rejection_note'  => $request->input('note'),
        ]);

        return redirect()->route('video-approval.index')
            ->with('success', "Đã reject Part {$videoJob->part_number}.");
    }

    /** Regenerate — resets to script_ready so Python re-renders. */
    public function regenerate(VideoJob $videoJob): RedirectResponse
    {
        $videoJob->update([
            'status'          => 'script_ready',
            'approval_status' => 'regenerating',
            'reviewed_by'     => Auth::id(),
            'reviewed_at'     => now(),
            'video_path'      => null,
            'thumbnail_path'  => null,
            'cost_total'      => 0,
            'error_message'   => null,
            'claimed_by'      => null,
            'claimed_at'      => null,
        ]);

        return redirect()->route('video-approval.index')
            ->with('success', "Đã đưa Part {$videoJob->part_number} về hàng đợi để render lại.");
    }
}
