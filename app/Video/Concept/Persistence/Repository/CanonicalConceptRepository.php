<?php

declare(strict_types=1);

namespace App\Video\Concept\Persistence\Repository;

use App\Video\Concept\ConceptInput;
use App\Video\Concept\Persistence\Models\CanonicalConceptRevision;

interface CanonicalConceptRepository
{
    public function createRevision(
        string $projectId,
        ?string $sessionId,
        ConceptInput $input,
        string $canonicalSchemaVersion,
        string $conceptModel,
        string $conceptPromptVersion,
        string $revisionReason = 'initial',
        ?string $parentRevisionId = null,
    ): CanonicalConceptRevision;

    public function findRevisionForUpdate(
        string $revisionId
    ): CanonicalConceptRevision;

    public function lockRevision(
        string $revisionId
    ): CanonicalConceptRevision;

    public function findRevision(
        string $revisionId
    ): ?CanonicalConceptRevision;

    public function latestRevisionNumber(
        string $projectId
    ): int;
}
