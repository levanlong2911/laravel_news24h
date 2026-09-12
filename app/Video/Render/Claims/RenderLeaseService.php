<?php

declare(strict_types=1);

namespace App\Video\Render\Claims;

use App\Models\VideoRender;
use App\Video\Concept\Support\Clock;
use DateInterval;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RenderLeaseService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly int $leaseSeconds = 120,
    ) {
    }

    public function heartbeat(string $renderId, string $claimToken, string $workerId): void
    {
        DB::transaction(function () use ($renderId, $claimToken, $workerId): void {
            $render = VideoRender::query()->whereKey($renderId)->lockForUpdate()->firstOrFail();
            $this->assertOwner($render, $claimToken, $workerId);

            if ($render->isTerminal()) {
                throw new RuntimeException('Cannot heartbeat terminal render.');
            }

            $now = $this->clock->now();

            $render->forceFill([
                'heartbeat_at' => $now,
                'lease_expires_at' => $now->add(new DateInterval('PT'.$this->leaseSeconds.'S')),
                'execution_version' => $render->execution_version + 1,
            ])->save();
        });
    }

    public function assertValid(string $renderId, string $claimToken, string $workerId): VideoRender
    {
        $render = VideoRender::query()->findOrFail($renderId);
        $this->assertOwner($render, $claimToken, $workerId);
        $now = $this->clock->now();

        if ($render->lease_expires_at === null || $render->lease_expires_at->lessThanOrEqualTo($now)) {
            throw new RuntimeException('Render claim lease expired.');
        }

        return $render;
    }

    private function assertOwner(VideoRender $render, string $claimToken, string $workerId): void
    {
        if (! hash_equals((string) $render->claim_token, $claimToken)) {
            throw new RuntimeException('Render claim token mismatch.');
        }

        if ($render->claimed_by !== $workerId) {
            throw new RuntimeException('Render worker ownership mismatch.');
        }
    }
}

