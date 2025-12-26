<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class DomainScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if (!$user) return;

        // Admin thấy tất cả
        if ($user->isAdmin()) {
            return;
        }

        // Member chỉ thấy domain của mình
        if ($user->domain_id) {
            $builder->where(
                $model->getTable() . '.domain_id',
                $user->domain_id
            );
        }
    }
}
