<?php

namespace App\Repositories\Eloquent;

use App\Models\AiPhase;
use App\Repositories\Interfaces\AiPhaseRepositoryInterface;
use Illuminate\Support\Collection;

class AiPhaseRepository extends BaseRepository implements AiPhaseRepositoryInterface
{
    public function getModel(): string
    {
        return AiPhase::class;
    }

    public function findPhase(?string $phaseId): ?AiPhase
    {
        return $this->model->newQuery()->find($phaseId);
    }

    public function findPhaseOrFail(?string $phaseId): AiPhase
    {
        return $this->model->newQuery()->findOrFail($phaseId);
    }

    public function findPhasesByIds(array $phaseIds): Collection
    {
        return $this->model->newQuery()->whereIn('id', $phaseIds)->get();
    }

    public function findRevisionsOfPhase(string $categoryId, string $workflow, string $phaseCode): array
    {
        return $this->model->newQuery()
            ->where('category_id', $categoryId)
            ->where('workflow_code', $workflow)
            ->where('phase_code', $phaseCode)
            ->get()
            ->all();
    }

    public function findCurrentRevisions(string $categoryId, string $workflow, string $phaseCode): Collection
    {
        return $this->model->newQuery()
            ->where('category_id', $categoryId)
            ->where('workflow_code', $workflow)
            ->where('phase_code', $phaseCode)
            ->where('is_current', true)
            ->get();
    }

    public function findSchemaLinksWithSchemas(AiPhase $phase): Collection
    {
        return $phase->schemaLinks()->with('schema')->get();
    }
}
