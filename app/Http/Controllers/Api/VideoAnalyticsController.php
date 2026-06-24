<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VideoAnalytic;
use App\Models\VideoJob;
use App\Models\Article;
use Illuminate\Http\Request;

/**
 * L12 — Analytics Feedback Loop ingestion endpoint.
 *
 * Called by n8n/Make on a daily schedule after pulling stats from
 * YouTube/Facebook/TikTok APIs. Stores CTR/retention/watch-time per job
 * per platform per day.
 *
 * The feedback loop: high CTR/retention articles inform future StoryPlan
 * beats and fact selection (query via /api/video-analytics/top-articles).
 */
class VideoAnalyticsController extends Controller
{
    private function authorizeAbility(Request $request): void
    {
        abort_unless($request->user()?->tokenCan('video-jobs'), 403);
    }

    /**
     * POST /api/video-analytics
     * Accepts a batch of platform stats (one array entry per job per day).
     */
    public function store(Request $request)
    {
        $this->authorizeAbility($request);

        $validated = $request->validate([
            'records'                      => 'required|array|min:1|max:100',
            'records.*.video_job_id'       => 'required|uuid|exists:video_jobs,id',
            'records.*.platform'           => 'required|in:youtube,facebook,tiktok,instagram',
            'records.*.date'               => 'required|date',
            'records.*.views'              => 'nullable|integer|min:0',
            'records.*.watch_time_seconds' => 'nullable|integer|min:0',
            'records.*.avg_view_duration'  => 'nullable|numeric|min:0',
            'records.*.retention_rate'     => 'nullable|numeric|min:0|max:1',
            'records.*.ctr'                => 'nullable|numeric|min:0|max:1',
            'records.*.likes'              => 'nullable|integer|min:0',
            'records.*.comments'           => 'nullable|integer|min:0',
            'records.*.shares'             => 'nullable|integer|min:0',
            'records.*.saves'              => 'nullable|integer|min:0',
            'records.*.raw_payload'        => 'nullable|array',
        ]);

        $upserted = 0;
        foreach ($validated['records'] as $rec) {
            VideoAnalytic::updateOrCreate(
                [
                    'video_job_id' => $rec['video_job_id'],
                    'platform'     => $rec['platform'],
                    'date'         => $rec['date'],
                ],
                array_filter($rec, fn ($v) => $v !== null)
            );
            $upserted++;
        }

        return response()->json(['upserted' => $upserted]);
    }

    /**
     * GET /api/video-analytics/top-articles?days=30&platform=youtube&limit=20
     * Returns articles ranked by avg CTR — used by StoryPlannerService to
     * bias fact selection toward proven engagement patterns.
     */
    public function topArticles(Request $request)
    {
        $this->authorizeAbility($request);

        $days     = min(90, (int) $request->query('days', 30));
        $platform = $request->query('platform', 'youtube');
        $limit    = min(50, (int) $request->query('limit', 20));

        $rows = VideoAnalytic::selectRaw(
                'video_jobs.story_plan_id,
                 story_plans.article_id,
                 AVG(video_analytics.ctr) as avg_ctr,
                 AVG(video_analytics.retention_rate) as avg_retention,
                 SUM(video_analytics.views) as total_views'
            )
            ->join('video_jobs', 'video_jobs.id', '=', 'video_analytics.video_job_id')
            ->join('story_plans', 'story_plans.id', '=', 'video_jobs.story_plan_id')
            ->where('video_analytics.platform', $platform)
            ->where('video_analytics.date', '>=', now()->subDays($days))
            ->groupBy('video_jobs.story_plan_id', 'story_plans.article_id')
            ->orderByDesc('avg_ctr')
            ->limit($limit)
            ->get();

        return response()->json(['data' => $rows]);
    }
}
