<?php

namespace App\Services\Admin;

use App\Repositories\Interfaces\NewsWebRepositoryInterface;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NewsWebService
{
    private NewsWebRepositoryInterface $newsWebRepository;

    public function __construct(
        NewsWebRepositoryInterface $newsWebRepository,
    ) {
        $this->newsWebRepository = $newsWebRepository;
    }

    public function getListNewsWeb($request = null)
    {
        return $this->newsWebRepository->getListNewsWeb($request);
    }

    public function addNewsWeb($request)
    {
        DB::beginTransaction();
        try {
            foreach (array_map('trim', explode(',', $request->url)) as $url) {
                if (empty($url)) {
                    continue;
                }

                if (!Str::startsWith($url, ['http://', 'https://'])) {
                    $url = 'https://' . $url;
                }

                $parsed = parse_url($url);
                if (!isset($parsed['host'])) {
                    continue;
                }

                $domain  = preg_replace('/^www\./', '', $parsed['host']);
                $baseUrl = ltrim($parsed['path'] ?? '', '/') ?: null;

                if ($this->newsWebRepository->chekDomain($domain, $request->category_id)) {
                    throw new \RuntimeException("Domain '{$domain}' đã tồn tại trong category này.");
                }

                $this->newsWebRepository->create([
                    'category_id' => $request->category_id,
                    'domain'      => $domain,
                    'base_url'    => $baseUrl,
                ]);
            }

            DB::commit();
            return true;
        } catch (\RuntimeException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Throwable $e) {
            DB::rollBack();
            logger()->error($e->getMessage());
            return false;
        }
    }

    public function getByIdWeb($id)
    {
        return $this->newsWebRepository->find($id);
    }

    public function updateWeb($id, $request)
    {
        DB::beginTransaction();
        try {
            $this->newsWebRepository->update($id, [
                'category_id' => $request->category_id,
                'domain'      => $request->domain,
                'base_url'    => ltrim($request->url ?? '', '/') ?: null,
            ]);

            DB::commit();
            return true;
        } catch (Exception $e) {
            DB::rollback();
            logger()->error($e->getMessage());
            return false;
        }
    }

    public function getListNewsWebIds()
    {
        return $this->newsWebRepository->all();
    }

    public function deleteWebByIds($request)
    {
        DB::beginTransaction();
        try {
            $questionData = $this->newsWebRepository->getListNewsWebIds($request->ids);
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

    public function getListTagByCategoryId($categoryId)
    {
        return $this->newsWebRepository->getWebByCategoryId($categoryId);
    }

}
