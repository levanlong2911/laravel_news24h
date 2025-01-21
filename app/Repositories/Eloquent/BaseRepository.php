<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Interfaces\RepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

abstract class BaseRepository implements RepositoryInterface
{
    protected Model $model;
    protected Builder $query;

    public function __construct()
    {
        $this->setModel();
        $this->query = $this->model->newQuery();
    }

    abstract public function getModel(): string;

    public function setModel(): void
    {
        $this->model = app($this->getModel());
    }

    public function all(): iterable
    {
        return $this->model->all();
    }

    public function create(array $attributes): Model
    {
        return $this->model->create($attributes);
    }

    public function update(string|int $id, array $attributes): bool
    {
        $record = $this->find($id);
        return $record ? $record->update($attributes) : false;
    }

    public function delete(string|int $id): bool
    {
        $record = $this->find($id);
        return $record ? $record->delete() : false;
    }

    public function find(string|int $id): ?Model
    {
        return $this->model->find($id);
    }

    public function first(): ?Model
    {
        return $this->model->first();
    }

    public function show(string|int $id): Model
    {
        return $this->model->findOrFail($id);
    }

    public function with($relations): Builder
    {
        return $this->model->with($relations);
    }

    // Cập nhật để trả về đúng kiểu Eloquent\Builder
    public function getQuery(): Builder
    {
        return $this->query;
    }

    public function clearQuery(): Builder
    {
        $this->query = $this->model->newQuery();
        return $this->query;
    }

    public function findBy(array $filter, bool $toArray = true): ?iterable
    {
        $builder = $this->model->newQuery();
        foreach ($filter as $key => $value) {
            $builder->where($key, $value);
        }

        $results = $builder->get();
        return $toArray ? $results->toArray() : $results;
    }

    public function findOneBy(array $filter, bool $toArray = true): ?array
    {
        $builder = $this->model->newQuery();
        foreach ($filter as $key => $value) {
            $builder->where($key, $value);
        }

        $result = $builder->first();
        return $toArray && $result ? $result->toArray() : $result;
    }

    public function paginate(int $page): LengthAwarePaginator
    {
        return $this->query->paginate($page);
    }
}
