<?php

declare(strict_types=1);

namespace App\Video\Render\Cost;

use App\Models\VideoCostEntry;
use App\Models\VideoRender;
use App\Models\VideoRenderAttempt;
use App\Video\Render\DTO\RenderAttemptUsage;
use RuntimeException;

final class RenderCostAccountingService
{
    public function record(
        VideoRender $render,
        VideoRenderAttempt $attempt,
        RenderAttemptUsage $usage,
    ): ?VideoCostEntry {
        $key = sprintf('render-attempt:%s:provider-usage', $attempt->id);

        $existing = VideoCostEntry::query()
            ->where('cost_idempotency_key', $key)
            ->first();

        if ($existing !== null) {
            if ($attempt->cost_entry_id !== $existing->id) {
                $attempt->forceFill([
                    'cost_entry_id' => $existing->id,
                ])->save();
            }

            return $existing;
        }

        if ($usage->providerCostUsd === null) {
            return null;
        }

        $stage = $render->render_kind === 'video' ? 'video_render' : 'image_render';

        $entry = VideoCostEntry::query()->create([
            'project_id' => $this->projectIdFor($render),
            'session_id' => $render->video_session_id ?? $render->shot?->session_id,
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

        $attempt->forceFill([
            'cost_entry_id' => $entry->id,
        ])->save();

        return $entry;
    }

    /**
     * Clip khong co design image — no gan vao shot. Lay nham duong dan thi dong
     * tien ghi project_id null va bien mat khoi tong chi phi cua project.
     */
    private function projectIdFor(VideoRender $render): ?string
    {
        $projectId = $render->designImage?->project_id
            ?? $render->shot?->session?->project_id
            ?? $render->session?->project_id;

        if ($projectId === null && $render->render_kind === 'video') {
            throw new RuntimeException('Video render '.$render->id.' khong suy duoc project — khong ghi ledger.');
        }

        return $projectId;
    }
}

