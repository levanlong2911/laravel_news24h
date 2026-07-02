<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\VideoJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Sanctum-protected API the Python pipeline (local machine) calls: pull +
 * claim + get + status + asset upload. Token must carry the 'video-jobs'
 * ability only -- see routes/api.php for the middleware wiring, and the
 * class docblock below for how to mint that token.
 *
 * Mint a scoped token (run once, via `php artisan tinker`):
 *   $admin = \App\Models\Admin::first(); // or whichever admin owns this integration
 *   $admin->createToken('python-video-pipeline', ['video-jobs'])->plainTextToken
 * Put the printed token into the Python project's .env as LARAVEL_API_TOKEN.
 */
class VideoJobApiController extends Controller
{
    private function authorizeAbility(Request $request): void
    {
        abort_unless($request->user()?->tokenCan('video-jobs'), 403, 'Token missing video-jobs ability');
    }

    /** GET /api/video-jobs?status=script_ready&limit=20 */
    public function index(Request $request)
    {
        $this->authorizeAbility($request);

        $status = $request->query('status', 'script_ready');
        $limit = min(100, (int) $request->query('limit', 20));

        $jobs = VideoJob::where('status', $status)
            ->orderBy('created_at')
            ->limit($limit)
            ->get(['id', 'status', 'part_number', 'created_at', 'updated_at', 'youtube_video_id']);

        return response()->json(['data' => $jobs]);
    }

    /**
     * POST /api/video-jobs/{id}/claim
     * Failure mode #1: atomic claim via lockForUpdate() inside a transaction --
     * two near-simultaneous claims on the same job must not both succeed.
     */
    public function claim(Request $request, string $id)
    {
        $this->authorizeAbility($request);

        $claimed = DB::transaction(function () use ($id, $request) {
            $job = VideoJob::where('id', $id)->lockForUpdate()->first();

            if (!$job || $job->status !== 'script_ready') {
                return null;
            }

            $job->update([
                'status' => 'claimed',
                'claimed_by' => $request->input('worker_id', $request->ip()),
                'claimed_at' => now(),
            ]);

            return $job;
        });

        if ($claimed === null) {
            return response()->json(['message' => 'Job already claimed or not claimable'], 409);
        }

        return response()->json(['data' => $claimed]);
    }

    /** GET /api/video-jobs/{id} -- full payload Python caches as script.json */
    public function show(Request $request, string $id)
    {
        $this->authorizeAbility($request);

        $job = VideoJob::with('storyPlan.article')->findOrFail($id);
        $script  = $job->script_json;
        $plan    = $job->storyPlan;
        $article = $plan->article;

        return response()->json(['data' => [
            'job_id'       => $job->id,
            'article_id'   => $plan->article_id,
            'topic'        => $plan->narrative_arc,
            'mood'         => $plan->mood,
            'content_type' => $plan->content_type ?? 'informational',
            'part_number'  => $job->part_number,
            'total_parts'  => $plan->total_parts,
            'is_final_part' => (int) $job->part_number === (int) $plan->total_parts,
            'hook'          => $script['hook'] ?? $plan->hook,
            'cta'           => $script['cta'] ?? null,
            'target_seconds' => $script['target_seconds'] ?? 15,
            'scenes'        => $script['scenes'] ?? [],
            // L10: viral signals so Python's viral_analyzer tunes prompt intensity
            'viral_score'   => (int) ($article->viral_score ?? 0),
            'hook_score'    => (float) ($article->hook_score ?? 0.0),
        ]]);
    }

    /** POST /api/video-jobs/{id}/status -- {status, cost_total?, error_message?} */
    public function updateStatus(Request $request, string $id)
    {
        $this->authorizeAbility($request);

        $data = $request->validate([
            'status' => 'required|in:rendering,quality_check_passed,quality_check_failed,uploaded,upload_failed',
            'cost_total' => 'nullable|numeric',
            'error_message' => 'nullable|string',
        ]);

        $job = VideoJob::findOrFail($id);
        $job->update(array_filter($data, fn ($v) => $v !== null));

        return response()->json(['data' => $job]);
    }

    /**
     * POST /api/video-jobs/{id}/assets -- multipart: video, thumbnail, metadata (JSON string).
     * Saves files then immediately advances status to 'uploaded' in one save() call so the
     * VideoJobObserver fires only after video_path is guaranteed to exist (not before).
     * Python should call this endpoint INSTEAD of calling updateStatus('uploaded') separately.
     */
    public function storeAssets(Request $request, string $id)
    {
        $this->authorizeAbility($request);

        $request->validate([
            'video' => 'required|file|mimes:mp4',
            'thumbnail' => 'nullable|file|image',
        ]);

        $job = VideoJob::findOrFail($id);
        $metadata = json_decode($request->input('metadata', '{}'), true) ?: [];

        $videoPath = $request->file('video')->store("videos/{$job->id}", 'public');
        $job->video_path = $videoPath;
        $job->status = 'uploaded';

        if ($request->hasFile('thumbnail')) {
            $job->thumbnail_path = $request->file('thumbnail')->store("videos/{$job->id}", 'public');
        }

        if (!empty($metadata['youtube_video_id'])) {
            $job->youtube_video_id = $metadata['youtube_video_id'];
        }
        if (!empty($metadata['facebook_post_id'])) {
            $job->facebook_post_id = $metadata['facebook_post_id'];
        }
        if (!empty($metadata['tiktok_post_id'])) {
            $job->tiktok_post_id = $metadata['tiktok_post_id'];
        }
        if (!empty($metadata['instagram_post_id'])) {
            $job->instagram_post_id = $metadata['instagram_post_id'];
        }
        if (isset($metadata['cost_total'])) {
            $job->cost_total = $metadata['cost_total'];
        }
        $job->save();

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk('public');
        return response()->json(['data' => [
            'video_url'     => $disk->url($job->video_path),
            'thumbnail_url' => $job->thumbnail_path ? $disk->url($job->thumbnail_path) : null,
        ]]);
    }
}
