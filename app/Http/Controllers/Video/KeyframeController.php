<?php

namespace App\Http\Controllers\Video;

use Illuminate\Http\Request;

final class KeyframeController extends VideoController
{
    public function index(string $id)
    {
        $this->ownedProject($id);
        abort_unless(app(\App\Services\Video\PartsDetailService::class)->supports($id), 404);

        return view('video-projects.keyframes', $this->chrome() + [
            'id' => $id,
            'motoKeyframes' => app(\App\Services\Video\MotoKeyframeService::class)->panel($id),
        ]);
    }

    public function action(Request $request, string $id, string $shot, string $action)
    {
        $this->ownedProject($id);
        abort_unless(app(\App\Services\Video\PartsDetailService::class)->supports($id), 404);
        $service = app(\App\Services\Video\MotoKeyframeService::class);
        $rules = match ($action) {
            'source' => ['kind' => 'required|in:design,environment', 'from' => 'nullable|uuid', 'to' => 'required|uuid'],
            'source-reset' => ['kind' => 'required|in:design,environment'],
            'source-off', 'source-on' => ['from' => 'required|uuid'],
            'settings' => ['model' => 'required|string|max:100', 'quality' => 'nullable|string|max:30', 'size' => 'required|string|max:20'],
            'render' => ['preview_hash' => 'required|string|max:80', 'operation_id' => 'required|uuid', 'confirm_cost' => 'accepted', 'model' => 'required|string|max:100', 'quality' => 'nullable|string|max:30', 'size' => 'required|string|max:20'],
            'approve' => ['artifact_id' => 'required|uuid', 'delivery_id' => 'nullable|uuid', 'in_use' => 'nullable|string|max:40'],
            'revoke' => ['artifact_id' => 'required|uuid'],
            'frame' => ['artifact_id' => 'required|uuid', 'x' => 'required|integer|min:0', 'y' => 'required|integer|min:0', 'width' => 'required|integer|min:1', 'height' => 'required|integer|min:1'],
            'edit-prompt' => ['artifact_id' => 'required|uuid', 'faults' => 'required|array|min:1', 'faults.*' => 'string|max:20', 'targets' => 'nullable|array', 'targets.*' => 'string|max:80', 'note' => 'nullable|string|max:'.\App\Video\Moto\MotoKeyframePrompt::NOTE_MAX_CHARS, 'references' => 'nullable|array', 'references.*' => 'uuid', 'operation_id' => 'required|uuid', 'confirm_cost' => 'accepted'],
            'edit-render' => ['artifact_id' => 'required|uuid', 'stage_id' => 'required|uuid', 'preview_hash' => 'required|string|max:80', 'operation_id' => 'required|uuid', 'confirm_cost' => 'accepted'],
            'prompt' => ['operation_id' => 'required|uuid', 'confirm_cost' => 'accepted', 'regenerate' => 'nullable|boolean'],
            'prompt-approve' => ['stage_id' => 'required|uuid'],
            default => abort(404),
        };
        $requester = auth()->id() === null ? null : ['id' => (string) auth()->id(), 'name' => (string) auth()->user()?->name];
        $reviewer = auth()->id() === null ? null : (string) auth()->id();
        $page = route('video-projects.keyframes', $id).'#shot-'.$shot;
        $part = fn (string $key, string $text) => response(view('video-projects.partials.moto-reference-flash', ['flashId' => 'keyframesFlash', $key => $text])->render()
            .view('video-projects.partials.moto-keyframes-panel', ['id' => $id, 'motoKeyframes' => $service->panel($id, $shot), 'fragment' => true])->render())->header('X-Vp-Fragment', '1');

        try {
            $data = $request->validate($rules);
        } catch (\Illuminate\Validation\ValidationException $e) {
            if (! $this->wantsReferencePart($request)) {
                throw $e;
            }

            return $part('error', implode(' ', \Illuminate\Support\Arr::flatten($e->errors())));
        }

        try {
            $message = match ($action) {
                'source' => (function () use ($service, $id, $shot, $data): string {
                    $service->saveSource($id, $shot, $data['kind'], $data['from'] ?? null, $data['to']);

                    return 'Đã đổi ảnh cho lượt keyframe này — nguồn gốc không đổi, chưa render.';
                })(),
                'source-reset' => (function () use ($service, $id, $shot, $data): string {
                    $service->resetSource($id, $shot, $data['kind']);

                    return 'Đã bỏ lựa chọn của người — thẻ dùng lại ảnh của kế hoạch shot, chưa render.';
                })(),
                'source-off', 'source-on' => (function () use ($service, $id, $shot, $data, $action): string {
                    $service->leaveOutMaster($id, $shot, $data['from'], $action === 'source-off');

                    return $action === 'source-off' ? 'Đã bỏ Reference master này khỏi shot — các part của nó không có nguồn chi tiết, chưa render.' : 'Shot gửi lại Reference master này — chưa render.';
                })(),
                'settings' => (function () use ($service, $id, $shot, $data): string {
                    $service->saveSettings($id, $shot, $data['model'], (string) ($data['quality'] ?? ''), $data['size']);

                    return 'Đã lưu cấu hình render của shot — chưa render.';
                })(),
                'render' => (function () use ($service, $id, $shot, $data, $requester): string {
                    [$image, $reason] = $service->render($id, $shot, $data['preview_hash'], $data['operation_id'], $requester, ['model' => $data['model'], 'quality' => (string) ($data['quality'] ?? ''), 'size' => $data['size']]);

                    return match (true) {
                        $image === null => throw new \RuntimeException($reason),
                        $reason === 'queued' => 'Đã xếp hàng render keyframe — máy chạy nền gửi request.',
                        $reason === 'already_exists' => 'Lượt render này đã có ('.$image->status.') — không gọi thêm API.',
                        $reason === 'rendered' => 'Đã render keyframe. Xem ảnh rồi duyệt.',
                        default => throw new \RuntimeException('Render: '.$reason.' — '.($image->render_error ?? 'xem lịch sử lượt render.')),
                    };
                })(),
                'approve' => (function () use ($service, $request, $id, $shot, $data, $reviewer): string {
                    $service->approve($id, $shot, $data['artifact_id'], $reviewer, $data['delivery_id'] ?? null, $request->has('in_use') ? (string) ($data['in_use'] ?? '') : null);

                    return isset($data['delivery_id']) ? 'Đã duyệt bộ ảnh gốc và ảnh video này cho '.$shot.'.' : 'Đã duyệt và dùng ảnh này cho '.$shot.'.';
                })(),
                'frame' => (function () use ($service, $id, $shot, $data): string {
                    $delivery = $service->applyFrame($id, $shot, $data['artifact_id'], ['x' => (int) $data['x'], 'y' => (int) $data['y'], 'width' => (int) $data['width'], 'height' => (int) $data['height']]);

                    return 'Đã áp dụng khung: ảnh video '.$delivery->width.'×'.$delivery->height.' — xem rồi duyệt bộ ảnh, bộ đang dùng chưa đổi.';
                })(),
                'edit-prompt' => (function () use ($id, $shot, $data, $requester): string {
                    $targets = array_map(static fn (string $target): array => ['type' => (string) strstr($target, ':', true), 'id' => substr((string) strstr($target, ':'), 1)], array_values((array) ($data['targets'] ?? [])));
                    [$stage, $reason] = app(\App\Services\Video\KeyframePromptWriterService::class)->runEdit($id, $shot, ['artifact_id' => $data['artifact_id'], 'faults' => array_values($data['faults']), 'targets' => $targets, 'note' => $data['note'] ?? null, 'references' => array_values((array) ($data['references'] ?? []))], $data['operation_id'], $requester);

                    return match (true) {
                        $stage === null => throw new \RuntimeException($reason),
                        $reason === 'queued', $reason === 'requeued' => 'Đã xếp hàng viết prompt sửa cho '.$shot.' — máy chạy nền gọi AI.',
                        default => 'Đã viết prompt sửa cho '.$shot.'. Đọc rồi duyệt trước khi render bản sửa; ảnh đang dùng không đổi.',
                    };
                })(),
                'edit-render' => (function () use ($service, $id, $shot, $data, $requester): string {
                    [$image, $reason] = $service->renderEdit($id, $shot, $data['artifact_id'], $data['stage_id'], $data['preview_hash'], $data['operation_id'], $requester);

                    return match (true) {
                        $image === null => throw new \RuntimeException($reason),
                        $reason === 'queued' => 'Đã xếp hàng render bản sửa — máy chạy nền gửi request.',
                        $reason === 'already_exists' => 'Lượt render này đã có ('.$image->status.') — không gọi thêm API.',
                        $reason === 'rendered' => 'Đã render bản sửa thành một ứng viên mới. Xem ảnh, chọn khung rồi duyệt.',
                        default => throw new \RuntimeException('Render: '.$reason.' — '.($image->render_error ?? 'xem lịch sử lượt render.')),
                    };
                })(),
                'revoke' => (function () use ($service, $id, $shot, $data, $reviewer): string {
                    $service->revoke($id, $shot, $data['artifact_id'], $reviewer);

                    return 'Đã thu hồi duyệt — ảnh và lịch sử vẫn giữ, '.$shot.' chưa có keyframe đang dùng.';
                })(),
                'prompt' => (function () use ($id, $shot, $data, $requester): string {
                    [$stage, $reason] = app(\App\Services\Video\KeyframePromptWriterService::class)->run($id, $shot, (bool) ($data['regenerate'] ?? false), $data['operation_id'], $requester);

                    return match (true) {
                        $stage === null => throw new \RuntimeException($reason),
                        $reason === 'queued', $reason === 'requeued' => 'Đã xếp hàng viết prompt ảnh cho '.$shot.' — máy chạy nền gọi AI.',
                        $reason === 'cached' => 'Đã có lượt viết prompt cho đúng nguồn này — không gọi AI thêm. Bấm Viết lại nếu cần bản khác.',
                        default => 'Đã viết prompt ảnh cho '.$shot.'. Đọc rồi duyệt trước khi render.',
                    };
                })(),
                'prompt-approve' => (function () use ($id, $shot, $data, $reviewer): string {
                    app(\App\Services\Video\KeyframePromptWriterService::class)->approve($id, $data['stage_id'], $reviewer, $shot);

                    return 'Đã duyệt prompt ảnh cho '.$shot.' — chưa render.';
                })(),
            };

            return $this->wantsReferencePart($request) ? $part('success', $message) : redirect()->to($page)->with('success', $message);
        } catch (\RuntimeException $e) {
            return $this->wantsReferencePart($request) ? $part('error', $e->getMessage()) : redirect()->to($page)->with('error', $e->getMessage());
        }
    }
}
