<?php

declare(strict_types=1);

namespace App\Video\Render\Qa;

use App\Models\RenderQaRun;
use App\Models\VideoCostEntry;

final class QaCostAccountingService
{
    public function record(RenderQaRun $qaRun, array $payload): ?VideoCostEntry
    {
        $cost = $payload['cost_usd'] ?? null;

        if ($cost === null) {
            return null;
        }

        $costKey = 'vision-qa:'.$qaRun->id;

        return VideoCostEntry::query()->firstOrCreate(
            [
                'cost_idempotency_key' => $costKey,
            ],
            [
                'project_id' => $qaRun->render?->designImage?->project_id,
                'session_id' => $qaRun->render?->video_session_id,
                'entity_type' => 'render_qa_run',
                'entity_id' => $qaRun->id,
                'stage' => 'vision_qa',
                'provider' => $qaRun->vision_provider,
                'model' => $qaRun->vision_model,
                'usage_type' => 'provider_reported',
                'quantity' => 1,
                'unit' => 'qa_run',
                'cost_usd' => $cost,
                'metadata_json' => [
                    'action' => 'vision_qa',
                    'input_tokens' => $payload['input_tokens'] ?? null,
                    'image_tokens' => $payload['image_tokens'] ?? null,
                    'output_tokens' => $payload['output_tokens'] ?? null,
                ],
            ],
        );
    }
}
