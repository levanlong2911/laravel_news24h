<?php

namespace App\Repositories\Eloquent;

use App\Models\Admin;
use App\Repositories\Interfaces\AdminRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use App\Enums\Paginate;

class AdminRepository extends BaseRepository implements AdminRepositoryInterface
{

    public function getModel(): string
    {
        return Admin::class;
    }

    public function getDataAdminById($adminId): ?Model
    {
        return $this->query->where('id', $adminId)->first();
    }

    public function paginate(int $perPage): LengthAwarePaginator
    {
        return $this->model->paginate($perPage);
    }

    public function delete($id): bool
    {
        $admin = Admin::findOrFail($id);
        return $admin->delete();
    }

    public function getListAdmin()
    {
        return Admin::query()
                ->orderBy('created_at', 'desc')
                ->paginate(Paginate::PAGE->value);
    }

}
