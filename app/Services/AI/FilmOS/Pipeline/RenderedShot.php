<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Pipeline;

use App\Services\AI\FilmOS\Runtime\RuntimeEvent;

final class RenderedShot
{
    public function __construct(
        public readonly string       $shotId,
        public readonly int          $shotOrder,
        public readonly RuntimeEvent $status,
        public readonly ?string      $assetUrl = null,
        public readonly ?string      $error    = null,
    ) {}

    public function isSuccess(): bool
    {
        return $this->status === RuntimeEvent::DOWNLOAD_COMPLETED;
    }
}
