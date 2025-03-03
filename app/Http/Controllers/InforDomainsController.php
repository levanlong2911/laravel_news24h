<?php

namespace App\Http\Controllers;

use App\Form\AdminCustomValidator;
use App\Repositories\Interfaces\InforDomainRepositoryInterface;
use App\Services\Admin\InforDomainService;
use Illuminate\Http\Request;

class InforDomainsController extends Controller
{
    private InforDomainRepositoryInterface $domainRepository;
    private InforDomainService $domainService;
    private AdminCustomValidator $form;

    public function __construct
    (
        InforDomainRepositoryInterface $domainRepository,
        InforDomainService $domainService,
        AdminCustomValidator $form
    )
    {
        $this->domainRepository = $domainRepository;
        $this->domainService = $domainService;
        $this->form = $form;
    }

    public function index()
    {
        $listsDomain = $this->domainService->getListDomain();
        // $result = $this->domainService->getListDomainIds()->pluck('id');
        // dd($result);
        return view("domain.index", [
            "route" => "domain",
            "action" => "admin-domain",
            "menu" => "menu-open",
            "active" => "active",
            'listsDomain' => $listsDomain,
            "domainIds" => $this->domainService->getListDomainIds()->pluck('id'),
        ]);
    }

    public function add(Request $request)
    {
        if ($request->isMethod('post')) {
            $this->form->validate($request, 'DomainAddForm');
            $nameDomain = parse_url($request->domain, PHP_URL_HOST);
            // Loại bỏ tiền tố "www." nếu có
            if (strpos($nameDomain, 'www.') === 0) {
                $nameDomain = substr($nameDomain, 4); // Cắt bỏ "www."
            }
            $result = $this->domainService->checkDomain($nameDomain);
            // Kiểm tra domain đã tồn tại chưa
            if ($result ) {
                return redirect()->route('domain.index')->with('error', __('messages.domain_already_exists'));
            }
            $params = [
                'domain' => $nameDomain,
                'key_class' => $request->key_class,
            ];
            $addDomain = $this->domainRepository->create($params);
            if ($addDomain) {
                return redirect()->route('domain.index')->with('success', __('messages.add_success'));
            }
            return redirect()->route('domain.index')->with('error', __('messages.add_error'));
        }
        return view("domain.add", [
            "route" => "domain",
            "action" => "domain-index",
            "menu" => "menu-open",
            "active" => "active",
        ]);
    }

    public function detail(Request $request)
    {
        $infoDomain = $this->domainService->getInforDomain($request->id);
        if(!$infoDomain) {
            return redirect()->back()->with('error', __('messages.account_not_found'));
        }
        return view("domain.detail", [
            "route" => "domain",
            "action" => "domain-detail",
            "menu" => "menu-open",
            "active" => "active",
            'infoDomain' => $infoDomain,
        ]);
    }

    public function update(Request $request)
    {
        $infoDomain = $this->domainService->getInforDomain($request->id);
        if ($request->isMethod('post')) {
            $params = [
                'domain' => $request->domain,
                'key_class' => $request->key_class,
            ];
            $updateCate = $this->domainRepository->update($request->id, $params);
            if ($updateCate) {
                return redirect()->route('domain.index')->with('success', __('messages.add_success'));
            }
            return redirect()->route('domain.index')->with('error', __('messages.add_error'));
        }
        return view("domain.update", [
            "route" => "domain",
            "action" => "domain-update",
            "menu" => "menu-open",
            "active" => "active",
            "infoDomain" => $infoDomain,
        ]);
    }

    public function delete(Request $request)
    {
        $result = $this->domainService->deleteDomainByIds($request);
        if ($result) {
            return redirect()->route('domain.index')->with('success', __('messages.delete_success'));
        }
        return redirect()->back()->with('error', __('messages.delete_error'));
    }
}
