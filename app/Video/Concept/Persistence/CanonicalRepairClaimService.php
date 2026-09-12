<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use App\Video\Concept\Persistence\Repository\CanonicalConceptRepository;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class CanonicalRepairClaimService
{
    public function __construct(
        private readonly CanonicalConceptRepository $repository,
    ) {}

    public function claimRepair(string $revisionId): CanonicalConceptRevision
    {
        return DB::transaction(function () use ($revisionId): CanonicalConceptRevision {
            $revision = $this->repository->findRevisionForUpdate($revisionId);

            if ((int) $revision->repair_count >= 1) {
                throw new RuntimeException(
                    'Canonical semantic repair limit already exhausted.'
                );
            }

            $revision->forceFill([
                'repair_count' => 1,
                'lock_version' => ((int) $revision->lock_version) + 1,
            ])->save();

            return $revision->refresh();
        });
    }
}
