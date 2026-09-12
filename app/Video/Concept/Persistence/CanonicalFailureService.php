<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Persistence\Enums\CanonicalConceptStatus;
use App\Video\Concept\Persistence\Enums\CanonicalEventType;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use App\Video\Concept\Persistence\Repository\CanonicalConceptRepository;
use App\Video\Concept\Support\Clock;
use Illuminate\Support\Facades\DB;
use LogicException;

final class CanonicalFailureService
{
    public function __construct(
        private readonly CanonicalConceptRepository $repository,
        private readonly CanonicalEventWriter $events,
        private readonly Clock $clock,
    ) {}

    public function fail(
        string $revisionId,
        string $code,
        string $message,
    ): void {
        DB::transaction(function () use ($revisionId, $code, $message): void {
            $revision = $this->repository->findRevisionForUpdate($revisionId);

            if ($revision->status === CanonicalConceptStatus::FAILED) {
                return;
            }

            if ($revision->status === CanonicalConceptStatus::FROZEN) {
                throw new LogicException(
                    'Cannot fail an already frozen revision.'
                );
            }

            $from = $revision->status;

            $revision->forceFill([
                'status' => CanonicalConceptStatus::FAILED,
                'failure_code' => $code,
                'failure_message' => $message,
                'failed_at' => $this->clock->now(),
                'lock_version' => ((int) $revision->lock_version) + 1,
            ])->save();

            $this->events->append(
                revision: $revision,
                type: CanonicalEventType::FAILED,
                from: $from,
                to: CanonicalConceptStatus::FAILED,
                metadata: ['code' => $code],
                eventKey: 'failed:'.$code,
            );
        });
    }
}
