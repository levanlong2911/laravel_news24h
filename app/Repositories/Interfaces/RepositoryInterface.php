<?php

namespace App\Repositories\Interfaces;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

interface RepositoryInterface
{
    public function all(): iterable;

    public function find(string|int $id): ?Model;

    public function first(): ?Model;

    public function create(array $attributes): Model;

    public function update(string|int $id, array $attributes): bool;

    public function delete(string|int $id): bool;

    public function show(string|int $id): Model;

    public function getQuery(): Builder;

    public function clearQuery(): Builder;

    public function findBy(array $filter, bool $toArray = true): ?iterable;

    public function findOneBy(array $filter, bool $toArray = true): ?array;

    public function paginate(int $page): LengthAwarePaginator;
}
