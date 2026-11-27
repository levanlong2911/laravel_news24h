<?php

namespace App\Repositories\Interfaces;

use App\Models\AiPhase;
use Illuminate\Support\Collection;

interface AiPhaseRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  ?string  $phaseId  null still runs the lookup (id is null)
     * @return ?AiPhase the phase revision
     */
    public function findPhase(?string $phaseId): ?AiPhase;

    /**
     * @param  ?string  $phaseId
     * @return AiPhase the phase revision
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findPhaseOrFail(?string $phaseId): AiPhase;

    /**
     * @param  array<int, string>  $phaseIds
     * @return Collection<int, AiPhase> the phase revisions with these ids
     */
    public function findPhasesByIds(array $phaseIds): Collection;

    /**
     * @param  string  $categoryId
     * @param  string  $workflow
     * @param  string  $phaseCode
     * @return list<AiPhase> every revision of the phase of the workflow for the category, whatever its status
     */
    public function findRevisionsOfPhase(string $categoryId, string $workflow, string $phaseCode): array;

    /**
     * @param  string  $categoryId
     * @param  string  $workflow
     * @param  string  $phaseCode
     * @return Collection<int, AiPhase> the revisions of the phase of the workflow for the category marked current
     */
    public function findCurrentRevisions(string $categoryId, string $workflow, string $phaseCode): Collection;

    /**
     * @param  AiPhase  $phase
     * @return Collection<int, \App\Models\AiPhaseSchema> the schema links of the revision with their schema eager loaded, in no given order
     */
    public function findSchemaLinksWithSchemas(AiPhase $phase): Collection;
}
