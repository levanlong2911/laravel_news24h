<?php

namespace App\Repositories\Eloquent;

use LogicException;

trait AppendOnlyRepository
{
    /**
     * @return never the rows are written only through the insert methods; a query builder would pass the model's append-only guard
     */
    public function getQuery(): never
    {
        throw new LogicException(static::class.' is append-only: no query builder is handed out.');
    }

    /**
     * @return never the rows are written only through the insert methods; a query builder would pass the model's append-only guard
     */
    public function clearQuery(): never
    {
        throw new LogicException(static::class.' is append-only: no query builder is handed out.');
    }

    /**
     * @param  mixed  $relations
     * @return never the rows are written only through the insert methods; a query builder would pass the model's append-only guard
     */
    public function with($relations): never
    {
        throw new LogicException(static::class.' is append-only: no query builder is handed out.');
    }

    /**
     * @param  int  $page
     * @return never the rows are written only through the insert methods; the paginator reads through the builder this repository does not hand out
     */
    public function paginate(int $page): never
    {
        throw new LogicException(static::class.' is append-only: no query builder is handed out.');
    }
}
