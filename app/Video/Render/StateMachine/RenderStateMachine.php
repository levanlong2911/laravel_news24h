<?php

declare(strict_types=1);

namespace App\Video\Render\StateMachine;

use App\Video\Render\Enums\RenderStatus;

final class RenderStateMachine
{
    /**
     * @var array<string,list<RenderStatus>>
     */
    private const TRANSITIONS = [
        'queued' => [
            RenderStatus::CLAIMED,
            RenderStatus::CANCELLED,
            RenderStatus::FAILED,
        ],
        'claimed' => [
            RenderStatus::PREPARING,
            RenderStatus::QUEUED,
            RenderStatus::FAILED,
            RenderStatus::CANCELLED,
        ],
        'preparing' => [
            RenderStatus::SUBMITTING,
            RenderStatus::RETRY_WAIT,
            RenderStatus::FAILED,
        ],
        'submitting' => [
            RenderStatus::SUBMITTED,
            RenderStatus::PROVIDER_UNKNOWN,
            RenderStatus::RETRY_WAIT,
            RenderStatus::FAILED,
        ],
        'submitted' => [
            RenderStatus::ARTIFACT_WRITING,
            RenderStatus::PROVIDER_UNKNOWN,
            RenderStatus::RETRY_WAIT,
            RenderStatus::FAILED,
        ],
        'provider_unknown' => [
            RenderStatus::SUBMITTED,
            RenderStatus::ARTIFACT_WRITING,
            RenderStatus::RETRY_WAIT,
            RenderStatus::FAILED,
        ],
        'artifact_writing' => [
            RenderStatus::CHECKPOINTING,
            RenderStatus::FAILED,
        ],
        'checkpointing' => [
            RenderStatus::SUCCEEDED,
            RenderStatus::FAILED,
        ],
        'retry_wait' => [
            RenderStatus::CLAIMED,
            RenderStatus::FAILED,
            RenderStatus::CANCELLED,
        ],
        'succeeded' => [],
        'failed' => [],
        'cancelled' => [],
    ];

    public function assert(RenderStatus $from, RenderStatus $to): void
    {
        if (! in_array($to, self::TRANSITIONS[$from->value] ?? [], true)) {
            throw InvalidRenderStateTransition::between($from, $to);
        }
    }
}

