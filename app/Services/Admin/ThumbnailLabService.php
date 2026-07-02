<?php

namespace App\Services\Admin;

use App\Models\VideoJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * L9 — Thumbnail Lab.
 *
 * After a video is uploaded, generates 5 thumbnail variants via Flux,
 * asks Claude to write 5 hook-style captions, scores each on predicted CTR,
 * and stores the top-1 thumbnail path back on the VideoJob.
 *
 * Runs automatically when a job transitions to 'uploaded' (triggered from
 * VideoJobObserver). Admin can also trigger manually from the approval UI.
 */
class ThumbnailLabService
{
    private const NUM_VARIANTS = 5;

    public function __construct(private ClaudeWriterService $claude) {}

    public function generate(VideoJob $job): void
    {
        $script = $job->script_json ?? [];
        $plan   = $job->storyPlan;

        if (!$plan || empty($script['scenes'])) {
            return;
        }

        // Find the most visually striking scene for the thumbnail base image
        $scenes = collect($script['scenes']);
        $heroScene = $scenes->firstWhere('beat', 'hook')
            ?? $scenes->firstWhere('beat', 'dramatic')
            ?? $scenes->first();

        if (!$heroScene) {
            return;
        }

        $basePrompt  = $heroScene['image_prompt'];
        $artStyle    = $plan->article->category?->context?->art_style ?? '';
        $hook        = $script['hook'] ?? $plan->hook;

        $candidates = $this->generateVariants($basePrompt, $artStyle, $job->id);
        if (empty($candidates)) {
            return;
        }

        $scored = $this->scoreCtr($candidates, $hook, $plan->narrative_arc);
        $winner = $scored[0] ?? null;

        if ($winner) {
            $job->update(['thumbnail_path' => $winner['path']]);
            Log::info('[ThumbnailLab] Winner thumbnail', [
                'job_id' => $job->id,
                'score'  => $winner['ctr_score'],
                'hook'   => $winner['hook'],
            ]);
        }
    }

    private function generateVariants(string $basePrompt, string $artStyle, string $jobId): array
    {
        $falKey = config('services.fal.key');
        if (!$falKey) {
            Log::warning('[ThumbnailLab] FAL_KEY not configured');
            return [];
        }

        $variants = [];
        $seeds    = range(100, 100 + self::NUM_VARIANTS - 1);

        foreach ($seeds as $i => $seed) {
            try {
                $response = Http::withHeaders(['Authorization' => "Key {$falKey}"])
                    ->timeout(60)
                    ->post('https://fal.run/fal-ai/flux-pro', [
                        'prompt'               => "{$basePrompt}, thumbnail composition, eye-catching, {$artStyle}",
                        'image_size'           => 'landscape_16_9',
                        'num_inference_steps'  => 28,
                        'guidance_scale'       => 3.5,
                        'seed'                 => $seed,
                    ]);

                if ($response->successful()) {
                    $url  = $response->json('images.0.url');
                    $path = $this->downloadThumbnail($url, $jobId, $i);
                    if ($path) {
                        $variants[] = ['path' => $path, 'seed' => $seed, 'index' => $i];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("[ThumbnailLab] Variant {$i} failed: {$e->getMessage()}");
            }
        }

        return $variants;
    }

    private function scoreCtr(array $variants, string $hook, string $narrativeArc): array
    {
        $count = count($variants);
        $last  = $count - 1;

        // Ask Claude to write 5 hooks AND score each variant's predicted CTR
        $prompt = <<<TXT
You are a YouTube Shorts CTR expert. Given this video's hook and topic, rate each of
{$count} thumbnail variants (indexed 0-{$last}) for predicted CTR on a 1-10 scale.
Also write one punchy 8-word hook caption for each.

Hook: {$hook}
Topic: {$narrativeArc}
Number of variants: {$count}

Respond with ONLY this JSON:
{"variants": [{"index": 0, "ctr_score": 8, "hook": "..."}, ...]}
TXT;

        try {
            $response = $this->claude->generate($prompt, 'haiku');
            $json     = json_decode($response->text, true);
            $scored   = $json['variants'] ?? [];

            // Merge scores back into variants
            foreach ($variants as &$v) {
                $match = collect($scored)->firstWhere('index', $v['index']);
                $v['ctr_score'] = $match['ctr_score'] ?? 5;
                $v['hook']      = $match['hook'] ?? '';
            }
            unset($v);

            usort($variants, fn ($a, $b) => $b['ctr_score'] <=> $a['ctr_score']);
        } catch (\Throwable $e) {
            Log::warning("[ThumbnailLab] CTR scoring failed: {$e->getMessage()}");
        }

        return $variants;
    }

    private function downloadThumbnail(string $url, string $jobId, int $index): ?string
    {
        try {
            $content = file_get_contents($url);
            $path    = "videos/{$jobId}/thumb_{$index}.jpg";
            \Illuminate\Support\Facades\Storage::disk('public')->put($path, $content);
            return $path;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
