<?php

namespace App\Http\Controllers;

use App\Models\Article;
use App\Models\StoryPlan;
use App\Models\VideoJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Process;

class VideoJobController extends Controller
{
    public function index()
    {
        $plans = StoryPlan::with(['article:id,title,viral_score', 'videoJobs'])
            ->latest()
            ->paginate(20);

        // Candidates for the manual "Tạo Video AI" dropdown -- recent published
        // articles that haven't entered the video pipeline yet (includes ones
        // currently skipped/failed, since picking one here is a deliberate
        // manual retry override; see VideoPipelineRunner::forceRetry()).
        $candidates = Article::published()
            ->whereDoesntHave('storyPlan')
            ->latest('published_at')
            ->limit(50)
            ->get(['id', 'title']);

        return view('admin.video-jobs.index', [
            'route' => 'video-job',
            'action' => 'video-job-index',
            'menu' => 'menu-open',
            'active' => 'active',
            'plans' => $plans,
            'candidates' => $candidates,
        ]);
    }

    /**
     * Manual trigger ("Tạo Video AI" button) -- runs the same
     * Fact Extractor -> Story Planner -> Script Generator chain as the
     * 15-minute cron, but for one article, on demand.
     *
     * Runs as a DETACHED background OS process (php artisan video:process-articles
     * --article=...), not inline in this request: Process::start() returns
     * immediately without waiting, so this HTTP request -- and the PHP/lsphp
     * worker handling it -- is freed in well under a second instead of being
     * held for the 1-3 minutes a full Fact/Story/Script Claude chain takes.
     * If 5 employees click this (or the article-writing pipeline) for
     * different articles at the same moment, each becomes its own independent
     * `php artisan` process running in parallel -- they don't queue behind
     * each other waiting for a web worker; only ClaudeWriterService's own
     * RPM/concurrency throttle paces the actual Claude calls underneath.
     *
     * Result is NOT known when this returns -- check back on this page (or
     * storage/logs/laravel.log, tagged [VideoPipelineRunner]) in a few minutes.
     */
    public function generate(Article $article): RedirectResponse
    {
        Process::path(base_path())->start([
            PHP_BINARY, 'artisan', 'video:process-articles', "--article={$article->id}",
        ]);

        return back()->with('success', "Đã bắt đầu tạo video cho \"{$article->title}\" ở chế độ nền. Quay lại trang này sau 1-3 phút để xem kết quả.");
    }

    /**
     * Polled by the global JS in layouts/base.blade.php (every ~7s, for
     * articles the current browser triggered generate() for) so the toast
     * "hoàn thành"/"lỗi" notification works no matter which admin page the
     * user navigated to after clicking the button.
     */
    public function status(Article $article)
    {
        $article->refresh();

        if ($article->video_skipped_at) {
            return response()->json(['status' => 'skipped', 'message' => $article->video_skip_reason]);
        }

        $plan = StoryPlan::with('videoJobs')->where('article_id', $article->id)->first();
        if ($plan && $plan->videoJobs->count() >= $plan->total_parts) {
            return response()->json(['status' => 'ok', 'message' => "{$plan->videoJobs->count()}/{$plan->total_parts} part(s) scripted"]);
        }

        if ($article->video_failure_count > 0) {
            return response()->json(['status' => 'failed', 'message' => "Đang thử lại (lần {$article->video_failure_count}) -- xem chi tiết trong log"]);
        }

        return response()->json(['status' => 'pending']);
    }

    public function show(Article $article)
    {
        $plan = StoryPlan::with('videoJobs')
            ->where('article_id', $article->id)
            ->firstOrFail();

        return view('admin.video-jobs.show', [
            'route' => 'video-job',
            'action' => 'video-job-show',
            'menu' => 'menu-open',
            'active' => 'active',
            'article' => $article,
            'plan' => $plan,
        ]);
    }

    /**
     * Manual override: reset a stuck/failed part back to script_ready so
     * Python's pipeline can claim and render it again. Only meaningful for
     * parts that already failed somewhere downstream -- a part still
     * mid-render (status=claimed/rendering) should be left alone in case a
     * worker is genuinely still processing it.
     */
    public function rerender(VideoJob $videoJob): RedirectResponse
    {
        if (!in_array($videoJob->status, ['quality_check_failed', 'upload_failed'], true)) {
            return back()->with('error', 'Chỉ có thể re-render phần đã failed (quality check hoặc upload).');
        }

        $videoJob->update([
            'status' => 'script_ready',
            'claimed_by' => null,
            'claimed_at' => null,
            'error_message' => null,
            // Reset, not kept -- this is a fresh render attempt, and cost_total is
            // meant to reflect "what the current asset actually cost", not an
            // accumulation across failed/retried attempts.
            'cost_total' => 0,
        ]);

        return back()->with('success', "Part {$videoJob->part_number} đã được reset, chờ Python claim lại.");
    }
}
