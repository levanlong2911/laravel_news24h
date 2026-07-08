<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Kernel\Plugins;

use App\Services\AI\FilmOS\Intent\DirectorIntent;
use App\Services\AI\FilmOS\Kernel\FilmTask;
use App\Services\AI\FilmOS\Kernel\TaskResult;
use App\Services\AI\FilmOS\Kernel\TaskType;
use App\Services\AI\Provider\Dto\RenderVideoRequest;
use App\Services\AI\Provider\RenderVideoProvider;
use App\Services\AI\Provider\RenderVideoStatus;

/**
 * Handles RENDER tasks by submitting to the video provider and polling until done.
 * Phase 1: submits to Kling v1.6 via the injected RenderVideoProvider.
 */
final class RenderPlugin implements KernelPlugin
{
    private const POLL_INTERVAL_SECONDS = 5;
    private const MAX_POLLS             = 60;  // 5 min timeout

    public function __construct(
        private readonly RenderVideoProvider $provider,
    ) {}

    public function taskTypes(): array
    {
        return [TaskType::RENDER];
    }

    public function execute(FilmTask $task): TaskResult
    {
        $startMs = (int) (microtime(true) * 1000);

        /** @var DirectorIntent $intent */
        $intent = $task->payload;

        $prompt  = self::buildPromptFromIntent($intent);
        $request = new RenderVideoRequest(
            prompt:          $prompt,
            negativePrompt:  'text overlay, logo, watermark, blurry, low quality, distorted',
            durationSeconds: 5,
            aspectRatio:     '16:9',
        );

        try {
            $submit   = $this->provider->submit($request);
            $taskId   = $submit->taskId;

            // Poll until terminal state or timeout
            $status = null;
            for ($i = 0; $i < self::MAX_POLLS; $i++) {
                $status = $this->provider->status($taskId);
                if ($status->isTerminal()) {
                    break;
                }
                sleep($this->provider->nextPollDelay($i, $status));
            }

            $durationMs = (int) (microtime(true) * 1000) - $startMs;

            if (!$status || !$status->isSuccess()) {
                return new TaskResult(
                    taskId:     $task->id,
                    success:    false,
                    output:     null,
                    durationMs: $durationMs,
                    error:      $status?->errorMessage ?? 'Render timed out or failed',
                );
            }

            return new TaskResult(
                taskId:     $task->id,
                success:    true,
                output:     [
                    'shotId'    => $intent->shotId,
                    'videoUrl'  => $status->videoUrl,
                    'taskId'    => $taskId,
                    'prompt'    => $prompt,
                ],
                durationMs: $durationMs,
            );
        } catch (\Throwable $e) {
            return new TaskResult(
                taskId:     $task->id,
                success:    false,
                output:     null,
                durationMs: (int) (microtime(true) * 1000) - $startMs,
                error:      $e->getMessage(),
            );
        }
    }

    /**
     * Builds a Kling-compatible prompt from DirectorIntent.
     * Phase 1: minimal but correct — injects key visual parameters.
     */
    public static function buildPromptFromIntent(DirectorIntent $intent): string
    {
        $exec     = $intent->execution;
        $strategy = $exec->visualStrategy->value;
        $lens     = $exec->styleRule['lens']      ?? 50;
        $movement = $exec->styleRule['movement']  ?? 'STATIC';
        $dof      = $exec->styleRule['dof']       ?? 'MEDIUM';

        $mustShow = implode(', ', $exec->mustShow);
        $domain   = ucwords(str_replace('_', ' ', $intent->meaning->function->value));

        $movementPhrase = match ($movement) {
            'SLOW_PUSH'      => 'slow subtle push',
            'HANDHELD_SUBTLE'=> 'subtle handheld',
            default          => 'static',
        };

        $dofPhrase = match ($dof) {
            'SHALLOW' => 'shallow depth of field',
            'MEDIUM'  => 'standard depth of field',
            default   => 'deep focus',
        };

        return "Hyperrealistic. Natural anatomy, realistic proportions. "
            . "Scene: {$mustShow}. "
            . "Cinematic {$strategy} style. "
            . "{$lens}mm. {$movementPhrase}, {$dofPhrase}. "
            . "Broadcast news coverage. No text overlays, no logos.";
    }
}
