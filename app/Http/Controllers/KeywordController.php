<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Keyword;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class KeywordController extends Controller
{
    public function index()
    {
        $keywords   = Keyword::with('category')->orderBy('sort_order')->orderBy('name')->get();
        $categories = Category::orderBy('name')->get();

        return view('admin.keywords.index', [
            'route'      => 'keyword',
            'action'     => 'keyword-index',
            'menu'       => 'menu-open',
            'active'     => 'active',
            'keywords'   => $keywords,
            'categories' => $categories,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'           => 'required|string|max:255|unique:keywords,name',
            'short_name'     => 'nullable|string|max:100',
            'search_keyword' => 'nullable|string|max:255',
            'category_id'    => 'nullable|exists:categories,id',
            'sort_order'     => 'nullable|integer',
        ]);

        Keyword::create([
            'id'             => Str::uuid(),
            'name'           => trim($request->name),
            'short_name'     => trim($request->short_name) ?: Str::slug($request->name),
            'search_keyword' => trim($request->search_keyword) ?: null,
            'extra_queries'  => $this->parseExtraQueries($request->extra_queries),
            'category_id'    => $request->category_id ?: null,
            'sort_order'     => $request->sort_order ?? 99,
            'is_base'        => true,
            'is_active'      => true,
        ]);

        return back()->with('success', "Đã thêm keyword: {$request->name}");
    }

    public function update(Request $request, Keyword $keyword)
    {
        $request->validate([
            'name'           => 'required|string|max:255|unique:keywords,name,' . $keyword->id,
            'short_name'     => 'nullable|string|max:100',
            'search_keyword' => 'nullable|string|max:255',
            'category_id'    => 'nullable|exists:categories,id',
            'sort_order'     => 'nullable|integer',
        ]);

        $keyword->update([
            'name'           => trim($request->name),
            'short_name'     => trim($request->short_name) ?: Str::slug($request->name),
            'search_keyword' => trim($request->search_keyword) ?: null,
            'extra_queries'  => $this->parseExtraQueries($request->extra_queries),
            'category_id'    => $request->category_id ?: null,
            'sort_order'     => $request->sort_order ?? $keyword->sort_order,
        ]);

        return back()->with('success', "Đã cập nhật: {$keyword->name}");
    }

    private function parseExtraQueries(?string $raw): ?array
    {
        if (empty($raw)) return null;
        $lines = array_filter(array_map('trim', explode("\n", $raw)));
        return !empty($lines) ? array_values($lines) : null;
    }

    public function destroy(Keyword $keyword)
    {
        $name = $keyword->name;
        $keyword->delete();
        return back()->with('success', "Đã xóa: {$name}");
    }

    public function show(Keyword $keyword)
    {
        return response()->json($keyword);
    }

    public function toggleActive(Keyword $keyword)
    {
        $keyword->update(['is_active' => !$keyword->is_active]);
        $status = $keyword->is_active ? 'bật' : 'tắt';
        return back()->with('success', "{$keyword->name} đã {$status}.");
    }
}
