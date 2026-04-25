<?php

namespace App\Repositories\Eloquent;

use App\Enums\Paginate;
use App\Models\NewsWeb;
use App\Repositories\Interfaces\NewsWebRepositoryInterface;

class NewsWebRepository extends BaseRepository implements NewsWebRepositoryInterface
{
    public function getModel(): string
    {
        return NewsWeb::class;
    }

    public function getListNewsWebIds($ids)
    {
        return NewsWeb::query()
                ->whereIn("id", $ids)
                ->get();
    }

    public function getWebByCategoryId($categoryId)
    {
        return NewsWeb::where('category_id', $categoryId)->get();
    }

    public function getListNewsWeb($request = null)
    {
        $query = NewsWeb::query()->with('category')->orderBy('created_at', 'desc');

        if ($request && $request->filled('domain')) {
            $query->where('domain', 'like', '%' . $request->domain . '%');
        }

        if ($request && $request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        return $query->paginate(Paginate::PAGE->value);
    }
    public function chekDomain($domain, $category_id)
    {
        return NewsWeb::query()
                ->where('domain', $domain)
                ->where('category_id', $category_id)
                ->exists();
    }
}
