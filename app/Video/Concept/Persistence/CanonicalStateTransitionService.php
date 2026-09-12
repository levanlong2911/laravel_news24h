<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Enums\CanonicalEventType;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use App\Video\Concept\Persistence\Repository\CanonicalConceptRepository;
use App\Video\Concept\Persistence\StateMachine\CanonicalConceptStateMachine;
use Illuminate\Support\Facades\DB;

final class CanonicalStateTransitionService
{
    public function __construct(
        private readonly CanonicalConceptRepository $repository,
        private readonly CanonicalConceptStateMachine $stateMachine,
        private readonly CanonicalEventWriter $events,
    ) {}

    /**
     * @param  array<string,mixed>|null  $metadata
     */
    public function transition(
        string $revisionId,
        CanonicalConceptStatus $to,
        CanonicalEventType $eventType,
        ?array $metadata = null,
        ?string $eventKey = null,
    ): CanonicalConceptRevision {
        return DB::transaction(function () use ($revisionId, $to, $eventType, $metadata, $eventKey): CanonicalConceptRevision {
            $revision = $this->repository->findRevisionForUpdate($revisionId);
            $from = $revision->status;

            if ($from !== $to) {
                $this->stateMachine->assertCanTransition($from, $to);
            }

            $revision->forceFill([
                'status' => $to,
                'lock_version' => ((int) $revision->lock_version) + 1,
            ])->save();

            $this->events->append(
                revision: $revision,
                type: $eventType,
                from: $from,
                to: $to,
                metadata: $metadata,
                eventKey: $eventKey,
            );

            return $revision->refresh();
        }, attempts: 3);
    }
}
