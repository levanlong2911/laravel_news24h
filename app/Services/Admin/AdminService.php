<?php

namespace App\Services\Admin;

use App\Enums\Paginate;
use App\Repositories\Interfaces\AdminRepositoryInterface;
use App\Repositories\Interfaces\RoleRepositoryInterface;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminService
{

    private AdminRepositoryInterface $adminRepository;
    private RoleRepositoryInterface $roleRepository;

    public function __construct
    (
        AdminRepositoryInterface $adminRepository,
        RoleRepositoryInterface $roleRepository
    )
    {
        $this->adminRepository = $adminRepository;
        $this->roleRepository = $roleRepository;
    }

    public function getListAcc()
    {
        return $this->adminRepository->paginate(Paginate::PAGE->value);
    }

    public function getListRole()
    {
        return $this->roleRepository->all();
    }

    public function create($request): Model
    {
        // Hash the password
        $passwordHash = Hash::make($request->password);

        // Prepare parameters for creation
        $params = [
            'role_id' => $request->role,
            'name' => $request->name,
            'email' => $request->email,
            'password' => $passwordHash,
            'email_verified_at' => Carbon::now(),
            'remember_token' => Str::random(10),
        ];

        // Create a new admin using the repository
        return $this->adminRepository->create($params);
    }

    /**
     * get Info account
     *
     * @param $id
     *
     * @return mixed
     */
    public function getByIdAcc($id) {
        return $this->adminRepository->find($id);
    }

    public function update($request)
    {
        // Hash the password only if it's provided
        $passwordHash = $request->password ? Hash::make($request->password) : null;

        // Prepare parameters for creation
        $params = [
            'role_id' => $request->role,
            'name' => $request->name,
            'email' => $request->email,
        ];

        // Only include password if it's not null
        if ($passwordHash) {
            $params['password'] = $passwordHash;
        }

        // Create a new admin using the repository
        return $this->adminRepository->update($request->id, $params);
    }

    public function getRoleAcc($id)
    {
        return $this->roleRepository->find($id);
    }
}
