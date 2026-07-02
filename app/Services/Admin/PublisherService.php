<?php

namespace App\Services\Admin;

use App\Models\VideoJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * L11 — Multi-Platform Publisher.
 *
 * Triggered after L10.5 Human Approval. Sends the approved video to
 * YouTube Shorts and Facebook Reels via n8n/Make webhooks.
 * TikTok and Instagram are registered for future wiring.
 *
 * n8n/Make receives the webhook and handles the actual platform API calls
 * (OAuth tokens, upload chunking) -- we only pass the asset URLs + metadata.
 */
class PublisherService
{
    private array $webhooks;

    public function __construct()
    {
        $this->webhooks = [
            'youtube'   => config('services.publisher.youtube_webhook'),
            'facebook'  => config('services.publisher.facebook_webhook'),
            'tiktok'    => config('services.publisher.tiktok_webhook'),
            'instagram' => config('services.publisher.instagram_webhook'),
        ];
    }

    public function publish(VideoJob $job): void
    {
        $plan    = $job->storyPlan()->with('article.category')->first();
        $script  = $job->script_json ?? [];
        $article = $plan?->article;

        if (!$job->video_path) {
            throw new \RuntimeException("Job {$job->id} has no video_path to publish.");
        }

        $videoUrl     = Storage::disk('public')->url($job->video_path);
        $thumbnailUrl = $job->thumbnail_path
            ? Storage::disk('public')->url($job->thumbnail_path)
            : null;

        $title       = $article?->title ?? $plan?->hook ?? 'Video';
        $description = $plan?->narrative_arc ?? '';
        $hook        = $script['hook'] ?? '';
        $category    = $article?->category?->name ?? '';
        $tags        = array_filter([$category, 'Shorts', 'AI']);

        $payload = [
            'job_id'        => $job->id,
            'article_id'    => $plan?->article_id,
            'part_number'   => $job->part_number,
            'total_parts'   => $plan?->total_parts,
            'video_url'     => $videoUrl,
            'thumbnail_url' => $thumbnailUrl,
            'title'         => mb_substr($hook ?: $title, 0, 100),
            'description'   => mb_substr($description, 0, 500),
            'tags'          => $tags,
            'approved_at'   => now()->toIso8601String(),
        ];

        $platforms = array_keys(array_filter($this->webhooks));
        foreach ($platforms as $platform) {
            $this->fireWebhook($platform, $payload, $job);
        }
    }

    private function fireWebhook(string $platform, array $payload, VideoJob $job): void
    {
        $url = $this->webhooks[$platform];
        if (!$url) {
            return;
        }

        try {
            $response = Http::timeout(30)->post($url, $payload);

            if ($response->successful()) {
                $result = $response->json();
                // n8n/Make echoes back the platform's post/video ID.
                // YouTube uses youtube_video_id; all others use {platform}_post_id.
                $idValue = $result['video_id'] ?? $result['post_id'] ?? null;
                if (!empty($idValue)) {
                    $idField = $platform === 'youtube' ? 'youtube_video_id' : "{$platform}_post_id";
                    $job->update([$idField => $idValue]);
                }
                Log::info("[Publisher] {$platform} OK", ['job_id' => $job->id]);
            } else {
                Log::warning("[Publisher] {$platform} non-200", [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error("[Publisher] {$platform} webhook failed: {$e->getMessage()}");
        }
    }
}
