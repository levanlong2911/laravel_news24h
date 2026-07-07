<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;


/**
 * Smoke-test video rendering via fal.ai (Kling model).
 * Calls fal.ai queue API directly — no queue infrastructure needed.
 *
 * Usage:
 *   php artisan ai:render-test
 *   php artisan ai:render-test --prompt="luxury villa at golden hour" --duration=5
 *   php artisan ai:render-test --model=fal-ai/kling-video/v2.1/standard/text-to-video
 *   php artisan ai:render-test --no-download
 */
class RenderTestCommand extends Command
{
    protected $signature = 'ai:render-test
                            {--prompt=A cinematic aerial shot of a luxury villa pool by the sea at golden hour, warm light, shallow depth of field : Video prompt}
                            {--duration=5 : Duration in seconds (5 or 10)}
                            {--ratio=16:9 : Aspect ratio (16:9 | 9:16 | 1:1)}
                            {--model=fal-ai/kling-video/v1.6/standard/text-to-video : fal.ai model path}
                            {--no-download : Skip downloading — just confirm the task completes and print the URL}';

    protected $description = 'Smoke-test: submit a video to fal.ai (Kling), poll until done, download the result';

    private const FAL_QUEUE_BASE = 'https://queue.fal.run';
    private const POLL_INTERVAL  = 10; // seconds
    private const MAX_POLLS      = 60; // 10 minutes max

    public function handle(): int
    {
        $key = (string) config('services.fal.key', env('FAL_KEY', ''));

        if ($key === '') {
            $this->error('FAL_KEY not configured.');
            $this->newLine();
            $this->line('Add to .env:');
            $this->line('  FAL_KEY=your_fal_api_key');
            $this->newLine();
            $this->line('Get key from: https://fal.ai/dashboard/keys');
            return self::FAILURE;
        }

        $model    = (string) $this->option('model');
        $prompt   = (string) $this->option('prompt');
        $duration = (string) $this->option('duration');
        $ratio    = (string) $this->option('ratio');

        $this->info('── fal.ai Render Smoke Test ──────────────────────────────');
        $this->line("  Model:    {$model}");
        $this->line("  Prompt:   " . mb_strimwidth($prompt, 0, 80, '…'));
        $this->line("  Duration: {$duration}s   Ratio: {$ratio}");
        $this->newLine();

        // ── Submit ────────────────────────────────────────────────────────────

        $this->line('Submitting…');
        $t0 = microtime(true);

        $submitResponse = Http::withHeaders(['Authorization' => "Key {$key}"])
            ->timeout(30)
            ->post(self::FAL_QUEUE_BASE . "/{$model}", [
                'prompt'          => $prompt,
                'duration'        => $duration,
                'aspect_ratio'    => $ratio,
                'negative_prompt' => '',
            ]);

        if ($submitResponse->failed()) {
            $this->error("Submit failed: HTTP {$submitResponse->status()}");
            $this->line($submitResponse->body());
            return self::FAILURE;
        }

        $submitted = $submitResponse->json();
        $requestId = $submitted['request_id'] ?? null;
        $statusUrl = $submitted['status_url']   ?? null;
        $resultUrl = $submitted['response_url'] ?? null;

        if (! $requestId) {
            $this->error('No request_id in response.');
            $this->line(json_encode($submitted, JSON_PRETTY_PRINT));
            return self::FAILURE;
        }

        $this->info("  ✓ Submitted   request_id={$requestId}");
        $this->newLine();

        // ── Poll ──────────────────────────────────────────────────────────────

        $this->line('Polling for completion…');
        $finalStatus = null;

        for ($i = 1; $i <= self::MAX_POLLS; $i++) {
            sleep(self::POLL_INTERVAL);

            $statusResponse = Http::withHeaders(['Authorization' => "Key {$key}"])
                ->timeout(15)
                ->get($statusUrl ?? (self::FAL_QUEUE_BASE . "/{$model}/requests/{$requestId}/status"));

            if ($statusResponse->failed()) {
                $this->warn("  Poll #{$i} HTTP {$statusResponse->status()} — retrying…");
                continue;
            }

            $statusData  = $statusResponse->json();
            $status      = $statusData['status'] ?? 'UNKNOWN';
            $elapsed     = round(microtime(true) - $t0);
            $this->line("  Poll #{$i}   status={$status}   elapsed={$elapsed}s");

            if ($status === 'COMPLETED') {
                $finalStatus = 'COMPLETED';
                break;
            }

            if ($status === 'FAILED') {
                $finalStatus = 'FAILED';
                $this->newLine();
                $this->error("Task failed on fal.ai side.");
                $this->line(json_encode($statusData, JSON_PRETTY_PRINT));
                return self::FAILURE;
            }
        }

        if ($finalStatus !== 'COMPLETED') {
            $elapsed = round(microtime(true) - $t0);
            $this->error("Timed out after {$elapsed}s — task may still be running on fal.ai.");
            $this->line("  Check: {$statusUrl}");
            return self::FAILURE;
        }

        // ── Fetch result ──────────────────────────────────────────────────────

        $resultResponse = Http::withHeaders(['Authorization' => "Key {$key}"])
            ->timeout(15)
            ->get($resultUrl ?? (self::FAL_QUEUE_BASE . "/{$model}/requests/{$requestId}"));

        if ($resultResponse->failed()) {
            $this->error("Result fetch failed: HTTP {$resultResponse->status()}");
            return self::FAILURE;
        }

        $result   = $resultResponse->json();
        $videoUrl = $result['video']['url'] ?? null;

        if (! $videoUrl) {
            $this->error('No video URL in result.');
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
            return self::FAILURE;
        }

        $totalSeconds = round(microtime(true) - $t0);
        $this->newLine();
        $this->info("  ✓ Render complete  ({$totalSeconds}s total)");
        $this->line("  Video URL: {$videoUrl}");

        if (isset($result['video']['file_size'])) {
            $sizeMb = round($result['video']['file_size'] / 1024 / 1024, 2);
            $this->line("  File size: {$sizeMb} MB");
        }

        // ── Download ──────────────────────────────────────────────────────────

        if ($this->option('no-download')) {
            $this->newLine();
            $this->warn('Download skipped (--no-download). Open the URL above to watch the video.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Downloading…');

        $filename = 'render-test/' . date('Ymd_His') . ".mp4";
        $savePath = storage_path("app/{$filename}");
        @mkdir(dirname($savePath), 0755, true);

        $downloadResponse = Http::timeout(300)->sink($savePath)->get($videoUrl);

        if (! $downloadResponse->successful()) {
            $this->error("Download failed: HTTP {$downloadResponse->status()}");
            return self::FAILURE;
        }

        $sizeMb = round(filesize($savePath) / 1024 / 1024, 2);
        $this->info("  ✓ Saved   ({$sizeMb} MB)");
        $this->line("  Path: {$savePath}");
        $this->newLine();
        $this->info('Done. Open the file to watch the video.');

        return self::SUCCESS;
    }
}
