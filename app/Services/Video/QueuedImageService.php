<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Models\VideoDesignImage;

interface QueuedImageService
{
    /** @return ?string why the queued render of the cell may no longer be sent, null while its source is still the one in use */
    public function checkQueued(VideoDesignImage $image): ?string;

    /** @return array{0: ?VideoDesignImage, 1: string, 2: ?array<string, mixed>} the render sent, with what the provider answered when it refused the call */
    public function sendQueued(VideoDesignImage $image): array;
}
