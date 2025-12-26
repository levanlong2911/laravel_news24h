<?php

namespace App\Http\Controllers;

use App\Form\AdminCustomValidator;
use App\Repositories\Interfaces\AdminRepositoryInterface;
use App\Services\Admin\AdminService;
use App\Services\Admin\WebsiteService;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    private AdminService $adminService;
    private AdminCustomValidator $form;
    private AdminRepositoryInterface $adminRepository;
    private WebsiteService $websiteService;

    public function __construct
    (
        AdminService $adminService,
        AdminCustomValidator $form,
        AdminRepositoryInterface $adminRepository,
        WebsiteService $websiteService
    )
    {
        $this->adminService = $adminService;
        $this->form = $form;
        $this->adminRepository = $adminRepository;
        $this->websiteService = $websiteService;
    }

    public function index()
    {
        $dataAdmin = $this->adminService->getListAcc();
        return view("admin.index", [
            "route" => "admin",
            "action" => "admin-index",
            "menu" => "menu-open",
            "active" => "active",
            'dataAdmin' => $dataAdmin
        ]);
    }

    public function add(Request $request)
    {
        $listRole = $this->adminService->getListRole();
        $listWebsite = $this->websiteService->getListWebsite();
        if($request->isMethod('post')) {
            $this->form->validate($request, 'AdminAddForm');
            $addAcc = $this->adminService->addAdmin($request);
            if ($addAcc) {
                return redirect()->route('admin.index')->with('success', __('messages.add_success'));
            }
            return redirect()->route('admin.index')->with('error', __('messages.add_error'));
        }
        return view("admin.add", [
            "route" => "admin",
            "action" => "admin-add",
            "menu" => "menu-open",
            "active" => "active",
            "listRole" => $listRole,
            "listWebsite" => $listWebsite
        ]);
    }

    public function update(Request $request, $id)
    {
        $dataAcc = $this->adminService->getByIdAcc($id);
        if(!$dataAcc) {
            return redirect()->back()->with('error', __('messages.account_not_found'));
        }
        $listWebsite = $this->websiteService->getListWebsite();
        $listRole = $this->adminService->getListRole();
        if ($request->isMethod('post')) {
            $this->form->validate($request, 'AdminUpdateForm');
            $updateAcc = $this->adminService->update($request);
            if ($updateAcc) {
                return redirect()->route('admin.index')->with('success', __('messages.update_success'));
            }
            return redirect()->route('admin.index')->with('error', __('messages.update_error'));
        }
        return view("admin.update", [
            "route" => "admin",
            "action" => "admin-update",
            "menu" => "menu-open",
            "active" => "active",
            "dataAcc" => $dataAcc,
            "listRole" => $listRole,
            "listWebsite" => $listWebsite
        ]);
    }

    public function delete(Request $request)
    {
        $del = $this->adminRepository->delete($request->id);
        if ($del) {
            return redirect()
                ->route('admin.index')
                ->with("success", __("messages.delete_success"));
        }

        return redirect()
            ->route('admin.index')
            ->with("error", __("messages.delete_error"));
    }

    public function detail(Request $request)
    {
        $infoAcc = $this->adminService->getByIdAcc($request->id);
        if(!$infoAcc) {
            return redirect()->back()->with('error', __('messages.account_not_found'));
        }
        $roleAcc = $this->adminService->getRoleAcc($infoAcc->role_id);
        // dd($roleAcc);
        return view("admin.detail", [
            "route" => "admin",
            "action" => "admin-detail",
            "menu" => "menu-open",
            "active" => "active",
            'infoAcc' => $infoAcc,
            'roleAcc' => $roleAcc,
        ]);
    }
}
