<?php

namespace App\Http\Controllers;

use App\Models\NewsSource;
use Illuminate\Http\Request;

class NewsSourceController extends Controller
{
    public function index(Request $request)
    {
        $type       = $request->get('type', 'trusted');
        $sources    = NewsSource::where('type', $type)->orderBy('category')->orderBy('name')->get();
        $categories = $sources->pluck('category')->unique()->sort()->values(); // từ data đã load, không query thêm

        return view("admin.news-sources.index", [
            "route" => "news-sources",
            "action" => "news-sources-index",
            "menu" => "menu-open",
            "active" => "active",
            'sources' => $sources,
            'type' => $type,
            'categories' => $categories,
        ]);
    }

    public function show(NewsSource $newsSource)
    {
        return response()->json($newsSource);
    }

    public function store(Request $request)
    {
        $request->validate([
            'domain'   => 'required|string|max:255|unique:news_sources,domain',
            'name'     => 'required|string|max:255',
            'type'     => 'required|in:trusted,blocked',
            'category' => 'required|string|max:100',
        ]);

        NewsSource::create([
            'domain'    => strtolower(trim($request->domain)),
            'name'      => trim($request->name),
            'type'      => $request->type,
            'category'  => trim($request->category),
            'is_active' => true,
        ]);

        NewsSource::clearCache();

        return back()->with('success', "Added {$request->domain} to {$request->type} list.");
    }

    public function update(Request $request, NewsSource $newsSource)
    {
        $request->validate([
            'name'      => 'required|string|max:255',
            'type'      => 'required|in:trusted,blocked',
            'category'  => 'required|string|max:100',
            'is_active' => 'boolean',
        ]);

        $newsSource->update([
            'name'      => trim($request->name),
            'type'      => $request->type,
            'category'  => trim($request->category),
            'is_active' => $request->boolean('is_active'),
        ]);

        NewsSource::clearCache();

        return back()->with('success', "Updated {$newsSource->domain}.");
    }

    public function destroy(NewsSource $newsSource)
    {
        $domain = $newsSource->domain;
        $newsSource->delete();
        NewsSource::clearCache();

        return back()->with('success', "Deleted {$domain}.");
    }

    public function toggleActive(NewsSource $newsSource)
    {
        $newsSource->update(['is_active' => !$newsSource->is_active]);
        NewsSource::clearCache();

        $status = $newsSource->is_active ? 'enabled' : 'disabled';
        return back()->with('success', "{$newsSource->domain} {$status}.");
    }
}
