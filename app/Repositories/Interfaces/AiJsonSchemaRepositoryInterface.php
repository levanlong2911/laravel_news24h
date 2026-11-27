<?php

namespace App\Repositories\Interfaces;

use Illuminate\Support\Collection;

interface AiJsonSchemaRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  array<int, string>  $schemaIds
     * @return Collection<int, \App\Models\AiJsonSchema> the schemas with these ids
     */
    public function findSchemasByIds(array $schemaIds): Collection;
}
