<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Production;

use App\Services\AI\Provider\Dto\RenderStatusResult;
use App\Services\AI\Provider\RenderVideoStatus;

/**
 * Tracks the lifecycle of one submitted render task during a production run.
 * Mutable: status and result update as the provider responds.
 */
final class RenderTask
{
    public RenderVideoStatus  $status;
    public ?string            $videoUrl    = null;
    public ?string            $errorMessage = null;
    public ?DownloadedClip    $clip         = null;
    public int                $pollAttempts = 0;

    public function __construct(
        public readonly string $filmOsTaskId,   // e.g. 'render_shot_002_cockroach_closeup'
        public readonly string $providerTaskId, // Kling task_id
        public readonly string $shotId,
        public readonly int    $ordinal,
        public readonly string $prompt,
    ) {
        $this->status = RenderVideoStatus::PENDING;
    }

    public function applyStatus(RenderStatusResult $result): void
    {
        $this->status       = $result->status;
        $this->videoUrl     = $result->videoUrl;
        $this->errorMessage = $result->errorMessage;
        $this->pollAttempts++;
    }

    public function isTerminal(): bool   { return $this->status->isTerminal(); }
    public function isSuccess(): bool    { return $this->status->isSuccess(); }
    public function hasClip(): bool      { return $this->clip !== null; }
}
