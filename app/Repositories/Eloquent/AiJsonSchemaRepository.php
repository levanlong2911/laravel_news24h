<?php

namespace App\Repositories\Eloquent;

use App\Models\AiJsonSchema;
use App\Repositories\Interfaces\AiJsonSchemaRepositoryInterface;
use Illuminate\Support\Collection;

class AiJsonSchemaRepository extends BaseRepository implements AiJsonSchemaRepositoryInterface
{
    public function getModel(): string
    {
        return AiJsonSchema::class;
    }

    public function findSchemasByIds(array $schemaIds): Collection
    {
        return $this->model->newQuery()->whereIn('id', $schemaIds)->get();
    }
}
