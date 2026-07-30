<?php

namespace App\Http\Controllers;

use App\Form\PromptFrameworkForm;
use App\Services\Admin\PromptFrameworkService;
use Illuminate\Http\Request;
use RuntimeException;

class PromptFrameworkController extends Controller
{
    public function __construct(
        private PromptFrameworkService $promptFrameworkService,
        private PromptFrameworkForm $form,
    ) {}

    public function index(Request $request)
    {
        $list = $this->promptFrameworkService->getAll($request->input('name'));

        return view('prompt-framework.index', $this->viewData([
            'list' => $list,
            'ids'  => $list->pluck('id'),
        ]));
    }

    public function add(Request $request)
    {
        if (!$request->isMethod('post')) {
            return view('prompt-framework.add', $this->viewData());
        }

        // Hỏng thì ném ValidationException, Laravel tự đưa về form kèm lỗi
        $validated = $this->form->validate($request);

        if (!$this->promptFrameworkService->create($validated)) {
            return redirect()->back()->withInput()->with('error', __('messages.add_error'));
        }

        return redirect()
            ->route('prompt-framework.index')
            ->with('success', __('messages.add_success'));
    }

    public function detail($id)
    {
        $framework = $this->promptFrameworkService->getById($id);

        if (!$framework) {
            return redirect()->back()->with('error', 'Không tìm thấy framework.');
        }

        return view('prompt-framework.detail', $this->viewData([
            'framework' => $framework,
        ]));
    }

    public function update(Request $request, $id)
    {
        $framework = $this->promptFrameworkService->getById($id);

        if (!$framework) {
            return redirect()->back()->with('error', 'Không tìm thấy framework.');
        }

        if (!$request->isMethod('post')) {
            return view('prompt-framework.update', $this->viewData([
                'framework' => $framework,
            ]));
        }

        // Rule unique bỏ qua chính bản ghi đang sửa — nếu không, giữ nguyên tên cũ
        // cũng bị báo trùng với chính nó.
        $request->merge(['id' => $id]);

        $validated = $this->form->validate($request);

        if (!$this->promptFrameworkService->update($id, $validated)) {
            return redirect()->back()->withInput()->with('error', __('messages.update_error'));
        }

        return redirect()
            ->route('prompt-framework.index')
            ->with('success', __('messages.update_success'));
    }

    public function delete(Request $request)
    {
        $request->validate([
            'ids'   => 'required|array',
            'ids.*' => 'uuid',
        ]);

        $ids = array_filter((array) $request->input('ids'));

        if (empty($ids)) {
            return redirect()->back()->with('info', 'Không có framework nào được chọn.');
        }

        try {
            $result = $this->promptFrameworkService->deleteByIds($ids);
        } catch (RuntimeException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $parts = [];

        if ($result['deactivated'] > 0) {
            $parts[] = "{$result['deactivated']} framework vô hiệu hoá (còn lịch sử sửa)";
        }

        if ($result['deleted'] > 0) {
            $parts[] = "{$result['deleted']} framework đã xoá";
        }

        return redirect()
            ->route('prompt-framework.index')
            ->with('success', $parts ? implode(', ', $parts) : __('messages.delete_success'));
    }

    /**
     * Bốn view của màn này dùng chung một bộ khoá điều hướng; gom lại để thêm
     * view mới không phải chép lại khối đó lần thứ năm.
     */
    private function viewData(array $extra = []): array
    {
        return array_merge([
            'route'  => 'prompt-framework',
            'action' => 'admin-prompt-framework',
            'menu'   => 'menu-open',
            'active' => 'active',
        ], $extra);
    }
}
