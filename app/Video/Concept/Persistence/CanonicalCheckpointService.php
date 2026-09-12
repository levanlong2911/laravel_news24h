<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence;

use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;
use App\Video\Concept\Persistence\Repository\CanonicalConceptRepository;
use Illuminate\Support\Facades\DB;

final class CanonicalCheckpointService
{
    public function __construct(
        private readonly CanonicalConceptRepository $repository,
    ) {}

    public function rawOutput(
        string $revisionId,
        string $rawJson,
    ): CanonicalConceptRevision {
        return DB::transaction(function () use ($revisionId, $rawJson): CanonicalConceptRevision {
            $revision = $this->repository->findRevisionForUpdate($revisionId);
            $revision->assertMutable();

            $revision->forceFill([
                'latest_raw_json' => $rawJson,
                'lock_version' => ((int) $revision->lock_version) + 1,
            ])->save();

            return $revision->refresh();
        });
    }

    /**
     * @param  list<array<string,mixed>>  $errors
     */
    public function validationFailure(
        string $revisionId,
        array $errors,
    ): CanonicalConceptRevision {
        return DB::transaction(function () use ($revisionId, $errors): CanonicalConceptRevision {
            $revision = $this->repository->findRevisionForUpdate($revisionId);
            $revision->assertMutable();

            $revision->forceFill([
                'latest_validation_errors' => $errors,
                'lock_version' => ((int) $revision->lock_version) + 1,
            ])->save();

            return $revision->refresh();
        });
    }

    public function clearValidationErrors(string $revisionId): void
    {
        DB::transaction(function () use ($revisionId): void {
            $revision = $this->repository->findRevisionForUpdate($revisionId);
            $revision->assertMutable();

            $revision->forceFill([
                'latest_validation_errors' => null,
                'lock_version' => ((int) $revision->lock_version) + 1,
            ])->save();
        });
    }
}
