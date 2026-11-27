<?php

namespace App\Http\Controllers\Video;

use App\Models\VideoProject;
use App\Services\Video\ShotSourceService;
use Illuminate\Http\Request;

final class ReferenceController extends VideoController
{
    public function index(Request $request, string $id)
    {
        $this->ownedProject($id);
        if (($refused = $this->refusedBy(\App\Http\Middleware\RefuseV63Project::class)) !== null) { return $refused; }

        $parts = app(\App\Services\Video\PartsDetailService::class);

        if ($parts->supports($id)) {
            if ($request->isMethod('post')) {
                return redirect()->route('video-projects.reference', $id)->with('error', 'Dùng các thao tác của bảng Reference.');
            }

            if (is_string($request->query('shots_approved')) && (app(ShotSourceService::class)->selection($id)['stage_id'] ?? null) === $request->query('shots_approved') && ! session()->has('success')) {
                session()->now('success', self::SHOT_PLAN_APPROVED);
            }

            $panel = $parts->panel($id);
            $impact = null;

            if ($panel['preview'] !== null && $panel['stage'] === null) {
                try {
                    $impact = app(\App\Services\Video\MotoClipSourceService::class)->impact($id);
                } catch (\RuntimeException) {
                    $impact = null;
                }
            }

            return view('video-projects.reference', $this->chrome() + [
                'id' => $id,
                'project' => VideoProject::query()->findOrFail($id),
                'motoReference' => $panel,
                'motoImpact' => $impact,
                'motoCharacters' => app(\App\Services\Video\MotoCharacterReferenceService::class)->panel($id),
            ]);
        }

        if ($request->isMethod('post')) {
            return $this->createReference($request, $id);
        }

        $data = $this->videoProjectService->referencePageData($id);

        if ($data === null) {
            return redirect()->route('video-projects.index')
                ->with('error', __('messages.project_not_found'));
        }

        return view('video-projects.reference', $this->chrome() + $data);
    }

    public function partsDetail(Request $request, string $id, string $action)
    {
        $this->ownedProject($id);
        if (($refused = $this->refusedBy(\App\Http\Middleware\RefuseV63Project::class)) !== null) { return $refused; }
        $service = app(\App\Services\Video\PartsDetailService::class);
        abort_unless($service->supports($id), 404);
        $cells = ['cells' => 'required|array|min:1|max:100', 'cells.*' => 'required|string|regex:/^R[0-9a-f]{10}$/'];
        $data = $request->validate(match ($action) {
            'plan' => ['hash' => 'required|string|max:80'],
            'layout' => ['rows' => 'required|integer', 'columns' => 'required|integer', 'size' => 'required|string|max:20', 'model' => 'required|string|max:100', 'quality' => 'required|string|max:30'],
            'review' => ['plan_id' => 'required|uuid', 'operation_id' => 'required|uuid', 'retry' => 'nullable|boolean', 'confirm_cost' => 'accepted'] + $cells,
            'render' => ['hash' => 'required|string|max:80', 'sheet_hash' => 'required|string|max:80', 'model' => 'required|string|max:100', 'quality' => 'required|string|max:30', 'operation_id' => 'required|uuid', 'confirm_cost' => 'accepted'] + $cells,
            'accept' => ['plan_id' => 'required|uuid', 'reviews' => 'required|array|min:1|max:10', 'reviews.*' => 'required|uuid'],
            'process' => ['artifact_id' => 'required|uuid'],
            'approve', 'reject', 'revoke' => ['artifact_id' => 'required|uuid'],
            'answer' => ['hash' => 'required|string|max:80', 'reference_id' => 'required|string|regex:/^R[0-9a-f]{10}$/', 'decisions' => 'required|array|min:1|max:20', 'decisions.*.question' => 'required|string|max:2000', 'decisions.*.answer' => 'required|string|max:1000'],
            default => abort(404),
        });
        $requester = auth()->id() === null ? null : ['id' => (string) auth()->id(), 'name' => (string) auth()->user()?->name];

        try {
            $message = match ($action) {
                'plan' => (function () use ($service, $id, $data): string {
                    $service->save($id, $data['hash']);

                    return 'Đã lập kế hoạch Reference từ nguồn đã duyệt — không gọi AI, không render.';
                })(),
                'layout' => (function () use ($service, $id, $data): string {
                    $service->saveGridChoice($id, (int) $data['rows'], (int) $data['columns'], $data['size'], $data['model'], $data['quality']);

                    return 'Đã lưu lưới và cấu hình render — không gọi AI, không render.';
                })(),
                'review' => (function () use ($id, $data, $requester): string {
                    [$stage, $reason] = app(\App\Services\Video\ReferenceWriterService::class)->run($id, $data['plan_id'], array_values($data['cells']), (bool) ($data['retry'] ?? false), $data['operation_id'], $requester);

                    if ($stage === null) {
                        throw new \RuntimeException($reason);
                    }

                    return match ($reason) {
                        'queued' => 'Đã xếp hàng lượt kiểm prompt — máy chạy nền sẽ gọi model; tải lại trang để xem kết quả.',
                        'cached', 'replayed' => 'Các ô này đã có lượt kiểm prompt cho đúng nguồn — không gọi model thêm.',
                        default => 'Kiểm prompt xong: '.($stage->output_json['check']['verdict'] ?? $reason).'.',
                    };
                })(),
                'render' => (function () use ($service, $id, $data, $requester): string {
                    $plan = $service->save($id, $data['hash']);
                    [$image, $reason] = $service->render($id, (string) $plan->id, array_values($data['cells']), $data['sheet_hash'], $data['model'], $data['quality'], $data['operation_id'], (string) auth()->user()?->name, $requester);

                    return match (true) {
                        $image === null => throw new \RuntimeException($reason),
                        $reason === 'queued' => 'Đã xếp hàng render master — máy chạy nền gửi request, cắt ảnh khi xong.',
                        $reason === 'already_exists' => 'Lượt render này đã có ('.$image->status.') — không gọi thêm API.',
                        $reason === 'rendered' => 'Đã render và cắt master. Xem rồi duyệt hoặc từ chối cả sheet.',
                        $reason === 'rendered_stale' => throw new \RuntimeException('Master đã render và lưu lịch sử, nhưng nguồn đổi trong lúc render — lập lại kế hoạch trước khi dùng.'),
                        str_starts_with($reason, 'processing_failed|') => throw new \RuntimeException('Đã render nhưng cắt ảnh lỗi: '.substr($reason, 18)),
                        default => throw new \RuntimeException('Render: '.$reason.' — '.($image->render_error ?? 'xem lịch sử lượt render.')),
                    };
                })(),
                'accept' => (function () use ($service, $id, $data): string {
                    $service->accept($id, $data['plan_id'], $data['reviews'], auth()->id() === null ? null : (string) auth()->id());

                    return 'Đã xác nhận nội dung AI viết lại cho '.count($data['reviews']).' ô — master có thể render khi mọi ô đã đạt.';
                })(),
                'process' => 'Xử lý ảnh: '.$service->process($id, $data['artifact_id']),
                'approve' => (function () use ($service, $id, $data): string {
                    $service->approveSheet($id, $data['artifact_id'], auth()->id() === null ? null : (string) auth()->id());

                    return 'Đã duyệt cả sheet — các ảnh part được dùng chung cho shot.';
                })(),
                'reject' => (function () use ($service, $id, $data): string {
                    $service->rejectSheet($id, $data['artifact_id'], auth()->id() === null ? null : (string) auth()->id());

                    return 'Đã từ chối cả sheet — các part trở lại danh sách cần tạo.';
                })(),
                'revoke' => (function () use ($service, $id, $data): string {
                    $service->revokeSheet($id, $data['artifact_id'], auth()->id() === null ? null : (string) auth()->id());

                    return 'Đã thu hồi duyệt — sheet trở về chờ quyết định, các part vẫn giữ ở sheet này. Duyệt lại để dùng, hoặc từ chối cả sheet để render lại.';
                })(),
                'answer' => (function () use ($service, $id, $data): string {
                    $service->answer($id, $data['reference_id'], array_values($data['decisions']), $data['hash'], auth()->id() === null ? null : (string) auth()->id());

                    return 'Đã lưu quyết định và lập lại kế hoạch — không gọi AI. Ô này cần kiểm prompt lại với quyết định mới.';
                })(),
            };

            if ($action === 'layout' && $this->wantsReferencePart($request)) {
                session()->now('success', $message);

                return response()->view('video-projects.partials.moto-reference-panel', ['id' => $id, 'motoReference' => $service->panel($id), 'fragment' => true])->header('X-Vp-Fragment', '1');
            }

            return redirect()->route('video-projects.reference', $id)->with('success', $message);
        } catch (\RuntimeException $e) {
            return redirect()->route('video-projects.reference', $id)->with('error', $e->getMessage());
        }
    }

    public function character(Request $request, string $id, string $character, string $action)
    {
        $this->ownedProject($id);
        abort_unless(app(\App\Services\Video\PartsDetailService::class)->supports($id), 404);
        $service = app(\App\Services\Video\MotoCharacterReferenceService::class);
        $data = $request->validate(match ($action) {
            'settings' => ['model' => 'required|string|max:100', 'quality' => 'required|string|max:30', 'size' => 'required|string|max:20'],
            'render' => ['sheet_hash' => 'required|string|max:80', 'operation_id' => 'required|uuid', 'confirm_cost' => 'accepted'],
            'process', 'approve', 'reject', 'revoke' => ['artifact_id' => 'required|uuid'],
            default => abort(404),
        });
        $requester = auth()->id() === null ? null : ['id' => (string) auth()->id(), 'name' => (string) auth()->user()?->name];
        $reviewer = auth()->id() === null ? null : (string) auth()->id();
        $page = route('video-projects.reference', $id).'#motoCharacters';

        try {
            $message = match ($action) {
                'settings' => (function () use ($service, $id, $character, $data): string {
                    $service->saveChoice($id, $character, $data['model'], $data['quality'], $data['size']);

                    return 'Đã lưu cấu hình render của '.$character.' — chưa render.';
                })(),
                'render' => (function () use ($service, $id, $character, $data, $requester): string {
                    [$image, $reason] = $service->render($id, $character, $data['sheet_hash'], $data['operation_id'], (string) auth()->user()?->name, $requester);

                    return match (true) {
                        $image === null => throw new \RuntimeException($reason),
                        $reason === 'queued' => 'Đã xếp hàng render ảnh nhân vật — máy chạy nền gửi request.',
                        $reason === 'already_exists' => 'Lượt render này đã có ('.$image->status.') — không gọi thêm API.',
                        $reason === 'rendered' => 'Đã render ảnh nhân vật. Xem rồi duyệt hoặc từ chối.',
                        str_starts_with($reason, 'processing_failed|') => throw new \RuntimeException('Đã render nhưng cắt ảnh lỗi: '.substr($reason, 18)),
                        default => throw new \RuntimeException('Render: '.$reason.' — '.($image->render_error ?? 'xem lịch sử lượt render.')),
                    };
                })(),
                'process' => 'Xử lý ảnh: '.$service->process($id, $data['artifact_id']),
                'approve' => (function () use ($service, $id, $data, $reviewer): string {
                    $service->approve($id, $data['artifact_id'], $reviewer);

                    return 'Đã duyệt ảnh nhân vật — keyframe dùng được ảnh này.';
                })(),
                'reject' => (function () use ($service, $id, $data, $reviewer): string {
                    $service->reject($id, $data['artifact_id'], $reviewer);

                    return 'Đã từ chối — các ô của ảnh này trở lại danh sách cần tạo.';
                })(),
                'revoke' => (function () use ($service, $id, $data, $reviewer): string {
                    $service->revoke($id, $data['artifact_id'], $reviewer);

                    return 'Đã thu hồi duyệt — ảnh và lịch sử vẫn giữ; duyệt lại để dùng, hoặc từ chối để render lại.';
                })(),
            };

            if ($action === 'settings' && $this->wantsReferencePart($request)) {
                session()->now('success', $message);

                return response(view('video-projects.partials.moto-reference-flash')->render().view('video-projects.partials.moto-character-panel', ['id' => $id, 'motoCharacters' => $service->panel($id)])->render())->header('X-Vp-Fragment', '1');
            }

            return redirect()->to($page)->with('success', $message);
        } catch (\RuntimeException $e) {
            return redirect()->to($page)->with('error', $e->getMessage());
        }
    }

    private function createReference(Request $request, string $id)
    {
        $data = $this->form->validate($request, 'ReferenceImageForm');

        [$image, $reason] = $this->videoProjectService->renderReferenceDirect(
            $id,
            (string) auth()->user()?->name,
            $data,
        );

        if ($image === null) {
            return back()->with('error', $this->referenceMessage($reason));
        }

        $level = in_array($reason, ['rendered', 'already_exists'], true) ? 'success' : 'error';

        return redirect()
            ->route('video-projects.reference', $id)
            ->with($level, $this->renderOutcome($reason, $image));
    }
}
