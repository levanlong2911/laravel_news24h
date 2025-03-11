<?php

namespace App\Repositories\Interfaces;

interface AdminRepositoryInterface extends RepositoryInterface
{
    public function getDataAdminById($adminId);
    public function delete($id): bool;
    public function getListAdmin();
}
