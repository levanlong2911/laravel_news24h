<?php

namespace App\Http\Controllers;

use App\Models\PromptFramework;
use Illuminate\Http\Request;

class PromptFrameworkController extends Controller
{
    public function index(Request $request)
    {
        $query = PromptFramework::orderBy('created_at', 'desc');

        if ($request->filled('name')) {
            $query->where('name', 'like', '%' . $request->name . '%');
        }

        $list = $query->paginate(20);

        return view('prompt-framework.index', [
            'route'  => 'prompt-framework',
            "action" => "admin-prompt-framework",
            'menu'   => 'menu-open',
            'active' => 'active',
            'list'   => $list,
            'ids'    => PromptFramework::pluck('id'),
        ]);
    }

    public function add(Request $request)
    {
        if ($request->isMethod('post')) {
            $request->validate([
                'name'             => 'required|string|max:60|unique:prompt_frameworks,name',
                'group_description'=> 'required|string|max:255',
                'system_prompt'    => 'required|string',
                'phase1_analyze'   => 'required|string',
                'phase2_diagnose'  => 'required|string',
                'phase3_generate'  => 'required|string',
            ]);

            PromptFramework::create($request->only([
                'name', 'group_description',
                'system_prompt', 'phase1_analyze', 'phase2_diagnose', 'phase3_generate',
                'version', 'is_active',
            ]));

            return redirect()->route('prompt-framework.index')->with('success', __('messages.add_success'));
        }

        return view('prompt-framework.add', [
            'route'  => 'prompt-framework',
            "action" => "admin-prompt-framework",
            'menu'   => 'menu-open',
            'active' => 'active',
        ]);
    }

    public function detail($id)
    {
        $framework = PromptFramework::with('contentTypes')->findOrFail($id);

        return view('prompt-framework.detail', [
            'route'     => 'prompt-framework',
            "action" => "admin-prompt-framework",
            'menu'      => 'menu-open',
            'active'    => 'active',
            'framework' => $framework,
        ]);
    }

    public function update(Request $request, $id)
    {
        $framework = PromptFramework::findOrFail($id);

        if ($request->isMethod('post')) {
            $request->validate([
                'name'             => 'required|string|max:60|unique:prompt_frameworks,name,' . $id,
                'group_description'=> 'required|string|max:255',
                'system_prompt'    => 'required|string',
                'phase1_analyze'   => 'required|string',
                'phase2_diagnose'  => 'required|string',
                'phase3_generate'  => 'required|string',
            ]);

            $framework->update($request->only([
                'name', 'group_description',
                'system_prompt', 'phase1_analyze', 'phase2_diagnose', 'phase3_generate',
                'version', 'is_active',
            ]));

            return redirect()->route('prompt-framework.index')->with('success', __('messages.add_success'));
        }

        return view('prompt-framework.update', [
            'route'     => 'prompt-framework',
            "action" => "admin-prompt-framework",
            'menu'      => 'menu-open',
            'active'    => 'active',
            'framework' => $framework,
        ]);
    }

    public function delete(Request $request)
    {
        PromptFramework::whereIn('id', $request->ids ?? [])->delete();
        return redirect()->route('prompt-framework.index')->with('success', __('messages.delete_success'));
    }
}
