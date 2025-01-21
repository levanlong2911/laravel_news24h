<?php

namespace App\Repositories\Eloquent;

use App\Models\Role;
use App\Repositories\Interfaces\RoleRepositoryInterface;

class RoleRepository extends BaseRepository implements RoleRepositoryInterface
{
    public function getModel(): string
    {
        return Role::class;
    }
}
