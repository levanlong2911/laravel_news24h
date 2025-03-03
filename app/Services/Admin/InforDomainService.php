<?php

namespace App\Services\Admin;

use App\Enums\Paginate;
use App\Repositories\Interfaces\InforDomainRepositoryInterface;
use Exception;
use Illuminate\Support\Facades\DB;

class InforDomainService
{
    private InforDomainRepositoryInterface $domainRepository;

    public function __construct(
        InforDomainRepositoryInterface $domainRepository,
    ) {
        $this->domainRepository = $domainRepository;
    }


    /**
     * List Role
     *
     * @return mixed
     */
    public function getListDomain()
    {
        return $this->domainRepository->paginate(Paginate::PAGE->value);
    }

    public function checkDomain($domain)
    {
        return $this->domainRepository->getByDomain($domain);
    }

    public function getInforDomain($id)
    {
        return $this->domainRepository->find($id);
    }

    public function getListDomainIds()
    {
        return $this->domainRepository->all();
    }

    public function deleteDomainByIds($request)
    {
        DB::beginTransaction();
        try {
            $questionData = $this->domainRepository->getDataListIds($request->ids);
            foreach ($questionData as $question) {
                $question->delete();
            }
            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollback();
            return false;
        }
    }

}
