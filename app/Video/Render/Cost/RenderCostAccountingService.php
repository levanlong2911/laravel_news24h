<?php

declare(strict_types=1);

namespace App\Video\Render\Cost;

use App\Models\VideoCostEntry;
use App\Models\VideoRender;
use App\Models\VideoRenderAttempt;
use App\Repositories\Interfaces\VideoCostEntryRepositoryInterface;
use App\Repositories\Interfaces\VideoRenderAttemptRepositoryInterface;
use App\Repositories\Interfaces\VideoRenderRepositoryInterface;
use App\Video\Render\DTO\RenderAttemptUsage;
use RuntimeException;

final class RenderCostAccountingService
{
    public function __construct(
        private readonly VideoCostEntryRepositoryInterface $costs,
        private readonly VideoRenderAttemptRepositoryInterface $attempts,
        private readonly VideoRenderRepositoryInterface $renders,
    ) {}

    public function record(
        VideoRender $render,
        VideoRenderAttempt $attempt,
        RenderAttemptUsage $usage,
    ): ?VideoCostEntry {
        $key = sprintf('render-attempt:%s:provider-usage', $attempt->id);

        $existing = $this->costs->findCostByIdempotencyKey($key);

        if ($existing !== null) {
            if ($attempt->cost_entry_id !== $existing->id) {
                $this->attempts->saveAttempt($attempt, [
                    'cost_entry_id' => $existing->id,
                ]);
            }

            return $existing;
        }

        if ($usage->providerCostUsd === null) {
            return null;
        }

        $stage = $render->render_kind === 'video' ? 'video_render' : 'image_render';
        $owners = $this->renders->costOwnerIds($render);

        $entry = $this->costs->createCostEntry([
            'project_id' => $this->projectIdFor($render, $owners['project_id']),
            'session_id' => $owners['session_id'],
            'entity_type' => 'render_attempt',
            'entity_id' => $attempt->id,
            'stage' => $stage,
            'provider' => $attempt->provider_key,
            'model' => $attempt->model_key,
            'usage_type' => 'provider_reported',
            'quantity' => 1,
            'unit' => 'attempt',
            'cost_usd' => $usage->providerCostUsd,
            'cost_idempotency_key' => $key,
            'metadata_json' => [
                'input_tokens' => $usage->inputTokens,
                'output_tokens' => $usage->outputTokens,
                'image_input_tokens' => $usage->imageInputTokens,
                'text_input_tokens' => $usage->textInputTokens,
                'action' => $stage,
            ],
        ]);

        $this->attempts->saveAttempt($attempt, [
            'cost_entry_id' => $entry->id,
        ]);

        return $entry;
    }

    /**
     * Clip khong co design image — no gan vao shot. Lay nham duong dan thi dong
     * tien ghi project_id null va bien mat khoi tong chi phi cua project.
     *
     * @param  VideoRender  $render
     * @param  mixed  $projectId  the project costOwnerIds() read for the render
     * @return ?string
     */
    private function projectIdFor(VideoRender $render, mixed $projectId): ?string
    {
        if ($projectId === null && $render->render_kind === 'video') {
            throw new RuntimeException('Video render '.$render->id.' khong suy duoc project — khong ghi ledger.');
        }

        return $projectId;
    }
}

