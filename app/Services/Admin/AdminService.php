<?php

namespace App\Services\Admin;

use App\Enums\Paginate;
use App\Repositories\Interfaces\AdminRepositoryInterface;
use App\Repositories\Interfaces\RoleRepositoryInterface;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        return $this->adminRepository->getListAdmin();
    }

    public function getListRole()
    {
        return $this->roleRepository->all();
    }

    public function addAdmin($request)
    {
        DB::beginTransaction();
        try {
            $passwordHash = Hash::make($request->password);
            $params = [
                'role_id' => $request->role,
                'name' => $request->name,
                'email' => $request->email,
                'domain_id' => $request->website,
                'password' => $passwordHash,
                'email_verified_at' => Carbon::now(),
                'remember_token' => Str::random(10),
            ];
            $admin = $this->adminRepository->create($params);
            DB::commit();
            return $admin;
        } catch (Exception $e) {
            DB::rollback();
            return false;
        }
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
        DB::beginTransaction();
        try {
            // Hash the password only if it's provided
            $passwordHash = $request->password ? Hash::make($request->password) : null;

            // Prepare parameters for creation
            $params = [
                'role_id' => $request->role,
                'name' => $request->name,
                'email' => $request->email,
                'domain_id' => $request->domain,
            ];

            // Only include password if it's not null
            if ($passwordHash) {
                $params['password'] = $passwordHash;
            }

            // Create a new admin using the repository
            $admin = $this->adminRepository->update($request->id, $params);
            DB::commit();
            return $admin;
        } catch (Exception $e) {
            DB::rollback();
            return false;
        }
    }

    public function getRoleAcc($id)
    {
        return $this->roleRepository->find($id);
    }
}
